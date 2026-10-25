<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Patch 55: a pasted D-scan, counted by type ("4× Legion, 2× Loki, Fortizar…"). EVE copies one
 * object per line, tab-separated: [type id,] name, type, distance. Lines that aren't D-scan rows
 * are left out.
 */
final class DscanSummary
{
    /**
     * @return array{objects: int, types: list<array{type: string, count: int}>, on_grid: int}
     */
    public static function parse(string $text): array
    {
        $counts = [];
        $objects = 0;
        $onGrid = 0;
        foreach (preg_split('~\R~', $text) ?: [] as $line) {
            $columns = array_map('trim', explode("\t", $line));
            if (count($columns) >= 4 && ctype_digit($columns[0])) {
                array_shift($columns);
            }
            if (count($columns) < 3 || $columns[1] === '') {
                continue;
            }
            $objects++;
            $counts[$columns[1]] = ($counts[$columns[1]] ?? 0) + 1;
            if (self::onGrid($columns[2])) {
                $onGrid++;
            }
        }
        arsort($counts);
        $types = [];
        foreach (array_slice($counts, 0, 40, true) as $type => $count) {
            $types[] = ['type' => (string) $type, 'count' => $count];
        }

        return ['objects' => $objects, 'types' => $types, 'on_grid' => $onGrid];
    }

    /** Within about 8,000 km ("2,500 m", "7,800 km"), not AU or "-". */
    private static function onGrid(string $distance): bool
    {
        if (! preg_match('~^([\d.,\s]+)\s*(m|km)$~i', $distance, $m)) {
            return false;
        }
        $value = (float) str_replace([',', ' ', "\u{00a0}"], '', $m[1]);

        return strtolower($m[2]) === 'm' || $value <= 8000;
    }
}
