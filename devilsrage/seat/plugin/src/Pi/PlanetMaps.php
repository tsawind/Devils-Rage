<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Pi;

/**
 * The planet scan (remake): checks on the maps the app hands in, and planet types from the SDE.
 * Plain PHP (tested by tests/pi-planet-maps-test.php).
 */
final class PlanetMaps
{
    public const KINDS = ['sky', 'bare'];

    /** A bare map: r, g, b for each of 360 × 180 cells. */
    public const BARE_BYTES = 360 * 180 * 3;

    /** The biggest sky the app makes (three levels), with room to spare. */
    public const MAX_SKY_BYTES = 8_000_000;

    /** Why these bytes aren't a map of this kind, or null when they are. */
    public static function problem(string $kind, string $raw): ?string
    {
        return match ($kind) {
            'bare' => strlen($raw) === self::BARE_BYTES ? null : 'That bare map is the wrong size.',
            'sky' => strncmp($raw, 'SKY2', 4) === 0 && strlen($raw) > 6 && strlen($raw) <= self::MAX_SKY_BYTES ? null : 'That is not a sky map the app made.',
            default => 'Unknown kind of map.',
        };
    }

    /** "Planet (Gas)" → "gas" (the same words as character_planets.planet_type). */
    public static function typeFromName(string $typeName): string
    {
        return preg_match('/\(([^)]+)\)/', $typeName, $m) === 1 ? strtolower(trim($m[1])) : strtolower(trim($typeName));
    }

    /**
     * Pieces of one upload put back together, or null while any is missing.
     *
     * @param array<int, string|null> $pieces part number => base64 text
     */
    public static function assemble(array $pieces, int $parts): ?string
    {
        $out = '';
        for ($i = 0; $i < $parts; $i++) {
            if (! isset($pieces[$i])) {
                return null;
            }
            $out .= $pieces[$i];
        }

        return $out;
    }
}
