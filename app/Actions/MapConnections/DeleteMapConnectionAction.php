<?php

declare(strict_types=1);

namespace App\Actions\MapConnections;

use App\Actions\MapSolarsystem\DeleteMapSolarsystemAction;
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
    ) {}

    /**
     * @throws Throwable
     */
    public function handle(MapConnection $mapConnection, bool $remove_map_solarsystem = false): void
    {
        $map = $mapConnection->map;

        $from_map_solarsystem = $mapConnection->fromMapSolarsystem;
        $to_map_solarsystem = $mapConnection->toMapSolarsystem;

        $mapConnection->delete();

        $removed_system_ids = [];
        if ($remove_map_solarsystem) {
            foreach ([$from_map_solarsystem, $to_map_solarsystem] as $map_solarsystem) {
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
