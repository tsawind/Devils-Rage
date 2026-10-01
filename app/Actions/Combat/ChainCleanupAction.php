<?php

declare(strict_types=1);

namespace App\Actions\Combat;

use App\Actions\MapSolarsystem\UpdateMapSolarsystemAction;
use App\Events\Maps\CombatModeTurnedOffEvent;
use App\Events\Signatures\SignatureUpdatedEvent;
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
 * Patch 14: clean a combat chain up into another chain, one system at a time.
 *
 * - start: the combat home loses its color, so the chain has no home; its
 *   systems hang off the home (main chain, side chain, or the home becomes
 *   a side chain itself). Combat turns off for whoever works the chain.
 *   Nothing is renamed.
 * - convert: standing in a system outside the chain, a scanner re-bookmarks
 *   one chain system in game and presses Done: only that system leaves the
 *   chain and takes its new name (the hole leading to it takes it too). The
 *   systems further out keep their combat names until someone gets there.
 * - return done: the converted system's way back ("*") was re-bookmarked.
 */
final readonly class ChainCleanupAction
{
    public function __construct(
        private UpdateMapSolarsystemAction $updateMapSolarsystemAction,
        private MapBroadcaster $mapBroadcaster,
    ) {}

    /**
     * @throws Throwable
     */
    public function start(MapSolarsystem $home, User $by): void
    {
        if (! $home->combat_home || blank($home->combat_color)) {
            throw ValidationException::withMessages(['combat' => 'Only a combat home starts a cleanup.']);
        }
        $color = (string) $home->combat_color;

        $turnedOff = DB::transaction(function () use ($home, $color): array {
            $this->updateMapSolarsystemAction->handle($home, [
                'combat_color' => null,
                'combat_home' => false,
                'combat_active' => false,
                'combat_started_at' => null,
                'combat_previous_color' => $color,
            ]);

            // The chain has no home any more: nobody can keep working it in combat mode.
            $settings = MapUserSetting::query()
                ->where('map_id', $home->map_id)
                ->where('combat_mode', true)
                ->where('combat_color', $color)
                ->get();
            foreach ($settings as $setting) {
                $setting->update(['combat_mode' => false, 'combat_color' => null]);
            }

            return $settings->pluck('user_id')->all();
        });

        $label = ucfirst($color);
        foreach ($turnedOff as $userId) {
            if ((int) $userId === $by->id) {
                continue;
            }
            broadcast(new CombatModeTurnedOffEvent(
                (int) $userId,
                $home->map_id,
                sprintf('Combat off: %s started cleaning up the %s chain', $by->name, $label),
                'The chain is being converted one system at a time. Follow the cleanup rows in the signature list.',
            ));
        }
    }

    /**
     * @throws Throwable
     */
    public function convert(MapSolarsystem $system, MapSolarsystem $parent, string $alias): void
    {
        $alias = trim($alias);
        if ($alias === '') {
            throw ValidationException::withMessages(['alias' => 'No new name given.']);
        }
        if (blank($system->combat_color) || $system->combat_home || $system->map_id !== $parent->map_id) {
            throw ValidationException::withMessages(['combat' => 'That system is not waiting for cleanup.']);
        }
        if (filled($parent->combat_color)) {
            throw ValidationException::withMessages(['combat' => 'Convert the system it hangs off first.']);
        }

        DB::transaction(function () use ($system, $parent, $alias): void {
            $clash = MapSolarsystem::query()
                ->where('map_id', $system->map_id)
                ->whereKeyNot($system->id)
                ->where('alias', $alias)
                ->exists();
            if ($clash) {
                throw ValidationException::withMessages(['alias' => sprintf('%s is already used on the map.', $alias)]);
            }

            $connection = MapConnection::query()
                ->where('map_id', $system->map_id)
                ->where(fn ($query) => $query
                    ->where(fn ($inner) => $inner->where('from_map_solarsystem_id', $parent->id)->where('to_map_solarsystem_id', $system->id))
                    ->orWhere(fn ($inner) => $inner->where('from_map_solarsystem_id', $system->id)->where('to_map_solarsystem_id', $parent->id)))
                ->first();
            if (! $connection instanceof MapConnection) {
                throw ValidationException::withMessages(['combat' => 'That system is not connected to this one.']);
            }

            $this->updateMapSolarsystemAction->handle($system, [
                'alias' => $alias,
                'combat_color' => null,
                'combat_home' => false,
                'combat_active' => false,
                'combat_previous_color' => $system->combat_color,
                'cleanup_return_pending' => true,
            ]);

            // The hole leading to it, on the parent's side, takes the new name too.
            $hole = Signature::query()
                ->where('map_connection_id', $connection->id)
                ->where('map_solarsystem_id', $parent->id)
                ->first();
            if ($hole instanceof Signature) {
                $hole->update(['alias' => $alias]);
                $this->mapBroadcaster->signaturesChanged($parent);
                broadcast(new SignatureUpdatedEvent($parent->map_id));
            }
        });
    }

    public function returnDone(MapSolarsystem $system): void
    {
        $this->updateMapSolarsystemAction->handle($system, ['cleanup_return_pending' => false]);
    }
}
