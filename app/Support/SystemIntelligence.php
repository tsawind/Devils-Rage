<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Alliance;
use App\Models\Character;
use App\Models\Corporation;
use App\Models\IntelStructure;
use App\Models\Killmail;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\Type;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Patch 58: who lives in a system, who hunts there and who only passed through, worked out from
 * the kills the mapper keeps (90 days) and the map's own intel, never counting "us" (the corps
 * and alliances with access to the map). Groups are alliances, else corporations.
 *
 * - Lives here: seen on 4+ days with a home sign (lost a structure, miner, hauler or Orca here,
 *   flew capitals here, or owns a structure in the map's intel), or 8+ days with 3+ losses.
 * - Lived here: a resident that lost its structure here and was not seen after (evicted?).
 * - Hunts here: kills here on 2+ days, no more losses than kills, no home sign.
 * - Passed through: everyone else.
 *
 * Hours, fleet sizes and ships come from the residents, else from all hostiles.
 */
final class SystemIntelligence
{
    public const int DAYS = 90;

    /** Upwell structures and starbases (POS towers and their modules). */
    private const array STRUCTURE_CATEGORIES = [65, 23];

    /** Titan, dreadnought, carrier, supercarrier, force auxiliary, Rorqual, lancer dreadnought. */
    private const array CAPITAL_GROUPS = [30, 485, 547, 659, 1538, 883, 4594];

    /** Mining barges, exhumers, Orca / Porpoise, expedition frigates, haulers, DSTs, blockade runners, freighters. */
    private const array HOME_SHIP_GROUPS = [463, 543, 941, 1283, 28, 380, 1202, 513, 902, 883];

    /** Venture (a frigate by group, a miner by use). */
    private const array HOME_SHIP_TYPES = [32880];

    private const int CAPSULE_GROUP = 29;

    /**
     * @return array<string, mixed>
     */
    public static function for(int $solarsystemId, ?Map $map = null): array
    {
        $since = CarbonImmutable::now()->subDays(self::DAYS);
        /** @var Collection<int, Killmail> $kills */
        $kills = Killmail::query()
            ->where('solarsystem_id', $solarsystemId)
            ->where('time', '>=', $since)
            ->orderBy('time')
            ->limit(3000)
            ->get(['id', 'time', 'data', 'zkb']);

        [$friendlyCorps, $friendlyAlliances] = $map instanceof Map ? self::friendlies($map) : [[], []];

        $rows = [];
        $typeIds = [];
        foreach ($kills as $kill) {
            if ((bool) data_get($kill->zkb, 'npc', false)) {
                continue;
            }
            $data = json_decode((string) json_encode($kill->data), true) ?: [];
            $time = $kill->time instanceof DateTimeInterface ? CarbonImmutable::instance($kill->time) : CarbonImmutable::parse((string) $kill->time);
            $victim = (array) ($data['victim'] ?? []);
            $attackers = array_values(array_filter((array) ($data['attackers'] ?? []), fn (mixed $attacker): bool => is_array($attacker) && isset($attacker['character_id'])));
            $rows[] = ['id' => (int) $kill->id, 'time' => $time, 'victim' => $victim, 'attackers' => $attackers];
            if (isset($victim['ship_type_id'])) {
                $typeIds[(int) $victim['ship_type_id']] = true;
            }
            foreach ($attackers as $attacker) {
                if (isset($attacker['ship_type_id'])) {
                    $typeIds[(int) $attacker['ship_type_id']] = true;
                }
            }
        }

        $types = Type::query()
            ->whereKey(array_keys($typeIds))
            ->with('group:id,category_id')
            ->get(['id', 'name', 'group_id'])
            ->keyBy('id');

        $entityOf = function (array $who): ?array {
            $alliance = (int) ($who['alliance_id'] ?? 0);
            if ($alliance > 0) {
                return ['alliance', $alliance];
            }
            $corporation = (int) ($who['corporation_id'] ?? 0);

            // NPC corporations (new pilots, pirates) aren't a group that lives anywhere.
            return $corporation >= 2_000_000 ? ['corporation', $corporation] : null;
        };
        $isUs = fn (array $who): bool => in_array((int) ($who['alliance_id'] ?? 0), $friendlyAlliances, true)
            || in_array((int) ($who['corporation_id'] ?? 0), $friendlyCorps, true);

        $entities = [];
        $us = ['kills' => 0, 'losses' => 0];
        $touch = function (string $key, array $entity, CarbonImmutable $time) use (&$entities): void {
            $entities[$key] ??= [
                'type' => $entity[0], 'id' => $entity[1], 'days' => [], 'kills' => 0, 'losses' => 0,
                'first' => $time, 'last' => $time, 'times' => [], 'signs' => [], 'capitals' => 0,
                'structure_lost_at' => null, 'structure_lost' => null, 'killed_structures' => 0, 'ships' => [], 'fleets' => [],
            ];
            $entities[$key]['days'][$time->toDateString()] = true;
            $entities[$key]['last'] = $time;
            $entities[$key]['times'][] = $time;
        };

        foreach ($rows as $row) {
            $time = $row['time'];
            $victimType = $types[(int) ($row['victim']['ship_type_id'] ?? 0)] ?? null;
            $victimGroup = $victimType?->group_id;
            $victimCategory = $victimType?->group?->category_id;
            $isStructure = in_array($victimCategory, self::STRUCTURE_CATEGORIES, true);

            if ($isUs($row['victim'])) {
                $us['losses']++;
            } elseif (($entity = $entityOf($row['victim'])) !== null) {
                $key = $entity[0].':'.$entity[1];
                $touch($key, $entity, $time);
                $entities[$key]['losses']++;
                if ($victimGroup !== self::CAPSULE_GROUP && $victimType !== null) {
                    $entities[$key]['ships'][$victimType->name] = ($entities[$key]['ships'][$victimType->name] ?? 0) + 1;
                }
                if ($isStructure) {
                    $entities[$key]['signs']['lost '.$victimType->name] = ($entities[$key]['signs']['lost '.$victimType->name] ?? 0) + 1;
                    $entities[$key]['structure_lost_at'] = $time;
                    $entities[$key]['structure_lost'] = $victimType->name;
                } elseif ($victimType !== null && (in_array($victimGroup, self::HOME_SHIP_GROUPS, true) || in_array($victimType->id, self::HOME_SHIP_TYPES, true))) {
                    $entities[$key]['signs']['lost '.$victimType->name] = ($entities[$key]['signs']['lost '.$victimType->name] ?? 0) + 1;
                }
                if (in_array($victimGroup, self::CAPITAL_GROUPS, true)) {
                    $entities[$key]['capitals']++;
                }
            }

            $ourAttack = false;
            $perEntity = [];
            foreach ($row['attackers'] as $attacker) {
                if ($isUs($attacker)) {
                    $ourAttack = true;

                    continue;
                }
                $entity = $entityOf($attacker);
                if ($entity === null) {
                    continue;
                }
                $key = $entity[0].':'.$entity[1];
                $perEntity[$key] = ($perEntity[$key] ?? 0) + 1;
                if (! isset($entities[$key]) || end($entities[$key]['times']) !== $time) {
                    $touch($key, $entity, $time);
                }
                $shipType = $types[(int) ($attacker['ship_type_id'] ?? 0)] ?? null;
                if ($shipType !== null && $shipType->group_id !== self::CAPSULE_GROUP) {
                    $entities[$key]['ships'][$shipType->name] = ($entities[$key]['ships'][$shipType->name] ?? 0) + 1;
                    if (in_array($shipType->group_id, self::CAPITAL_GROUPS, true)) {
                        $entities[$key]['capitals']++;
                    }
                }
            }
            foreach ($perEntity as $key => $count) {
                $entities[$key]['kills']++;
                $entities[$key]['fleets'][] = ['size' => $count, 'at' => $time];
                if ($isStructure) {
                    $entities[$key]['killed_structures']++;
                }
            }
            if ($ourAttack) {
                $us['kills']++;
            }
        }

        $names = self::names(array_values($entities));
        $owners = self::structureOwners($map, $solarsystemId);

        $now = CarbonImmutable::now();
        $residents = [];
        $hunters = [];
        $passers = [];
        foreach ($entities as $key => $entity) {
            $name = $names[$key]['name'] ?? ucfirst($entity['type']).' '.$entity['id'];
            $ticker = $names[$key]['ticker'] ?? null;
            $days = count($entity['days']);
            $signs = [];
            foreach ($entity['signs'] as $what => $count) {
                $signs[] = $count > 1 ? preg_replace('~^lost ~', "lost {$count}× ", $what) : $what;
            }
            if ($entity['capitals'] > 0) {
                $signs[] = 'flew capitals';
            }
            $ownsStructure = $owners->first(fn (string $owner): bool => $owner === mb_strtolower($name) || ($ticker !== null && $owner === mb_strtolower($ticker)));
            if ($ownsStructure !== null) {
                $signs[] = 'owns a structure (your intel)';
            }
            arsort($entity['ships']);
            $ships = array_slice(array_keys($entity['ships']), 0, 4);
            $base = [
                'id' => $entity['id'],
                'type' => $entity['type'],
                'name' => $name,
                'days' => $days,
                'kills' => $entity['kills'],
                'losses' => $entity['losses'],
                'first_seen' => $entity['first']->toIso8601String(),
                'last_seen' => $entity['last']->toIso8601String(),
                'ships' => $ships,
            ];

            $resident = ($days >= 4 && $signs !== []) || ($days >= 8 && $entity['losses'] >= 3) || $ownsStructure !== null;
            if ($resident) {
                $lostAt = $entity['structure_lost_at'];
                $evicted = $lostAt instanceof CarbonImmutable && $entity['last']->lte($lostAt->addDay()) && $ownsStructure === null;
                $residents[] = [...$base,
                    'role' => $evicted ? 'former' : 'resident',
                    'confidence' => ($days >= 8 || count($signs) >= 2 || $ownsStructure !== null) ? 'sure' : 'likely',
                    'evidence' => $evicted
                        ? sprintf('lost its %s on %s, nothing since', $entity['structure_lost'], $lostAt->format('M j'))
                        : implode(' · ', array_filter([sprintf('%d of %d days', $days, self::DAYS), ...$signs, $ships === [] ? null : 'flies '.implode(', ', array_slice($ships, 0, 3))])),
                    'times' => $entity['times'],
                    'fleets' => $entity['fleets'],
                    'shipCounts' => $entity['ships'],
                ];
            } elseif ($entity['kills'] >= 1 && $days >= 2 && $entity['losses'] <= $entity['kills']) {
                $hunters[] = [...$base, 'killed_structures' => $entity['killed_structures'] > 0, 'times' => $entity['times'], 'fleets' => $entity['fleets'], 'shipCounts' => $entity['ships']];
            } else {
                $passers[] = [...$base, 'killed_structures' => $entity['killed_structures'] > 0, 'times' => $entity['times'], 'fleets' => $entity['fleets'], 'shipCounts' => $entity['ships']];
            }
        }
        usort($residents, fn (array $a, array $b): int => [$a['role'] === 'resident', $b['last_seen']] <=> [$b['role'] === 'resident', $a['last_seen']]);
        usort($hunters, fn (array $a, array $b): int => [$b['days'], $b['kills']] <=> [$a['days'], $a['kills']]);
        usort($passers, fn (array $a, array $b): int => $b['last_seen'] <=> $a['last_seen']);

        $living = array_values(array_filter($residents, fn (array $resident): bool => $resident['role'] === 'resident'));
        $basis = $living !== [] ? $living : [...$residents, ...$hunters, ...$passers];
        $hours = array_fill(0, 24, 0);
        $fleets = [];
        $shipCounts = [];
        foreach ($basis as $entity) {
            foreach ($entity['times'] as $time) {
                $hours[(int) $time->format('G')]++;
            }
            array_push($fleets, ...$entity['fleets']);
            foreach ($entity['shipCounts'] as $ship => $count) {
                $shipCounts[$ship] = ($shipCounts[$ship] ?? 0) + $count;
            }
        }
        usort($fleets, fn (array $a, array $b): int => $a['size'] <=> $b['size']);
        arsort($shipCounts);

        $hostiles = [...$residents, ...$hunters, ...$passers];
        $lastHostile = array_reduce($hostiles, fn (?string $carry, array $entity): string => $carry === null || $entity['last_seen'] > $carry ? $entity['last_seen'] : $carry);
        $weekAgo = $now->subDays(7);
        $week = ['kills' => 0, 'losses' => 0, 'days' => []];
        foreach ($rows as $row) {
            if ($row['time']->lt($weekAgo)) {
                continue;
            }
            $hostileVictim = ! $isUs($row['victim']) && $entityOf($row['victim']) !== null;
            $hostileAttack = array_filter($row['attackers'], fn (array $attacker): bool => ! $isUs($attacker) && $entityOf($attacker) !== null) !== [];
            if ($hostileAttack) {
                $week['kills']++;
            }
            if ($hostileVictim) {
                $week['losses']++;
            }
            if ($hostileAttack || $hostileVictim) {
                $week['days'][$row['time']->toDateString()] = true;
            }
        }

        $strip = fn (array $entity): array => array_diff_key($entity, ['times' => true, 'fleets' => true, 'shipCounts' => true]);

        return [
            'kills' => count($rows),
            'since' => $since->toDateString(),
            'status' => self::status($living, $residents, $hunters, $lastHostile, $now),
            'capitals' => array_filter($entities, fn (array $entity): bool => $entity['capitals'] > 0) !== [],
            'last_hostile_at' => $lastHostile,
            'week' => ['kills' => $week['kills'], 'losses' => $week['losses'], 'days' => count($week['days'])],
            'us' => $friendlyCorps === [] && $friendlyAlliances === [] ? null : ['kills' => $us['kills'], 'losses' => $us['losses']],
            'residents' => array_map($strip, $residents),
            'hunters' => array_map($strip, array_slice($hunters, 0, 8)),
            'passers' => array_map($strip, array_slice($passers, 0, 12)),
            'basis' => $living !== [] ? 'residents' : 'hostiles',
            'hours' => $hours,
            'peak' => SystemAssessment::peak($hours),
            'quiet' => self::quiet($hours),
            'fleet' => $fleets === [] ? null : [
                'median' => $fleets[intdiv(count($fleets), 2)]['size'],
                'low' => $fleets[intdiv(count($fleets), 4)]['size'],
                'high' => $fleets[intdiv(count($fleets) * 3, 4)]['size'],
                'max' => end($fleets)['size'],
                'max_at' => end($fleets)['at']->toIso8601String(),
            ],
            'ships' => array_map(fn (string $ship, int $count): array => ['name' => $ship, 'count' => $count], array_keys(array_slice($shipCounts, 0, 8, true)), array_slice($shipCounts, 0, 8, true)),
        ];
    }

    /**
     * The corporations and alliances that may use this map (not just a visitor's timed access).
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    public static function friendlies(Map $map): array
    {
        $corporations = [];
        $alliances = [];
        $accesses = MapAccess::query()
            ->where('map_id', $map->id)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with('accessible')
            ->get();
        foreach ($accesses as $access) {
            if ($access->expires_at !== null) {
                continue;
            }
            $accessible = $access->accessible;
            if ($accessible instanceof Alliance) {
                $alliances[] = (int) $accessible->id;
            } elseif ($accessible instanceof Corporation) {
                $corporations[] = (int) $accessible->id;
            } elseif ($accessible instanceof Character) {
                $corporations[] = (int) $accessible->corporation_id;
                if ($accessible->alliance_id) {
                    $alliances[] = (int) $accessible->alliance_id;
                }
            }
        }

        return [array_values(array_unique($corporations)), array_values(array_unique($alliances))];
    }

    /**
     * @param  list<array{type: string, id: int}>  $entities
     * @return array<string, array{name: string, ticker: string|null}>
     */
    private static function names(array $entities): array
    {
        $byType = ['alliance' => [], 'corporation' => []];
        foreach ($entities as $entity) {
            $byType[$entity['type']][] = $entity['id'];
        }
        $names = [];
        foreach (Alliance::query()->whereKey($byType['alliance'])->get(['id', 'name', 'ticker']) as $alliance) {
            $names['alliance:'.$alliance->id] = ['name' => (string) $alliance->name, 'ticker' => $alliance->ticker ?? null];
        }
        foreach (Corporation::query()->whereKey($byType['corporation'])->get(['id', 'name', 'ticker']) as $corporation) {
            $names['corporation:'.$corporation->id] = ['name' => (string) $corporation->name, 'ticker' => $corporation->ticker ?? null];
        }

        return $names;
    }

    /**
     * Owners written on the map's structures in this system, lower case.
     *
     * @return Collection<int, lowercase-string>
     */
    private static function structureOwners(?Map $map, int $solarsystemId): Collection
    {
        if (! $map instanceof Map) {
            return collect();
        }

        return IntelStructure::query()
            ->where('map_id', $map->id)
            ->where('solarsystem_id', $solarsystemId)
            ->whereNotNull('owner')
            ->whereNotIn('state', ['gone', 'abandoned'])
            ->pluck('owner')
            ->map(fn (mixed $owner): string => mb_strtolower(mb_trim((string) $owner)))
            ->values();
    }

    /**
     * @param  list<array<string, mixed>>  $living
     * @param  list<array<string, mixed>>  $residents
     * @param  list<array<string, mixed>>  $hunters
     * @return array{label: string, tone: string}
     */
    private static function status(array $living, array $residents, array $hunters, ?string $lastHostile, CarbonImmutable $now): array
    {
        $lastResident = array_reduce($living, fn (?string $carry, array $entity): ?string => $carry === null || $entity['last_seen'] > $carry ? $entity['last_seen'] : $carry);
        if ($lastResident !== null) {
            $at = CarbonImmutable::parse($lastResident);

            return $at->gte($now->subDays(3))
                ? ['label' => 'Lived in · active '.$at->diffForHumans($now, short: true, syntax: \Carbon\CarbonInterface::DIFF_RELATIVE_TO_NOW), 'tone' => 'red']
                : ['label' => 'Lived in · quiet for '.$at->diffForHumans($now, short: true, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE), 'tone' => 'amber'];
        }
        $recentHunt = array_filter($hunters, fn (array $hunter): bool => CarbonImmutable::parse($hunter['last_seen'])->gte($now->subDays(7)));
        if ($recentHunt !== []) {
            return ['label' => 'Hunted', 'tone' => 'amber'];
        }
        if ($residents !== []) {
            return ['label' => 'Evicted or empty', 'tone' => 'gray'];
        }
        if ($lastHostile === null || CarbonImmutable::parse($lastHostile)->lt($now->subDays(30))) {
            return ['label' => 'Quiet', 'tone' => 'gray'];
        }

        return ['label' => 'Visited', 'tone' => 'gray'];
    }

    /**
     * The quietest stretch of the day (six hours in a row), when there is activity at all.
     *
     * @param  list<int>  $hours
     * @return array{from: int, to: int}|null
     */
    public static function quiet(array $hours): ?array
    {
        if (array_sum($hours) < 5) {
            return null;
        }
        $best = 0;
        $bestSum = PHP_INT_MAX;
        for ($start = 0; $start < 24; $start++) {
            $sum = 0;
            for ($offset = 0; $offset < 6; $offset++) {
                $sum += $hours[($start + $offset) % 24];
            }
            if ($sum < $bestSum) {
                $bestSum = $sum;
                $best = $start;
            }
        }

        return ['from' => $best, 'to' => ($best + 6) % 24];
    }
}
