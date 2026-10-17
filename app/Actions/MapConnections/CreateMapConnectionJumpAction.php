<?php

declare(strict_types=1);

namespace App\Actions\MapConnections;

use App\Models\MapConnection;
use App\Models\MapConnectionJump;
use App\Models\Type;

/**
 * Creates a manually logged jump on a connection, e.g. for untracked pilots
 * whose passage would otherwise be missing from the mass estimate.
 *
 * @phpstan-type ManualJumpData array{map_connection_id: int, direction: string, ship_type_id?: int|null, mass?: int|null, side?: string|null, ship_class?: string|null, fit?: array<string, bool>|null}
 */
final readonly class CreateMapConnectionJumpAction
{
    public function __construct(
        private BroadcastMapConnectionAction $broadcastMapConnection,
        private InferMassStatusFromJumpsAction $inferMassStatus,
    ) {}

    /**
     * @param  ManualJumpData  $data
     */
    public function handle(array $data): MapConnectionJump
    {
        $connection = MapConnection::query()
            ->with('fromMapSolarsystem', 'toMapSolarsystem')
            ->findOrFail($data['map_connection_id']);

        // Patch 39: a direction nobody saw is logged as outbound, marked unknown.
        $outbound = $data['direction'] !== 'inbound';
        $ship_type_id = $data['ship_type_id'] ?? null;

        $jump = MapConnectionJump::query()->create([
            'map_id' => $connection->map_id,
            'map_connection_id' => $connection->id,
            'character_id' => null,
            'from_solarsystem_id' => $outbound
                ? $connection->fromMapSolarsystem->solarsystem_id
                : $connection->toMapSolarsystem->solarsystem_id,
            'to_solarsystem_id' => $outbound
                ? $connection->toMapSolarsystem->solarsystem_id
                : $connection->fromMapSolarsystem->solarsystem_id,
            'ship_type_id' => $ship_type_id,
            'mass' => $data['mass'] ?? $this->shipTypeMass($ship_type_id),
            'is_manual' => true,
            'side' => $data['side'] ?? null,
            'ship_class' => $data['ship_class'] ?? null,
            'direction_known' => $data['direction'] !== 'unknown',
            'fit' => $data['fit'] ?? null,
        ]);
        // Patch 39: a jump added from the watch banner is a click in it ("still on it?").
        if (($data['side'] ?? null) !== null && $connection->mass_watched_at !== null) {
            $connection->forceFill(['mass_watch_touched_at' => now()])->save();
        }

        // Patch 33: the log may prove the hole reduced / critical / rolled.
        $this->inferMassStatus->handle($connection);
        $this->broadcastMapConnection->handle($connection);

        return $jump;
    }

    private function shipTypeMass(?int $ship_type_id): int
    {
        // Patch 34: HICs / Odysseus log as if their Zero-Point Mass Entangler were on.
        return Type::loggedJumpMass($ship_type_id);
    }
}
