<?php

declare(strict_types=1);

namespace App\Actions\MapSelection;

use App\Actions\MapSolarsystem\HideMapSolarsystemsAction;
use App\Models\Map;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Support\Broadcasting\MapBroadcaster;
use App\Support\ChainMemory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class DeleteMapSelectionAction
{
    public function __construct(
        private MapBroadcaster $mapBroadcaster,
        private HideMapSolarsystemsAction $hideMapSolarsystemsAction,
    ) {}

    /**
     * Remove the selected systems from the map (a selection, side chains,
     * orphans, Clear map). Patch 36 (chain memory): they are hidden, not
     * deleted. The map's home and pinned systems are never hidden; selected by
     * hand they are still deleted as before (pipes and signatures cascade away;
     * the persistent details survive).
     *
     * @param  int[]  $map_solarsystem_ids
     *
     * @throws Throwable
     */
    public function handle(array $map_solarsystem_ids): void
    {
        DB::transaction(function () use ($map_solarsystem_ids): void {
            // A selection always belongs to a single map.
            $map_id = MapSolarsystem::query()->whereIn('id', $map_solarsystem_ids)->value('map_id');

            if ($map_id === null) {
                return;
            }

            $map = Map::query()->findOrFail($map_id);
            $systems = MapSolarsystem::query()->where('map_id', $map_id)->whereIn('id', $map_solarsystem_ids)->get();
            [$kept, $hideable] = $systems->partition(fn (MapSolarsystem $system): bool => ChainMemory::isAlwaysKept($map, $system));

            $this->hideMapSolarsystemsAction->handle($map, $hideable->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all());

            if ($kept->isEmpty()) {
                return;
            }

            $deleted_system_ids = $kept->pluck('id')->all();
            $deleted_connection_ids = MapConnection::query()
                ->where(fn (Builder $query) => $query
                    ->whereIn('from_map_solarsystem_id', $deleted_system_ids)
                    ->orWhereIn('to_map_solarsystem_id', $deleted_system_ids))
                ->pluck('id')
                ->all();

            MapSolarsystem::query()->whereIn('id', $deleted_system_ids)->delete();

            $this->mapBroadcaster->systemsRemoved($map_id, $deleted_system_ids, $deleted_connection_ids);
        });
    }
}
