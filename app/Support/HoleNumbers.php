<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MapSolarsystem;
use App\Models\Signature;

/**
 * Patch 39: hole numbers only count up per system. Each system remembers the highest
 * number it ever handed out (`hole_slot_high`), so a freed number stays retired until 9
 * is reached; then the gaps are refilled, then letters. Hubs (home, pinned systems, the
 * start of a side chain) refill at once: the browser decides that, this only remembers.
 */
final class HoleNumbers
{
    /** The non-static slots in order (as the browser's NUMERIC_SLOTS). */
    public const string SLOTS = '123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Note a hole's number as handed out by its system.
     */
    public static function remember(Signature $signature): void
    {
        $alias = $signature->alias;
        if ($alias === null || $alias === '') {
            return;
        }

        $system = MapSolarsystem::withHidden()->with('map:id,bookmark_ignored_alias')->find($signature->map_solarsystem_id);
        if (! $system instanceof MapSolarsystem) {
            return;
        }

        $slot = self::slotOf($system, $alias);
        if ($slot === null) {
            return;
        }

        $current = $system->hole_slot_high;
        if ($current !== null && mb_strpos(self::SLOTS, $current) !== false && mb_strpos(self::SLOTS, $current) >= mb_strpos(self::SLOTS, $slot)) {
            return;
        }

        MapSolarsystem::withHidden()->whereKey($system->id)->update(['hole_slot_high' => $slot]);
    }

    /**
     * The slot a hole's name takes in its system ("A12" in A1 → "2"), or null when the name
     * isn't one of its numbers (a loop name, home's letters, a static 0, a typed name).
     */
    public static function slotOf(MapSolarsystem $system, string $alias): ?string
    {
        $prefix = self::prefixOf($system);
        if ($prefix === null) {
            return null;
        }

        $name = mb_strtoupper(mb_trim(explode(ChainMemory::STAMP, $alias, 2)[0]));
        if (mb_strlen($name) !== mb_strlen($prefix) + 1 || ! str_starts_with($name, $prefix)) {
            return null;
        }

        $slot = mb_substr($name, -1);

        return str_contains(self::SLOTS, $slot) ? $slot : null;
    }

    /**
     * The chain prefix of a system's holes (as the browser's chainPrefix), or null for home
     * (it uses callsigns, always refilled).
     */
    private static function prefixOf(MapSolarsystem $system): ?string
    {
        $base = mb_trim(explode(ChainMemory::STAMP, (string) $system->alias, 2)[0]);
        $ignored = mb_trim((string) ($system->map->bookmark_ignored_alias ?? ''));
        if ($ignored !== '' && mb_strtolower($base) === mb_strtolower($ignored)) {
            return null;
        }
        if ($system->combat_home) {
            return '';
        }
        if (preg_match('/^[Tt]hera[0-9A-Z]*$/', $base) === 1) {
            return mb_strtoupper($base);
        }
        if ($base !== '' && (preg_match('/[^A-Za-z0-9-]/', $base) === 1 || preg_match('/^[A-Za-z]{3,}$/', $base) === 1)) {
            preg_match('/[A-Za-z]/', $base, $letter);

            return mb_strtoupper($letter[0] ?? 'X');
        }

        return mb_strtoupper($base);
    }
}
