<?php

declare(strict_types=1);

namespace App\Actions\Signatures;

use App\Enums\SolarsystemClass;
use App\Models\MapConnection;
use App\Models\Signature;
use App\Models\SignatureType;
use App\Models\Solarsystem;
use BackedEnum;
use Throwable;

/**
 * Patch 37: a jump through a K162 tells what the hole on the far side is when only one
 * type fits: it spawned in the far system's class and leads into the near system's
 * class, frigate-sized when the K162 was marked "K162 - Frigate" (else not). Example:
 * a frigate K162 in a C5, jumped into a C5, can only be a C008. A type someone set by
 * hand is never overwritten.
 */
final readonly class InferFarSideTypeAction
{
    public function __construct(private ConnectionHoleTypeAction $connectionHoleTypeAction) {}

    /**
     * @throws Throwable
     */
    public function handle(?int $mapConnectionId): void
    {
        $connection = $mapConnectionId === null ? null : MapConnection::query()->find($mapConnectionId);
        if (! $connection instanceof MapConnection) {
            return;
        }

        $signatures = Signature::query()
            ->where('map_connection_id', $connection->id)
            ->with(['signatureType.wormhole', 'mapSolarsystem.solarsystem.wormholeSystem'])
            ->get();

        $near = $signatures->first(fn (Signature $signature): bool => $signature->signatureType instanceof SignatureType && self::isK162($signature->signatureType));
        if (! $near instanceof Signature) {
            return;
        }
        $farTyped = $signatures->first(fn (Signature $signature): bool => $signature->map_solarsystem_id !== $near->map_solarsystem_id && $signature->signature_type_id !== null);
        if ($farTyped instanceof Signature) {
            return;
        }

        $farId = $connection->from_map_solarsystem_id === $near->map_solarsystem_id ? $connection->to_map_solarsystem_id : $connection->from_map_solarsystem_id;
        $farSystem = $connection->from_map_solarsystem_id === $near->map_solarsystem_id ? $connection->toMapSolarsystem : $connection->fromMapSolarsystem;
        $nearClass = self::classOf($near->mapSolarsystem?->solarsystem);
        $farClass = self::classOf($farSystem?->solarsystem);
        if (! $nearClass instanceof SolarsystemClass || ! $farClass instanceof SolarsystemClass) {
            return;
        }

        $frigate = mb_strtolower((string) $near->signatureType?->extra) === 'frigate';
        $candidates = SignatureType::query()
            ->with('wormhole')
            ->where('signature_category_id', $near->signatureType?->signature_category_id)
            ->where('signature', '!=', 'K162')
            ->whereNull('extra')
            ->where('target_class', $nearClass->value)
            ->get()
            ->filter(fn (SignatureType $type): bool => in_array($farClass, $type->spawn_areas ?? [], true)
                && $type->wormhole !== null
                && ($type->wormhole->maximum_jump_mass <= 5_000_000) === $frigate)
            ->unique(fn (SignatureType $type): string => self::code($type))
            ->values();

        if ($candidates->count() !== 1) {
            return;
        }

        $this->connectionHoleTypeAction->setType($connection, $farId, $candidates->first());
    }

    private static function isK162(SignatureType $type): bool
    {
        return mb_strtoupper(self::code($type)) === 'K162';
    }

    private static function code(SignatureType $type): string
    {
        $signature = $type->signature;

        return $signature instanceof BackedEnum ? (string) $signature->value : (string) $signature;
    }

    private static function classOf(?Solarsystem $solarsystem): ?SolarsystemClass
    {
        if (! $solarsystem instanceof Solarsystem) {
            return null;
        }

        return $solarsystem->wormholeSystem?->class ?? SolarsystemClass::fromSecurity((float) $solarsystem->security);
    }
}
