<?php

declare(strict_types=1);

namespace App\Actions\MapSolarsystem;

use App\Models\Map;
use App\Models\MapSolarsystem;
use App\Support\Broadcasting\MapBroadcaster;
use App\Support\ChainMemory;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class DeleteMapSolarsystemAction
{
    public function __construct(
        private MapBroadcaster $mapBroadcaster,
        private HideMapSolarsystemsAction $hideMapSolarsystemsAction,
    ) {}

    /**
     * Remove a system from the map. Patch 36 (chain memory): it is hidden, not
     * deleted, so a reconnect within 27 h brings it back as it was. The map's
     * home and pinned systems are never hidden: removing one by hand still
     * deletes its placement (its pipes and signatures cascade away; the
     * persistent details survive).
     *
     * @throws Throwable
     */
    public function handle(MapSolarsystem $mapSolarsystem): bool
    {
        $map = Map::query()->findOrFail($mapSolarsystem->map_id);

        if (! ChainMemory::isAlwaysKept($map, $mapSolarsystem)) {
            $hidden = $this->hideMapSolarsystemsAction->handle($map, [$mapSolarsystem->id]);
            if ($hidden !== []) {
                return true;
            }
        }

        return DB::transaction(function () use ($mapSolarsystem): bool {
            $map_id = $mapSolarsystem->map_id;
            $connection_ids = $mapSolarsystem->mapConnections()->get(['id'])->pluck('id')->all();

            $deleted = (bool) $mapSolarsystem->delete();

            $this->mapBroadcaster->systemsRemoved($map_id, [$mapSolarsystem->id], $connection_ids);

            return $deleted;
        });
    }
}
