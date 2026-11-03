<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Pi;

/**
 * Patch 71: planet resource scans from the desktop app's video scan.
 *
 * The app watches the planet window while the pilot rotates it, and paints the scan colours onto
 * a flat whole-planet map: GRID_W × GRID_H cells (one degree each), row 0 at the north pole,
 * column 0 at longitude 0, the same way EVE gives building positions (latitude 0 … π from the
 * pole, longitude 0 … 2π). Each cell is one byte: 0 = not seen yet, 1 … 251 = heat 0 … 1.
 *
 * Pure: decode, merge a rescan into what we had, coverage, heat at a spot.
 */
final class PiScan
{
    public const GRID_W = 360;

    public const GRID_H = 180;

    public const UNSEEN = 0;

    /** The cells as raw bytes, or null when it isn't a whole grid. */
    public static function decode(string $base64): ?string
    {
        $bytes = base64_decode($base64, true);
        if ($bytes === false || strlen($bytes) !== self::GRID_W * self::GRID_H) {
            return null;
        }
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            if (ord($bytes[$i]) > 251) {
                return null;
            }
        }

        return $bytes;
    }

    /** A rescan adds to the old map: cells seen now win, cells not seen now keep the old reading. */
    public static function merge(?string $old, string $new): string
    {
        if ($old === null || strlen($old) !== strlen($new)) {
            return $new;
        }
        $out = $new;
        for ($i = 0, $n = strlen($new); $i < $n; $i++) {
            if (ord($new[$i]) === self::UNSEEN) {
                $out[$i] = $old[$i];
            }
        }

        return $out;
    }

    /** Share of the planet's surface seen (cells weighted by their real size: small near the poles). */
    public static function coverage(string $bytes): float
    {
        $seen = 0.0;
        $all = 0.0;
        for ($row = 0; $row < self::GRID_H; $row++) {
            $weight = sin(($row + 0.5) / self::GRID_H * M_PI);
            for ($col = 0; $col < self::GRID_W; $col++) {
                $all += $weight;
                if (ord($bytes[$row * self::GRID_W + $col]) !== self::UNSEEN) {
                    $seen += $weight;
                }
            }
        }

        return $all > 0 ? $seen / $all : 0.0;
    }

    /** Heat 0 … 1 at an EVE position, or null where the scan hasn't seen yet. */
    public static function heatAt(string $bytes, float $lat, float $lon): ?float
    {
        $row = (int) min(self::GRID_H - 1, max(0, floor($lat / M_PI * self::GRID_H)));
        $lon = fmod(fmod($lon, 2 * M_PI) + 2 * M_PI, 2 * M_PI);
        $col = (int) min(self::GRID_W - 1, floor($lon / (2 * M_PI) * self::GRID_W));
        $value = ord($bytes[$row * self::GRID_W + $col]);

        return $value === self::UNSEEN ? null : ($value - 1) / 250;
    }

    /** The byte for a heat 0 … 1. */
    public static function encodeHeat(float $heat): int
    {
        return 1 + (int) round(max(0.0, min(1.0, $heat)) * 250);
    }
}
