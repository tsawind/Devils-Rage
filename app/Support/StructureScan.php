<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Patch 57: structures and POS in text copied from EVE: a D-scan ([type id,] name, type,
 * distance) or overview rows (the columns a pilot shows: distance, name, type, …, corp ticker).
 * Each row whose type is a structure we track gives its kind, type, name, owner ticker and
 * distance. Anything else (ships, customs offices, chat) is left out.
 */
final class StructureScan
{
    /** Type names (lower case) → our kind. Faction Fortizars and the Upwell Keepstar count as their hull. */
    private const array TYPES = [
        'astrahus' => 'astrahus',
        'fortizar' => 'fortizar',
        "'moreau' fortizar" => 'fortizar',
        "'draccous' fortizar" => 'fortizar',
        "'horizon' fortizar" => 'fortizar',
        "'marginis' fortizar" => 'fortizar',
        "'prometheus' fortizar" => 'fortizar',
        'keepstar' => 'keepstar',
        'upwell palatine keepstar' => 'keepstar',
        'raitaru' => 'raitaru',
        'azbel' => 'azbel',
        'sotiyo' => 'sotiyo',
        'athanor' => 'athanor',
        'tatara' => 'tatara',
    ];

    /**
     * @return list<array{kind: string, type: string, name: string|null, owner: string|null, distance: string|null}>
     */
    public static function parse(string $text): array
    {
        $found = [];
        foreach (preg_split('~\R~', $text) ?: [] as $line) {
            if (! str_contains($line, "\t")) {
                continue;
            }
            $columns = array_map(fn (string $column): string => mb_trim($column), explode("\t", $line));
            $typeAt = null;
            $kind = '';
            foreach ($columns as $index => $column) {
                $kindOfColumn = self::kindOf($column);
                if ($kindOfColumn !== null) {
                    $typeAt = $index;
                    $kind = $kindOfColumn;
                    break;
                }
            }
            if ($typeAt === null) {
                continue;
            }

            $name = null;
            $owner = null;
            $distance = null;
            foreach ($columns as $index => $column) {
                if ($index === $typeAt || $column === '' || $column === '-') {
                    continue;
                }
                if ($distance === null && self::isDistance($column)) {
                    $distance = $column;
                } elseif ($owner === null && preg_match('~^\[([A-Z0-9 .\-_]{1,5})\]$~i', $column, $m)) {
                    $owner = mb_strtoupper(mb_trim($m[1]));
                } elseif ($name === null && $index < $typeAt && ! ctype_digit($column) && ! self::isNumber($column)) {
                    $name = $column;
                }
            }

            $key = $kind.'|'.mb_strtolower((string) $name);
            $found[$key] ??= ['kind' => $kind, 'type' => $columns[$typeAt], 'name' => $name, 'owner' => $owner, 'distance' => $distance];
        }

        return array_values($found);
    }

    public static function kindOf(string $type): ?string
    {
        $lower = mb_strtolower(mb_trim($type));
        if (isset(self::TYPES[$lower])) {
            return self::TYPES[$lower];
        }

        return str_contains($lower, 'control tower') ? 'pos' : null;
    }

    private static function isDistance(string $value): bool
    {
        return preg_match('~^[\d.,\s\x{00a0}]+\s*(m|km|au)$~iu', $value) === 1;
    }

    private static function isNumber(string $value): bool
    {
        return preg_match('~^[\d.,\s\x{00a0}%+-]+$~u', $value) === 1;
    }
}
