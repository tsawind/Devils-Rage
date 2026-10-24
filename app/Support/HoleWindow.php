<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Patch 52: the hole's real size from what people saw (the same math as
 * resources/js/lib/holeWindow.ts). A hole is 90–110% of its nominal size; each status seen
 * with the mass logged then narrows that. Only holds when every jump was logged.
 */
final class HoleWindow
{
    /**
     * @param  iterable<array{status: string, logged: int|float, flip: int|float|null}>  $observations
     * @return array{low: float, high: float, consistent: bool}
     */
    public static function size(float $nominal, iterable $observations): array
    {
        $low = $nominal * 0.9;
        $high = $nominal * 1.1;
        foreach ($observations as $seen) {
            $p = (float) $seen['logged'];
            $flip = $seen['flip'] === null ? null : (float) $seen['flip'];
            if ($seen['status'] === 'fresh') {
                $low = max($low, 2 * $p);
            } elseif ($seen['status'] === 'reduced') {
                $high = min($high, 2 * $p);
                $low = max($low, $p / 0.9);
                if ($flip) {
                    $low = max($low, 2 * ($p - $flip));
                }
            } else {
                $high = min($high, $p / 0.9);
                $low = max($low, $p);
                if ($flip) {
                    $low = max($low, ($p - $flip) / 0.9);
                }
            }
        }

        return ['low' => $low, 'high' => $high, 'consistent' => $low <= $high];
    }
}
