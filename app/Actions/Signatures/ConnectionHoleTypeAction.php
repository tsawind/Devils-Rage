<?php

declare(strict_types=1);

namespace App\Actions\Signatures;

use App\Actions\MapConnections\SyncConnectionShipSizeAction;
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
    ) {}

    /**
     * @throws Throwable
     */
    public function setType(MapConnection $connection, int $sideId, SignatureType $type): void
    {
        if (! in_array($sideId, [$connection->from_map_solarsystem_id, $connection->to_map_solarsystem_id], true)) {
            throw ValidationException::withMessages(['side' => 'That system is not on this connection.']);
        }
        if (self::isK162($type)) {
            throw ValidationException::withMessages(['type' => 'Pick the hole type; its far side is the K162.']);
        }

        DB::transaction(function () use ($connection, $sideId, $type): void {
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

            $this->fillFarSideK162Action->handle($connection->id);
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

    private function broadcast(MapConnection $connection): void
    {
        foreach ([$connection->from_map_solarsystem_id, $connection->to_map_solarsystem_id] as $id) {
            $system = MapSolarsystem::query()->find($id);
            if ($system instanceof MapSolarsystem) {
                $this->mapBroadcaster->signaturesChanged($system);
            }
        }
        broadcast(new SignatureUpdatedEvent($connection->map_id));
    }

    private static function isK162(SignatureType $type): bool
    {
        $signature = $type->signature;
        $name = $signature instanceof BackedEnum ? (string) $signature->value : (string) ($signature ?? $type->name);

        return str_starts_with(mb_strtoupper($name), 'K162');
    }
}
