<?php

declare(strict_types=1);

namespace App\Actions\MapSolarsystem;

use App\Actions\MapConnections\CreateMapConnectionAction;
use App\Jobs\MapAlerts\AnnounceChainExitJob;
use App\Jobs\MapAlerts\AnnounceKnownHomeJob;
use App\Jobs\MapAlerts\EvaluateAssetWatchesJob;
use App\Jobs\MapAlerts\EvaluateMapAlertsJob;
use App\Models\Map;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Support\Broadcasting\MapBroadcaster;
use App\Support\ChainMemory;
use Illuminate\Database\Eloquent\Builder;

final readonly class StoreMapSolarsystemAction
{
    public function __construct(
        private CreateMapConnectionAction $createMapConnection,
        private MapBroadcaster $mapBroadcaster,
    ) {}

    public function handle(Map $map, array $data): MapSolarsystem
    {
        // Persistent intel is kept (or created with defaults) so it survives a system being
        // removed and re-added; only the placement is (re)created.
        $details = $map->mapSolarsystemDetails()->firstOrCreate([
            'solarsystem_id' => $data['solarsystem_id'],
        ]);

        // Patch 36 (chain memory): a hidden system keeps its row, so re-adding it brings it back.
        $map_solarsystem = MapSolarsystem::withHidden()
            ->where('map_id', $map->id)
            ->where('solarsystem_id', $data['solarsystem_id'])
            ->first() ?? new MapSolarsystem(['map_id' => $map->id, 'solarsystem_id' => $data['solarsystem_id']]);
        $map_solarsystem->map_id = $map->id;
        $map_solarsystem->solarsystem_id = (int) $data['solarsystem_id'];
        $map_solarsystem->map_solarsystem_details_id = $details->id;

        $was_hidden = $map_solarsystem->exists && $map_solarsystem->isHidden();
        $old_position = [(int) ($map_solarsystem->getAttributes()['position_x'] ?? 0), (int) ($map_solarsystem->getAttributes()['position_y'] ?? 0)];
        if ($was_hidden) {
            ChainMemory::unhide($map_solarsystem);
        }

        // Only a newly placed (or restored) system takes the requested position; re-adding one
        // already on the map (e.g. to connect to it) must leave it where the user put it.
        if (! $map_solarsystem->exists || $was_hidden) {
            $map_solarsystem->position_x = $data['position_x'];
            $map_solarsystem->position_y = $data['position_y'];
        }
        $map_solarsystem->save();

        // Patch 37: the rest of its old chain comes back with it, its pipes faded.
        $restored = ['systems' => [], 'connections' => []];
        if ($was_hidden) {
            $restored = ChainMemory::restoreChain($map_solarsystem, [
                (int) $map_solarsystem->position_x - $old_position[0],
                (int) $map_solarsystem->position_y - $old_position[1],
            ]);
        }

        // The rest of a returning chain counts as added too (alerts never saw it come back otherwise).
        foreach ([$map_solarsystem->id, ...$restored['systems']] as $returned_id) {
            EvaluateMapAlertsJob::dispatch($returned_id)->afterCommit();
            EvaluateAssetWatchesJob::dispatch($returned_id)->afterCommit();
            AnnounceChainExitJob::dispatch($returned_id)->afterCommit();
            AnnounceKnownHomeJob::dispatch($returned_id)->afterCommit();
        }

        // Patch 53: a dotted hole that someone knows the far side of: the new pipe is that hole
        // (also when the system is on the map already: a loop).
        $hole = isset($data['link_signature_id'])
            ? Signature::query()->where('map_solarsystem_id', $data['connect_to_map_solarsystem_id'] ?? 0)->whereNull('map_connection_id')->find($data['link_signature_id'])
            : null;
        $connection = $this->connectToOrigin($map, $map_solarsystem, $data['connect_to_map_solarsystem_id'] ?? null, always: $hole instanceof Signature);
        if ($hole instanceof Signature && $connection instanceof MapConnection) {
            $hole->forceFill(['map_connection_id' => $connection->id])->save();
            if (blank($map_solarsystem->alias) && filled($data['alias'] ?? null)) {
                $map_solarsystem->forceFill(['alias' => (string) $data['alias']])->save();
            }
            $this->mapBroadcaster->connectionsUpserted($map->id, MapConnection::query()
                ->whereKey($connection->id)
                ->with('signatures.signatureType', 'signatures.wormhole')
                ->withJumpSummary()
                ->get());
            $origin = MapSolarsystem::query()->find($hole->map_solarsystem_id);
            if ($origin instanceof MapSolarsystem) {
                $this->mapBroadcaster->signaturesChanged($origin);
            }
        }

        $this->mapBroadcaster->systemsUpserted($map->id, MapSolarsystem::query()
            ->whereKey([$map_solarsystem->id, ...$restored['systems']])
            ->with('details')
            ->withCount('signatures', 'wormholeSignatures', 'mapConnections', 'uncategorizedSignatures')
            ->get());

        if ($restored['connections'] !== []) {
            $this->mapBroadcaster->connectionsUpserted($map->id, MapConnection::query()
                ->whereKey($restored['connections'])
                ->with('signatures.signatureType', 'signatures.wormhole')
                ->withJumpSummary()
                ->get());
        }

        return $map_solarsystem;
    }

    /**
     * Link the freshly placed system back to the system it was added from (when the user
     * used "Add connection"), skipping self-links and any connection that already exists.
     */
    private function connectToOrigin(Map $map, MapSolarsystem $target, ?int $origin_id, bool $always = false): ?MapConnection
    {
        if ($origin_id === null || $origin_id === $target->id) {
            return null;
        }
        if ($always) {
            return $this->createMapConnection->handle([
                'from_map_solarsystem_id' => $origin_id,
                'to_map_solarsystem_id' => $target->id,
            ]);
        }

        // Each direction must AND its from/to pair, so the two pairs are wrapped in their
        // own closures — array-form where()/orWhere() would join the keys with the call's
        // boolean and turn the second pair into an OR, matching any link touching either node.
        $already_connected = MapConnection::query()
            ->where('map_id', $map->id)
            ->where(function (Builder $query) use ($origin_id, $target): void {
                $query
                    ->where(fn (Builder $pair) => $pair
                        ->where('from_map_solarsystem_id', $origin_id)
                        ->where('to_map_solarsystem_id', $target->id))
                    ->orWhere(fn (Builder $pair) => $pair
                        ->where('from_map_solarsystem_id', $target->id)
                        ->where('to_map_solarsystem_id', $origin_id));
            })
            ->exists();

        if ($already_connected) {
            return null;
        }

        return $this->createMapConnection->handle([
            'from_map_solarsystem_id' => $origin_id,
            'to_map_solarsystem_id' => $target->id,
        ]);
    }
}
