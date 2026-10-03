<?php

declare(strict_types=1);

namespace App\Actions\Signatures;

use App\Actions\MapConnections\BroadcastMapConnectionAction;
use App\Actions\MapConnections\SyncConnectionShipSizeAction;
use App\Enums\ConnectionType;
use App\Events\Signatures\SignatureUpdatedEvent;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Models\SignatureType;
use App\Support\Broadcasting\MapBroadcaster;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Patch 14: right-click a jumped connection → Type. The hole's type goes on
 * the side it spawned from (the client picks it: e.g. N432 only spawns in
 * nullsec, so the nullsec side). That side's signature is typed, or created
 * without an ID when nobody pasted that system yet (a paste there fills the
 * ID in later). The other side becomes the K162.
 *
 * Absorb: a paste found the real signature of a hole typed this way: it gives
 * its ID to the typed row and is removed, so the hole isn't listed twice.
 */
final readonly class ConnectionHoleTypeAction
{
    public function __construct(
        private FillFarSideK162Action $fillFarSideK162Action,
        private SyncConnectionShipSizeAction $syncConnectionShipSizeAction,
        private MapBroadcaster $mapBroadcaster,
        private BroadcastMapConnectionAction $broadcastMapConnectionAction,
    ) {}

    /**
     * @throws Throwable
     */
    public function setType(MapConnection $connection, int $sideId, SignatureType $type): void
    {
        if (! in_array($sideId, [$connection->from_map_solarsystem_id, $connection->to_map_solarsystem_id], true)) {
            throw ValidationException::withMessages(['side' => 'That system is not on this connection.']);
        }
        $isK162 = self::isK162($type);

        DB::transaction(function () use ($connection, $sideId, $type, $isK162): void {
            $fields = [
                'signature_category_id' => $type->signature_category_id,
                'signature_type_id' => $type->id,
                'wormhole_id' => $type->wormhole?->id,
                'raw_type_name' => null,
            ];

            $signature = Signature::query()
                ->where('map_connection_id', $connection->id)
                ->where('map_solarsystem_id', $sideId)
                ->first();
            if ($signature instanceof Signature) {
                $signature->update($fields);
            } else {
                $signature = Signature::query()->create([
                    'map_solarsystem_id' => $sideId,
                    'map_connection_id' => $connection->id,
                    'signature_id' => null,
                    ...$fields,
                ]);
            }

            // Only one side can be the hole itself: a normal type on the far side gives way to its K162.
            // Patch 20: a K162 set on this side leaves the far side's real type alone (only a second K162 goes).
            $others = Signature::query()
                ->with('signatureType')
                ->where('map_connection_id', $connection->id)
                ->where('map_solarsystem_id', '!=', $sideId)
                ->get();
            foreach ($others as $other) {
                if (! $other->signatureType instanceof SignatureType) {
                    continue;
                }
                if (self::isK162($other->signatureType) === $isK162) {
                    $other->update(['signature_type_id' => null, 'wormhole_id' => null]);
                }
            }

            if (! $isK162) {
                $this->fillFarSideK162Action->handle($connection->id);
            }
            $this->syncConnectionShipSizeAction->handle($signature);
        });

        $this->broadcast($connection);
    }

    /**
     * @throws Throwable
     */
    public function absorb(Signature $typed, Signature $pasted): void
    {
        if ($typed->signature_id !== null || $typed->map_connection_id === null) {
            throw ValidationException::withMessages(['signature' => 'That row already has its signature ID.']);
        }
        if ($pasted->map_solarsystem_id !== $typed->map_solarsystem_id || $pasted->map_connection_id !== null || $pasted->id === $typed->id) {
            throw ValidationException::withMessages(['signature' => 'That signature belongs elsewhere.']);
        }

        DB::transaction(function () use ($typed, $pasted): void {
            $signatureId = $pasted->signature_id;
            $pasted->delete();
            $typed->update(['signature_id' => $signatureId]);
        });

        $system = MapSolarsystem::query()->find($typed->map_solarsystem_id);
        if ($system instanceof MapSolarsystem) {
            $this->mapBroadcaster->signaturesChanged($system);
            broadcast(new SignatureUpdatedEvent($system->map_id));
        }
    }

    /**
     * Patch 16: connection right-click → "Link to a different hole…": the jump
     * went through another signature on that side. The link moves to it (the
     * two swap numbers); the old one stays as an unjumped hole, unless it was
     * only a stand-in typed from the map without an ID, which goes (its type
     * moves along when the new one has none).
     *
     * @throws Throwable
     */
    public function relink(MapConnection $connection, Signature $target): void
    {
        $sideId = $target->map_solarsystem_id;
        if (! in_array($sideId, [$connection->from_map_solarsystem_id, $connection->to_map_solarsystem_id], true)) {
            throw ValidationException::withMessages(['signature' => 'That signature is not on either end of this connection.']);
        }
        if ($connection->type === ConnectionType::Stargate) {
            throw ValidationException::withMessages(['signature' => 'A stargate has no signature to link.']);
        }
        if ($target->map_connection_id !== null) {
            throw ValidationException::withMessages(['signature' => 'That hole is already linked to a connection.']);
        }

        DB::transaction(function () use ($connection, $target, $sideId): void {
            $current = Signature::query()
                ->where('map_connection_id', $connection->id)
                ->where('map_solarsystem_id', $sideId)
                ->first();

            $targetAlias = $target->alias;
            if ($current instanceof Signature) {
                $currentAlias = $current->alias;
                if ($current->signature_id === null) {
                    if ($target->signature_type_id === null && $current->signature_type_id !== null) {
                        $target->fill([
                            'signature_category_id' => $current->signature_category_id,
                            'signature_type_id' => $current->signature_type_id,
                            'wormhole_id' => $current->wormhole_id,
                        ]);
                    }
                    $current->delete();
                    // A stand-in typed from the map has no number of its own: the target keeps its locked one.
                    $currentAlias ??= $targetAlias;
                } else {
                    // Free the number first (one number per system), then swap.
                    $current->update(['map_connection_id' => null, 'alias' => null]);
                }
                $target->fill(['alias' => $currentAlias]);
                $target->map_connection_id = $connection->id;
                $target->save();
                if ($current->exists) {
                    $current->update(['alias' => $targetAlias]);
                }
            } else {
                $target->map_connection_id = $connection->id;
                $target->save();
            }

            // Only one side can be the hole itself: if the new one is typed, the far side gives way to its K162.
            $target->load('signatureType');
            if ($target->signatureType instanceof SignatureType && ! self::isK162($target->signatureType)) {
                $others = Signature::query()
                    ->with('signatureType')
                    ->where('map_connection_id', $connection->id)
                    ->where('map_solarsystem_id', '!=', $sideId)
                    ->get();
                foreach ($others as $other) {
                    if ($other->signatureType instanceof SignatureType && ! self::isK162($other->signatureType)) {
                        $other->update(['signature_type_id' => null, 'wormhole_id' => null]);
                    }
                }
            }

            $this->fillFarSideK162Action->handle($connection->id);
            $this->syncConnectionShipSizeAction->handle($target);
        });

        $this->broadcast($connection);
    }

    private function broadcast(MapConnection $connection): void
    {
        foreach ([$connection->from_map_solarsystem_id, $connection->to_map_solarsystem_id] as $id) {
            $system = MapSolarsystem::query()->find($id);
            if ($system instanceof MapSolarsystem) {
                $this->mapBroadcaster->signaturesChanged($system);
            }
        }
        broadcast(new SignatureUpdatedEvent($connection->map_id));
        // Patch 20: the pipe (type, size, K162 side) updates for everyone at once.
        $this->broadcastMapConnectionAction->handle($connection);
    }

    private static function isK162(SignatureType $type): bool
    {
        $signature = $type->signature;
        $name = $signature instanceof BackedEnum ? (string) $signature->value : (string) ($signature ?? $type->name);

        return str_starts_with(mb_strtoupper($name), 'K162');
    }
}
