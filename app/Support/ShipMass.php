<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Type;

/**
 * Patch 37: a ship's jump mass from its fit.
 * - cold (prop off) = hull + plates (mass additions of everything but the prop mod);
 * - hot (prop on) = cold + the prop mod's mass addition;
 * - a Higgs Anchor rig doubles all of it (hull, plates and prop);
 * - HICs and the Odysseus count with their Zero-Point Mass Entangler on (20% of the hull),
 *   as in the jump log.
 */
final class ShipMass
{
    /**
     * @return array{cold: int, hot: int}
     */
    public static function fromFit(int $hullTypeId, bool $higgs, float $plateMass, float $propMass): array
    {
        $hull = (float) Type::loggedJumpMass($hullTypeId);
        $factor = $higgs ? 2.0 : 1.0;
        $cold = ($hull + $plateMass) * $factor;
        $hot = $cold + $propMass * $factor;

        return ['cold' => (int) round($cold), 'hot' => (int) round($hot)];
    }

    /**
     * Without a known fit: the bare hull cold, and hot with a typical prop mod for its size
     * (50m for a battleship-sized hull, 5m for cruisers, 0.5m for frigates; none for caps).
     *
     * @return array{cold: int, hot: int}
     */
    public static function bareHull(int $hullTypeId): array
    {
        $hull = Type::loggedJumpMass($hullTypeId);
        $rawMass = (float) (Type::query()->whereKey($hullTypeId)->value('mass') ?? 0);
        $prop = match (true) {
            $rawMass >= 500_000_000 => 0,
            $rawMass >= 50_000_000 => 50_000_000,
            $rawMass >= 5_000_000 => 5_000_000,
            default => 500_000,
        };

        return ['cold' => $hull, 'hot' => $hull + $prop];
    }
}
