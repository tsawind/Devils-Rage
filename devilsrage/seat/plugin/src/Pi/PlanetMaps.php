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

    /** A base map (kind "bare": the planet's own colours): "BAS1", width, height, then r, g, b a cell. */
    public const BASE_W = 2048;

    public const BASE_H = 1024;

    public const BASE_BYTES = 8 + self::BASE_W * self::BASE_H * 3;

    /** The biggest sky the app makes (three levels), with room to spare. */
    public const MAX_SKY_BYTES = 8_000_000;

    /** Why these bytes aren't a map of this kind, or null when they are. */
    public static function problem(string $kind, string $raw): ?string
    {
        return match ($kind) {
            'bare' => self::isBase($raw) ? null : 'That base map is not one the app made.',
            'sky' => strncmp($raw, 'SKY3', 4) === 0 && strlen($raw) > 6 && strlen($raw) <= self::MAX_SKY_BYTES ? null : 'That is not a sky map the app made.',
            default => 'Unknown kind of map.',
        };
    }

    private static function isBase(string $raw): bool
    {
        if (strlen($raw) !== self::BASE_BYTES || strncmp($raw, 'BAS1', 4) !== 0) {
            return false;
        }
        $size = unpack('vw/vh', substr($raw, 4, 4));

        return $size !== false && $size['w'] === self::BASE_W && $size['h'] === self::BASE_H;
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
