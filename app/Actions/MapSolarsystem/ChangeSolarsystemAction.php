<?php

declare(strict_types=1);

namespace App\Actions\MapSolarsystem;

use App\Models\MapSolarsystem;
use App\Models\Solarsystem;
use App\Support\Broadcasting\MapBroadcaster;
use Illuminate\Validation\ValidationException;

/**
 * Patch 53: double-click a system → "Real system": it was put on the map as the wrong system (or
 * someone typed the J-code wrong). It becomes the right one, keeping its name, holes, pipes and
 * signatures; the intel (occupier, notes) is the new system's.
 */
final readonly class ChangeSolarsystemAction
{
    public function __construct(private MapBroadcaster $mapBroadcaster) {}

    /**
     * @throws ValidationException
     */
    public function handle(MapSolarsystem $mapSolarsystem, int $solarsystemId): MapSolarsystem
    {
        if ($mapSolarsystem->solarsystem_id === $solarsystemId) {
            return $mapSolarsystem;
        }

        $taken = MapSolarsystem::withHidden()
            ->where('map_id', $mapSolarsystem->map_id)
            ->where('solarsystem_id', $solarsystemId)
            ->whereKeyNot($mapSolarsystem->id)
            ->first();
        if ($taken instanceof MapSolarsystem) {
            $name = Solarsystem::query()->whereKey($solarsystemId)->value('name') ?? 'That system';
            throw ValidationException::withMessages(['solarsystem_id' => $taken->isHidden()
                ? sprintf('%s is in the map\'s chain memory (an old chain). Add it from the map to bring it back, then connect it.', $name)
                : sprintf('%s is on the map already%s. Connect to it instead.', $name, filled($taken->alias) ? ' as '.$taken->alias : '')]);
        }

        $details = $mapSolarsystem->map->mapSolarsystemDetails()->firstOrCreate(['solarsystem_id' => $solarsystemId]);
        $mapSolarsystem->forceFill([
            'solarsystem_id' => $solarsystemId,
            'map_solarsystem_details_id' => $details->id,
        ])->save();

        $this->mapBroadcaster->systemsUpserted($mapSolarsystem->map_id, MapSolarsystem::query()
            ->whereKey($mapSolarsystem->id)
            ->with('details')
            ->withCount('signatures', 'wormholeSignatures', 'mapConnections', 'uncategorizedSignatures')
            ->get());

        return $mapSolarsystem;
    }
}
