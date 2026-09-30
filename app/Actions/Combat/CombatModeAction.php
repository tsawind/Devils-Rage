<?php

declare(strict_types=1);

namespace App\Actions\Combat;

use App\Actions\MapSolarsystem\UpdateMapSolarsystemAction;
use App\Models\Map;
use App\Models\MapSolarsystem;
use App\Models\MapUserSetting;
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
        private MapBroadcaster $mapBroadcaster,
    ) {}

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
     * Remove a chain's color from every system in it (the systems and their
     * numbers stay on the map), and move anyone working it to plain combat speed.
     *
     * @throws Throwable
     */
    public function clear(MapSolarsystem $system): void
    {
        DB::transaction(function () use ($system): void {
            $color = $system->combat_color;
            if (blank($color)) {
                return;
            }

            $ids = MapSolarsystem::query()
                ->where('map_id', $system->map_id)
                ->where('combat_color', $color)
                ->pluck('id')
                ->all();

            MapSolarsystem::query()
                ->whereKey($ids)
                ->update(['combat_color' => null, 'combat_home' => false, 'combat_active' => false]);

            MapUserSetting::query()
                ->where('map_id', $system->map_id)
                ->where('combat_color', $color)
                ->update(['combat_color' => null]);

            $this->mapBroadcaster->systemsUpserted($system->map_id, MapSolarsystem::query()
                ->whereKey($ids)
                ->with('details')
                ->withCount('signatures', 'wormholeSignatures', 'mapConnections', 'uncategorizedSignatures')
                ->get());
        });
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
                'combat' => 'All 6 chain colors are in use. Right-click an old chain and choose "Clear combat chain" first.',
            ]);
        }

        $this->updateMapSolarsystemAction->handle($home, [
            'combat_color' => $color,
            'combat_home' => true,
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

        foreach ($homes as $home) {
            $shouldPulse = in_array($home->combat_color, $active, true);
            if ($home->combat_active !== $shouldPulse) {
                $this->updateMapSolarsystemAction->handle($home, ['combat_active' => $shouldPulse]);
            }
        }
    }
}
