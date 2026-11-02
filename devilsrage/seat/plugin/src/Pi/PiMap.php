<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Pi;

/**
 * Patch 64: the corp planet map and pair finder, from every extractor program the corp has run
 * (devilsrage_pi_runs, kept 180 days).
 *
 * A **spot** is where a resource was extracted: programs on one planet for one resource whose
 * heads' centre was within SAME_SPOT of each other. Each spot gets a **yield** (units per head
 * over the first 48 h with CCP's formula, so programs of any length compare fairly), its best
 * and latest yield (latest well under best = worked out), and when it was last used.
 *
 * The **pair finder** looks for a spot of resource A and one of resource B close enough for one
 * P0 → P2 setup (the two extractors' centres within reach), both still good and recent.
 *
 * Coordinates are EVE's: latitude 0 … π from the north pole, longitude 0 … 2π (radians).
 * Pure: give it the runs, get spots and pairs back.
 */
final class PiMap
{
    /** Programs whose centres are this close (radians, each way) are one spot. */
    public const SAME_SPOT = 0.03;

    /** Hours of a program used to compare yields. */
    public const YARDSTICK_HOURS = 48;

    /** A spot's weight halves every this many days since it was last used. */
    public const HALF_LIFE_DAYS = 30;

    /**
     * @param  list<array{character_id: int, product: int, product_name: string, heads: int, lat: float, lon: float, qty: int, cycle: int, install: int}>  $runs  one planet's programs
     * @return list<array{product: int, product_name: string, lat: float, lon: float, runs: int, best: int, latest: int, worked: float, last_used: int, characters: list<int>}>
     */
    public static function spots(array $runs): array
    {
        usort($runs, fn (array $a, array $b): int => $a['install'] <=> $b['install']);
        $spots = [];
        foreach ($runs as $run) {
            if ((int) $run['heads'] < 1 || (int) $run['qty'] < 1 || (int) $run['cycle'] < 1) {
                continue;
            }
            $yield = (int) round(PiPlanner::programUnits((int) $run['qty'], (int) $run['cycle'], self::YARDSTICK_HOURS * 3600) / (int) $run['heads']);
            $at = null;
            foreach ($spots as $i => $spot) {
                if ($spot['product'] === (int) $run['product'] && abs($spot['lat'] - (float) $run['lat']) < self::SAME_SPOT && abs(self::lonGap($spot['lon'], (float) $run['lon'])) < self::SAME_SPOT) {
                    $at = $i;
                    break;
                }
            }
            if ($at === null) {
                $spots[] = [
                    'product' => (int) $run['product'],
                    'product_name' => (string) $run['product_name'],
                    'lat' => (float) $run['lat'],
                    'lon' => (float) $run['lon'],
                    'runs' => 1,
                    'best' => $yield,
                    'latest' => $yield,
                    'worked' => 0.0,
                    'last_used' => (int) $run['install'],
                    'characters' => [(int) $run['character_id']],
                ];

                continue;
            }
            $spot = &$spots[$at];
            $spot['runs']++;
            $spot['best'] = max($spot['best'], $yield);
            $spot['latest'] = $yield;
            $spot['last_used'] = max($spot['last_used'], (int) $run['install']);
            // Follow the heads as they're moved around the spot.
            $spot['lat'] = (float) $run['lat'];
            $spot['lon'] = (float) $run['lon'];
            if (! in_array((int) $run['character_id'], $spot['characters'], true)) {
                $spot['characters'][] = (int) $run['character_id'];
            }
            unset($spot);
        }
        foreach ($spots as &$spot) {
            $spot['worked'] = $spot['best'] > 0 ? round(1 - $spot['latest'] / $spot['best'], 3) : 0.0;
        }
        unset($spot);

        return $spots;
    }

    /**
     * Pairs of spots for one P0 → P2 setup: one of each resource, centres within reach.
     *
     * @param  list<array<string, mixed>>  $spots  from spots()
     * @return list<array{a: array<string, mixed>, b: array<string, mixed>, km: int, score: float}>
     */
    public static function pairs(array $spots, int $productA, int $productB, float $radiusKm, float $reachKm, int $now, int $limit = 5): array
    {
        $as = array_values(array_filter($spots, fn (array $s): bool => $s['product'] === $productA));
        $bs = array_values(array_filter($spots, fn (array $s): bool => $s['product'] === $productB));
        if ($as === [] || $bs === []) {
            return [];
        }
        $bestA = max(array_map(fn (array $s): int => $s['latest'], $as)) ?: 1;
        $bestB = max(array_map(fn (array $s): int => $s['latest'], $bs)) ?: 1;
        $pairs = [];
        foreach ($as as $a) {
            foreach ($bs as $b) {
                $km = self::distanceKm($a['lat'], $a['lon'], $b['lat'], $b['lon'], $radiusKm);
                if ($km > $reachKm) {
                    continue;
                }
                $score = min($a['latest'] / $bestA, $b['latest'] / $bestB) * self::freshness(min($a['last_used'], $b['last_used']), $now);
                $pairs[] = ['a' => $a, 'b' => $b, 'km' => (int) round($km), 'score' => round($score, 3)];
            }
        }
        usort($pairs, fn (array $x, array $y): int => $y['score'] <=> $x['score'] ?: $x['km'] <=> $y['km']);

        return array_slice($pairs, 0, $limit);
    }

    /** 1 now, ½ after HALF_LIFE_DAYS, ¼ after twice that. */
    public static function freshness(int $lastUsed, int $now): float
    {
        $days = max(0, $now - $lastUsed) / 86400;

        return 0.5 ** ($days / self::HALF_LIFE_DAYS);
    }

    /** Great-circle distance between two points given as EVE latitude (from the pole) / longitude. */
    public static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2, float $radiusKm): float
    {
        $cos = cos($lat1) * cos($lat2) + sin($lat1) * sin($lat2) * cos($lon1 - $lon2);

        return acos(max(-1.0, min(1.0, $cos))) * $radiusKm;
    }

    /** Longitude difference across the 0 / 2π seam. */
    private static function lonGap(float $a, float $b): float
    {
        $gap = fmod($a - $b, 2 * M_PI);
        if ($gap > M_PI) {
            $gap -= 2 * M_PI;
        } elseif ($gap < -M_PI) {
            $gap += 2 * M_PI;
        }

        return $gap;
    }
}
