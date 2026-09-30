<?php

declare(strict_types=1);

namespace App\Actions\Signatures;

use App\Enums\SolarsystemClass;
use App\Models\MapConnection;
use App\Models\Signature;
use App\Models\SignatureType;
use App\Models\Solarsystem;
use App\Support\Broadcasting\MapBroadcaster;
use BackedEnum;

/**
 * The far end of a normal (non-K162) wormhole is always a K162 leading back to
 * the class of the system the hole started in. When a connection has a typed
 * normal hole on one side and an untyped signature on the other, fill the
 * other side in as "K162 - <that class>". A type someone set by hand is never
 * overwritten.
 */
final readonly class FillFarSideK162Action
{
    public function __construct(private MapBroadcaster $mapBroadcaster) {}

    public function handle(?int $mapConnectionId): void
    {
        if ($mapConnectionId === null) {
            return;
        }

        $connection = MapConnection::query()->find($mapConnectionId);
        if (! $connection instanceof MapConnection) {
            return;
        }

        $signatures = Signature::query()
            ->where('map_connection_id', $connection->id)
            ->with(['signatureType', 'mapSolarsystem.solarsystem.wormholeSystem'])
            ->get();

        foreach ($signatures as $typed) {
            $type = $typed->signatureType;
            if (! $type instanceof SignatureType || $this->isK162($type)) {
                continue;
            }

            $class = $this->classOf($typed->mapSolarsystem?->solarsystem);
            if (! $class instanceof SolarsystemClass) {
                continue;
            }

            $k162 = $this->k162Leading($class);
            if (! $k162 instanceof SignatureType) {
                continue;
            }

            foreach ($signatures as $other) {
                if ($other->map_solarsystem_id === $typed->map_solarsystem_id || $other->signature_type_id !== null) {
                    continue;
                }

                $other->update([
                    'signature_type_id' => $k162->id,
                    'signature_category_id' => $k162->signature_category_id,
                    'wormhole_id' => $k162->wormhole?->id,
                    'raw_type_name' => null,
                ]);

                if ($other->mapSolarsystem !== null) {
                    $this->mapBroadcaster->signaturesChanged($other->mapSolarsystem);
                }
            }
        }
    }

    private function isK162(SignatureType $type): bool
    {
        $signature = $type->signature;
        $code = $signature instanceof BackedEnum ? (string) $signature->value : (string) $signature;

        return mb_strtoupper($code) === 'K162';
    }

    private function classOf(?Solarsystem $solarsystem): ?SolarsystemClass
    {
        if (! $solarsystem instanceof Solarsystem) {
            return null;
        }

        return $solarsystem->wormholeSystem?->class ?? SolarsystemClass::fromSecurity((float) $solarsystem->security);
    }

    private function k162Leading(SolarsystemClass $class): ?SignatureType
    {
        return SignatureType::query()
            ->where('signature', 'K162')
            ->where('target_class', $class->value)
            ->orderByRaw('extra is null desc')
            ->first();
    }
}
