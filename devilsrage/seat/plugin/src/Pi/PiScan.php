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
    /**
     * EVE's resource fields are smooth, so a lone cell far from the cells round it (5 × 5) is a
     * speck (glowing lava shining through the scan colours): it takes their median.
     */
    public static function despeckle(string $bytes, float $limit = 0.2): string
    {
        $out = $bytes;
        $lim = (int) round($limit * 250);
        for ($row = 0; $row < self::GRID_H; $row++) {
            for ($col = 0; $col < self::GRID_W; $col++) {
                $here = ord($bytes[$row * self::GRID_W + $col]);
                if ($here === self::UNSEEN) {
                    continue;
                }
                $near = [];
                for ($dr = -2; $dr <= 2; $dr++) {
                    $r = $row + $dr;
                    if ($r < 0 || $r >= self::GRID_H) {
                        continue;
                    }
                    for ($dc = -2; $dc <= 2; $dc++) {
                        $v = ord($bytes[$r * self::GRID_W + (($col + $dc + self::GRID_W) % self::GRID_W)]);
                        if ($v !== self::UNSEEN) {
                            $near[] = $v;
                        }
                    }
                }
                if (count($near) < 9) {
                    continue;
                }
                sort($near);
                $median = $near[intdiv(count($near), 2)];
                if (abs($here - $median) > $lim) {
                    $out[$row * self::GRID_W + $col] = chr($median);
                }
            }
        }

        return $out;
    }

    /**
     * The mean heat 0 … 1 within `radius` (radians on the planet) of an EVE position, over cells
     * seen in both maps when `other` is given (so two dates compare like for like).
     *
     * @return array{heat: float|null, cells: int}
     */
    public static function meanAround(string $bytes, float $lat, float $lon, float $radius, ?string $other = null): array
    {
        $centre = [sin($lat) * cos($lon), sin($lat) * sin($lon), cos($lat)];
        $sum = 0.0;
        $n = 0;
        $rows = (int) ceil($radius / M_PI * self::GRID_H) + 1;
        $mid = (int) floor($lat / M_PI * self::GRID_H);
        for ($row = max(0, $mid - $rows); $row <= min(self::GRID_H - 1, $mid + $rows); $row++) {
            $la = ($row + 0.5) / self::GRID_H * M_PI;
            for ($col = 0; $col < self::GRID_W; $col++) {
                $lo = ($col + 0.5) / self::GRID_W * 2 * M_PI;
                $dot = sin($la) * cos($lo) * $centre[0] + sin($la) * sin($lo) * $centre[1] + cos($la) * $centre[2];
                if (acos(max(-1.0, min(1.0, $dot))) > $radius) {
                    continue;
                }
                $i = $row * self::GRID_W + $col;
                $v = ord($bytes[$i]);
                if ($v === self::UNSEEN || ($other !== null && ord($other[$i]) === self::UNSEEN)) {
                    continue;
                }
                $sum += ($v - 1) / 250;
                $n++;
            }
        }

        return ['heat' => $n > 0 ? $sum / $n : null, 'cells' => $n];
    }
}
