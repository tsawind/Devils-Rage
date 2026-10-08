<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\MapRageRoll\NewStaticAction;
use App\Models\Map;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Patch 36: chain memory. Clears hide systems instead of deleting them; a
 * hidden system never holds a name (its alias gets a "@hhmm" stamp), comes
 * back as it was when someone reconnects to it within 27 h, and loses its
 * name after that. Every number of the plan lives here.
 */
final class ChainMemory
{
    /** A wormhole signature is deleted this long after it was created. */
    public const int WORMHOLE_SIGNATURE_LIFETIME_HOURS = 27;

    /** A hidden system keeps its name (and comes back as it was) this long. */
    public const int HIDDEN_NAME_KEPT_HOURS = 27;

    /** Other signatures (data, relic, gas, ore, combat…) are kept this long. */
    public const int SIGNATURE_LIFETIME_DAYS = 30;

    /**
     * The name without its stamp: "A1@1958" → "A1".
     */
    public static function plainAlias(?string $alias): ?string
    {
        if ($alias === null) {
            return null;
        }

        $plain = mb_trim(explode('@', $alias, 2)[0]);

        return $plain === '' ? null : $plain;
    }

    public static function isStamped(?string $alias): bool
    {
        return $alias !== null && str_contains($alias, '@');
    }

    /**
     * Systems that are never hidden by an automatic hide (cleanup, rage roll):
     * the map's home, pinned systems, the rally point, the system being rage
     * rolled and rage (combat) homes.
     */
    public static function isProtected(Map $map, MapSolarsystem $system): bool
    {
        return self::isAlwaysKept($map, $system)
            || $system->combat_home
            || ($map->rally_solarsystem_id !== null && $map->rally_solarsystem_id === $system->solarsystem_id)
            || ($map->rage_roll_solarsystem_id !== null && $map->rage_roll_solarsystem_id === $system->solarsystem_id);
    }

    /**
     * Systems no clear ever hides: the map's home and pinned systems.
     */
    public static function isAlwaysKept(Map $map, MapSolarsystem $system): bool
    {
        return $system->pinned || $map->home_solarsystem_id === $system->solarsystem_id;
    }

    /**
     * The stamp every system about to be hidden gets: the EVE time (UTC, "Hi")
     * its chain's first system was added to the map. A system's chain start is
     * the system with the shortest name it lies under ("A" for "A12"), looked
     * for among the systems being hidden and the rest of the map.
     *
     * @param  Collection<int, MapSolarsystem>  $hiding
     * @param  Collection<int, MapSolarsystem>  $others  the map's other (visible) systems
     * @return array<int, string> map solarsystem id → stamp ("1958")
     */
    public static function stampsFor(Collection $hiding, Collection $others): array
    {
        $candidates = $hiding->merge($others)
            ->filter(fn (MapSolarsystem $system): bool => $system->alias !== null && ! self::isStamped($system->alias))
            ->sortBy(fn (MapSolarsystem $system): int => mb_strlen((string) $system->alias))
            ->values();

        $stamps = [];
        foreach ($hiding as $system) {
            if ($system->alias === null || self::isStamped($system->alias)) {
                continue;
            }

            $name = mb_strtoupper(mb_trim($system->alias));
            $root = $candidates->first(fn (MapSolarsystem $candidate): bool => NewStaticAction::isInChain($name, mb_strtoupper(mb_trim((string) $candidate->alias)))) ?? $system;

            $stamps[$system->id] = self::stampOf($root);
        }

        return $stamps;
    }

    public static function stampOf(MapSolarsystem $system): string
    {
        return $system->created_at->utc()->format('Hi');
    }

    /**
     * Whether a plain name is free on the map: no visible system holds it, and
     * no unjumped hole in a visible system has it locked.
     */
    public static function isNameFree(int $map_id, string $plain, ?int $except_id = null): bool
    {
        $name = mb_strtoupper($plain);

        $taken_by_system = MapSolarsystem::query()
            ->where('map_id', $map_id)
            ->when($except_id !== null, fn ($query) => $query->whereKeyNot($except_id))
            ->whereRaw('UPPER(alias) = ?', [$name])
            ->exists();

        if ($taken_by_system) {
            return false;
        }

        return ! Signature::query()
            ->whereNull('map_connection_id')
            ->whereRaw('UPPER(alias) = ?', [$name])
            ->whereHas('mapSolarsystem', fn ($query) => $query
                ->where('map_id', $map_id)
                ->when($except_id !== null, fn ($inner) => $inner->whereKeyNot($except_id)))
            ->exists();
    }

    /**
     * Bring a hidden system back onto the map, as it was: within 27 h it keeps
     * its name (the stamp drops when the plain name is still free); later it
     * comes back with no name and gets a fresh one.
     *
     * @return bool whether it came back with its old name
     */
    public static function unhide(MapSolarsystem $system): bool
    {
        if (! $system->isHidden()) {
            return $system->alias !== null;
        }

        $kept_name = $system->hidden_at !== null
            && $system->hidden_at->greaterThan(CarbonImmutable::now()->subHours(self::HIDDEN_NAME_KEPT_HOURS));

        $alias = $kept_name ? $system->alias : null;
        $stamp = self::isStamped($system->alias) ? explode('@', (string) $system->alias, 2)[1] : null;
        $plain = self::plainAlias($alias);

        if ($kept_name && $stamp !== null && $plain !== null && self::isNameFree($system->map_id, $plain, $system->id)) {
            $alias = $plain;
            self::unstampSignatures($system, $stamp);
        }

        if (! $kept_name) {
            self::forgetSignatureNames($system);
        }

        $system->forceFill(['hidden_at' => null, 'alias' => $alias])->save();

        return $alias !== null;
    }

    /**
     * Numbers locked on a hidden system's holes lose their stamp with it.
     */
    private static function unstampSignatures(MapSolarsystem $system, string $stamp): void
    {
        Signature::query()
            ->where('map_solarsystem_id', $system->id)
            ->where('alias', 'like', '%@'.$stamp)
            ->get(['id', 'alias'])
            ->each(fn (Signature $signature) => $signature->update(['alias' => self::plainAlias($signature->alias)]));
    }

    /**
     * After 27 h hidden the names go: numbers locked on its holes too.
     */
    public static function forgetSignatureNames(MapSolarsystem $system): void
    {
        Signature::query()
            ->where('map_solarsystem_id', $system->id)
            ->where('alias', 'like', '%@%')
            ->update(['alias' => null]);
    }
}
