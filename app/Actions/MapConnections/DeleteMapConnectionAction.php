<?php

declare(strict_types=1);

namespace App\Actions\MapConnections;

use App\Actions\MapSolarsystem\DeleteMapSolarsystemAction;
use App\Actions\MapSolarsystem\HideMapSolarsystemsAction;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Support\Broadcasting\MapBroadcaster;
use App\Support\ChainMemory;
use Throwable;

final readonly class DeleteMapConnectionAction
{
    public function __construct(
        private DeleteMapSolarsystemAction $deleteMapSolarsystemAction,
        private MapBroadcaster $mapBroadcaster,
        private HideMapSolarsystemsAction $hideMapSolarsystemsAction,
    ) {}

    /**
     * Patch 37: the systems this pipe links to home and nothing else does: deleting the pipe
     * (a rolled or collapsed hole) cuts them off, so they leave the map with it (hidden, chain
     * memory: a reconnect within 27 h brings them back) instead of landing in Side chains.
     * Empty when the map has no home on it, or the pipe isn't on home's chain.
     *
     * @return list<int>
     */
    public function systemsCutOffBy(MapConnection $mapConnection): array
    {
        $map = $mapConnection->map;
        $home = $map->home_solarsystem_id === null ? null : MapSolarsystem::query()
            ->where('map_id', $map->id)
            ->where('solarsystem_id', $map->home_solarsystem_id)
            ->value('id');
        if ($home === null) {
            return [];
        }

        $edges = MapConnection::query()->where('map_id', $map->id)->get(['id', 'from_map_solarsystem_id', 'to_map_solarsystem_id']);
        $reach = function (?int $without) use ($edges, $home): array {
            $neighbours = [];
            foreach ($edges as $edge) {
                if ((int) $edge->id === $without) {
                    continue;
                }
                $neighbours[(int) $edge->from_map_solarsystem_id][] = (int) $edge->to_map_solarsystem_id;
                $neighbours[(int) $edge->to_map_solarsystem_id][] = (int) $edge->from_map_solarsystem_id;
            }
            $seen = [(int) $home => true];
            $queue = [(int) $home];
            while ($queue !== []) {
                $id = array_shift($queue);
                foreach ($neighbours[$id] ?? [] as $next) {
                    if (! isset($seen[$next])) {
                        $seen[$next] = true;
                        $queue[] = $next;
                    }
                }
            }

            return array_keys($seen);
        };

        $cut = array_values(array_diff($reach(null), $reach((int) $mapConnection->id)));
        if ($cut === []) {
            return [];
        }

        // Only systems still on the map, never home / pinned / rally / rage ones.
        return MapSolarsystem::query()
            ->where('map_id', $map->id)
            ->whereIn('id', $cut)
            ->get()
            ->reject(fn (MapSolarsystem $system): bool => ChainMemory::isProtected($map, $system))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Patch 37: $keepChain: "Disconnect (keep as side chain)": what the pipe linked to home
     * stays on the map, in Side chains.
     *
     * @throws Throwable
     */
    public function handle(MapConnection $mapConnection, bool $remove_map_solarsystem = false, bool $keepChain = false): void
    {
        $map = $mapConnection->map;

        $from_map_solarsystem = $mapConnection->fromMapSolarsystem;
        $to_map_solarsystem = $mapConnection->toMapSolarsystem;
        $cut_off = $keepChain ? [] : $this->systemsCutOffBy($mapConnection);

        $mapConnection->delete();

        // Patch 37: what this pipe linked to home leaves the map with it (Side chains stay for the rest).
        if ($cut_off !== []) {
            $this->hideMapSolarsystemsAction->handle($map, $cut_off);
        }

        $removed_system_ids = [];
        if ($remove_map_solarsystem) {
            foreach ([$from_map_solarsystem, $to_map_solarsystem] as $map_solarsystem) {
                if (in_array($map_solarsystem->id, $cut_off, true)) {
                    continue;
                }
                if ($this->checkAndRemoveMapSolarsystem($map_solarsystem)) {
                    $removed_system_ids[] = $map_solarsystem->id;
                }
            }
        }

        $this->mapBroadcaster->connectionsRemoved($map->id, [$mapConnection->id], $removed_system_ids);
    }

    /**
     * @throws Throwable
     */
    private function checkAndRemoveMapSolarsystem(MapSolarsystem $mapSolarsystem): bool
    {
        if (ChainMemory::isAlwaysKept($mapSolarsystem->map, $mapSolarsystem)) {
            return false;
        }

        if ($mapSolarsystem->mapConnections()->exists()) {
            return false;
        }

        // Patch 36: left with no pipe, the system is hidden (chain memory), not deleted.
        return $this->deleteMapSolarsystemAction->handle($mapSolarsystem);
    }
}
