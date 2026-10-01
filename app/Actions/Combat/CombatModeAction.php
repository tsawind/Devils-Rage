<?php

declare(strict_types=1);

namespace App\Actions\Combat;

use App\Actions\MapSolarsystem\DeleteMapSolarsystemAction;
use App\Actions\MapSolarsystem\StoreMapSolarsystemAction;
use App\Actions\MapSolarsystem\UpdateMapSolarsystemAction;
use App\Events\Maps\CombatModeTurnedOffEvent;
use App\Models\Map;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\MapUserSetting;
use App\Models\Signature;
use App\Models\User;
use App\Support\Broadcasting\MapBroadcaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Combat mode (The Devil's Rage): each person turns it on for themselves.
 *
 * - start: the system you are in becomes a combat home. Its chain gets the
 *   first free color and numbers from 1 (static 0); systems found from it
 *   join the chain (see StoreTrackingAction).
 * - join: work an existing chain (by color).
 * - solo: combat speed without a chain.
 * - stop: combat mode off for you.
 *
 * A combat home pulses while anyone is working its chain; when the last
 * person turns combat mode off the pulsing stops but the colors stay, until
 * someone clears the chain.
 */
final readonly class CombatModeAction
{
    /** Chain colors in the order they are handed out. Kept in sync with resources/js/lib/combat.ts. */
    public const array COLORS = ['red', 'blue', 'green', 'yellow', 'purple', 'orange'];

    public function __construct(
        private UpdateMapSolarsystemAction $updateMapSolarsystemAction,
        private StoreMapSolarsystemAction $storeMapSolarsystemAction,
        private DeleteMapSolarsystemAction $deleteMapSolarsystemAction,
        private MapBroadcaster $mapBroadcaster,
    ) {}

    /**
     * Start a chain in a system that may not be on the map yet (patch 12): the
     * system you are in is added to the map first, then becomes the combat home.
     *
     * @throws Throwable
     */
    public function startFromSolarsystem(User $user, Map $map, int $solarsystemId): string
    {
        return DB::transaction(function () use ($user, $map, $solarsystemId): string {
            if ($map->home_solarsystem_id === $solarsystemId) {
                throw ValidationException::withMessages([
                    'combat' => 'The home system cannot be a combat home. Start the chain from the next system out.',
                ]);
            }

            $home = MapSolarsystem::query()
                ->where('map_id', $map->id)
                ->where('solarsystem_id', $solarsystemId)
                ->first();

            if (! $home instanceof MapSolarsystem) {
                $rightmost = (int) MapSolarsystem::query()->where('map_id', $map->id)->max('position_x');
                $home = $this->storeMapSolarsystemAction->handle($map, [
                    'solarsystem_id' => $solarsystemId,
                    'position_x' => min($rightmost + 200, (int) config('map.max_size.x')),
                    'position_y' => 40,
                ]);
            }

            return $this->start($user, $map, $home);
        });
    }

    /**
     * @throws Throwable
     */
    public function start(User $user, Map $map, MapSolarsystem $home): string
    {
        return DB::transaction(function () use ($user, $map, $home): string {
            if ($map->home_solarsystem_id === $home->solarsystem_id) {
                throw ValidationException::withMessages([
                    'combat' => 'The home system cannot be a combat home. Start the chain from the next system out.',
                ]);
            }

            $color = $home->combat_home && filled($home->combat_color)
                ? (string) $home->combat_color
                : $this->makeCombatHome($map, $home);

            $this->setUserCombat($user, $map, true, $color);
            $this->refreshActiveChains($map);

            return $color;
        });
    }

    /**
     * @throws Throwable
     */
    public function join(User $user, Map $map, string $color): void
    {
        DB::transaction(function () use ($user, $map, $color): void {
            $exists = MapSolarsystem::query()
                ->where('map_id', $map->id)
                ->where('combat_color', $color)
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    'combat' => sprintf('There is no %s chain on this map any more.', $color),
                ]);
            }

            $this->setUserCombat($user, $map, true, $color);
            $this->refreshActiveChains($map);
        });
    }

    /**
     * @throws Throwable
     */
    public function solo(User $user, Map $map): void
    {
        DB::transaction(function () use ($user, $map): void {
            $this->setUserCombat($user, $map, true, null);
            $this->refreshActiveChains($map);
        });
    }

    /**
     * @throws Throwable
     */
    public function stop(User $user, Map $map): void
    {
        DB::transaction(function () use ($user, $map): void {
            $this->setUserCombat($user, $map, false, null);
            $this->refreshActiveChains($map);
        });
    }

    /**
     * Clear a combat chain from the map (patch 12), by any system in it.
     *
     * @return array{removed: int, kept: int, turned_off: int}
     *
     * @throws Throwable
     */
    public function clear(MapSolarsystem $system, ?User $by = null): array
    {
        if (blank($system->combat_color)) {
            return ['removed' => 0, 'kept' => 0, 'turned_off' => 0];
        }

        return $this->clearChain(Map::query()->findOrFail($system->map_id), (string) $system->combat_color, $by);
    }

    /**
     * Clear a combat chain from the map (patch 12):
     *
     * - its systems and their connections are removed, like Clear map does;
     * - a system still attached to something outside the chain stays: it moves
     *   to where it is attached (a main-chain system → no color; another
     *   chain's system → that chain's color), keeps its name and remembers the
     *   chain it came from ("was Red");
     * - the combat home stays (back in the main chain, no color) when it is
     *   still linked to anything outside the chain, else it is removed too;
     * - pinned systems and the map's home are never removed;
     * - signatures elsewhere that led into a removed system go back to
     *   unlinked, and their number is freed;
     * - Combat turns off for everyone working the chain, and for everyone on
     *   the map once no combat chain is left. Each of them gets a notice.
     *
     * @return array{removed: int, kept: int, turned_off: int}
     *
     * @throws Throwable
     */
    public function clearChain(Map $map, string $color, ?User $by = null): array
    {
        $result = DB::transaction(function () use ($map, $color): array {
            /** @var \Illuminate\Database\Eloquent\Collection<int, MapSolarsystem> $chain */
            $chain = MapSolarsystem::query()
                ->where('map_id', $map->id)
                ->where('combat_color', $color)
                ->lockForUpdate()
                ->get();

            if ($chain->isEmpty()) {
                return ['removed' => 0, 'kept' => 0, 'turned_off_users' => []];
            }

            $chain_ids = $chain->pluck('id')->all();
            $connections = MapConnection::query()
                ->where('map_id', $map->id)
                ->where(fn ($query) => $query
                    ->whereIn('from_map_solarsystem_id', $chain_ids)
                    ->orWhereIn('to_map_solarsystem_id', $chain_ids))
                ->get(['id', 'from_map_solarsystem_id', 'to_map_solarsystem_id']);

            // The systems outside the chain each chain system is still attached to.
            $outside = [];
            foreach ($connections as $connection) {
                $from = (int) $connection->from_map_solarsystem_id;
                $to = (int) $connection->to_map_solarsystem_id;
                $from_in = in_array($from, $chain_ids, true);
                $to_in = in_array($to, $chain_ids, true);
                if ($from_in && ! $to_in) {
                    $outside[$from][] = $to;
                }
                if ($to_in && ! $from_in) {
                    $outside[$to][] = $from;
                }
            }

            $outside_colors = MapSolarsystem::query()
                ->whereKey(array_unique(array_merge([], ...array_values($outside))))
                ->pluck('combat_color', 'id')
                ->all();

            $removed = [];
            $kept = [];
            foreach ($chain as $system) {
                $attached = $outside[$system->id] ?? [];
                $is_map_home = $map->home_solarsystem_id === $system->solarsystem_id;

                if ($attached === [] && ! $system->pinned && ! $is_map_home) {
                    $removed[] = $system;

                    continue;
                }

                // Where it is still attached: the main chain wins over another combat chain.
                $colors = array_map(fn (int $id): ?string => $outside_colors[$id] ?? null, $attached);
                $new_color = in_array(null, $colors, true) || $colors === [] ? null : $colors[0];

                $this->updateMapSolarsystemAction->handle($system, [
                    'combat_color' => $new_color,
                    'combat_home' => false,
                    'combat_active' => false,
                    'combat_started_at' => null,
                    'combat_previous_color' => $system->combat_home ? null : $color,
                ]);
                $kept[] = $system->id;
            }

            // Signatures outside the chain that led into a removed system go back to unlinked.
            $removed_ids = array_map(fn (MapSolarsystem $system): int => $system->id, $removed);
            $dropped_connection_ids = $connections
                ->filter(fn (MapConnection $connection): bool => in_array((int) $connection->from_map_solarsystem_id, $removed_ids, true)
                    || in_array((int) $connection->to_map_solarsystem_id, $removed_ids, true))
                ->pluck('id')
                ->all();
            if ($dropped_connection_ids !== []) {
                $unlinked = Signature::query()
                    ->whereIn('map_connection_id', $dropped_connection_ids)
                    ->whereNotIn('map_solarsystem_id', $removed_ids)
                    ->get();
                foreach ($unlinked as $signature) {
                    $signature->update(['map_connection_id' => null, 'alias' => null]);
                }
                foreach ($unlinked->pluck('map_solarsystem_id')->unique() as $map_solarsystem_id) {
                    $owner = MapSolarsystem::query()->find($map_solarsystem_id);
                    if ($owner instanceof MapSolarsystem) {
                        $this->mapBroadcaster->signaturesChanged($owner);
                    }
                }
            }

            foreach ($removed as $system) {
                $this->deleteMapSolarsystemAction->handle($system);
            }

            // Combat off for everyone on this chain; for everyone once no chain is left.
            $chains_left = MapSolarsystem::query()->where('map_id', $map->id)->whereNotNull('combat_color')->exists();
            $settings = MapUserSetting::query()
                ->where('map_id', $map->id)
                ->where('combat_mode', true)
                ->when($chains_left, fn ($query) => $query->where('combat_color', $color))
                ->get();
            foreach ($settings as $setting) {
                $setting->update(['combat_mode' => false, 'combat_color' => null]);
            }

            $this->refreshActiveChains($map);

            return [
                'removed' => count($removed),
                'kept' => count($kept),
                'turned_off_users' => $settings->pluck('user_id')->all(),
                'chains_left' => $chains_left,
            ];
        });

        $label = ucfirst($color);
        $who = $by instanceof User ? $by->name : 'Someone';
        foreach ($result['turned_off_users'] as $user_id) {
            if ($by instanceof User && $by->id === (int) $user_id) {
                continue;
            }
            $message = ($result['chains_left'] ?? true)
                ? sprintf('Combat off: %s cleared the %s chain', $who, $label)
                : 'Combat off: no combat chains left';
            broadcast(new CombatModeTurnedOffEvent((int) $user_id, $map->id, $message, sprintf('%s cleared the %s chain.', $who, $label)));
        }

        return ['removed' => $result['removed'], 'kept' => $result['kept'], 'turned_off' => count($result['turned_off_users'])];
    }

    /**
     * Give the system the first color no chain on the map is using.
     */
    private function makeCombatHome(Map $map, MapSolarsystem $home): string
    {
        $used = MapSolarsystem::query()
            ->where('map_id', $map->id)
            ->whereNotNull('combat_color')
            ->whereKeyNot($home->id)
            ->distinct()
            ->pluck('combat_color')
            ->all();

        $color = collect(self::COLORS)->first(fn (string $candidate): bool => ! in_array($candidate, $used, true));

        if ($color === null) {
            throw ValidationException::withMessages([
                'combat' => 'All 6 chain colors are in use. Right-click the map and clear an old chain first.',
            ]);
        }

        $this->updateMapSolarsystemAction->handle($home, [
            'combat_color' => $color,
            'combat_home' => true,
            'combat_started_at' => now(),
            'combat_previous_color' => null,
        ]);

        return $color;
    }

    private function setUserCombat(User $user, Map $map, bool $on, ?string $color): void
    {
        $settings = $user->mapUserSettings()->firstOrCreate(['map_id' => $map->id]);
        $settings->update(['combat_mode' => $on, 'combat_color' => $on ? $color : null]);
    }

    /**
     * A combat home pulses while at least one person is working its chain.
     */
    private function refreshActiveChains(Map $map): void
    {
        $active = MapUserSetting::query()
            ->where('map_id', $map->id)
            ->where('combat_mode', true)
            ->whereNotNull('combat_color')
            ->distinct()
            ->pluck('combat_color')
            ->all();

        $homes = MapSolarsystem::query()
            ->where('map_id', $map->id)
            ->where('combat_home', true)
            ->get();

        // Every home is re-sent, so everyone sees who is working each chain.
        foreach ($homes as $home) {
            $shouldPulse = in_array($home->combat_color, $active, true);
            $this->updateMapSolarsystemAction->handle($home, ['combat_active' => $shouldPulse]);
        }
    }
}
