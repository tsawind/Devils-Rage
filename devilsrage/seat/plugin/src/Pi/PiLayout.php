<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Pi;

/**
 * P0 → P1 colony layouts as EVE templates, sized to the planet.
 *
 * Shape (like Tsawind's J145735 I): the launchpad in the middle, a ring of basic industry
 * facilities round it, one ring slot holding the storage facility for the raw, and the
 * extractor control unit just past the storage, on the scanned hotspot. Every factory has its
 * own link to the launchpad. As many factories as the command center's power and CPU allow,
 * links included (their cost grows with length in km, so the planet's radius matters).
 *
 * Routes: extractor → storage (raw), storage → launchpad → each factory (raw), each factory →
 * launchpad (P1). EVE keeps only the extractor's number of heads in a template; the heads
 * spread round the extractor by themselves when the program starts.
 *
 * Plain PHP, no database: tested on its own.
 */
final class PiLayout
{
    /** Command center power (MW) and CPU (tf) by upgrade level (CCU skill). */
    public const CC = [0 => [6000, 1675], 1 => [9000, 7057], 2 => [12000, 12136], 3 => [15000, 17215], 4 => [17000, 21315], 5 => [19000, 25415]];

    /** Power, CPU (same on every planet type; from the SDE). */
    public const ECU = [2600, 400];

    public const HEAD = [550, 110];

    public const LAUNCHPAD = [700, 3600];

    public const STORAGE = [700, 500];

    public const BASIC = [800, 200];

    /** Link: base power and CPU, plus per km (level 0). */
    public const LINK = [10.0, 15.0, 0.15, 0.2];

    /** Closest two buildings sit (km, centre to centre), and the storage → extractor gap. */
    public const SPACING_KM = 30.0;

    public const MAX_FACTORIES = 24;

    /** Raw a basic factory takes per 30-minute cycle, and the P1 it makes. */
    public const BASIC_IN = 3000;

    public const BASIC_OUT = 20;

    /**
     * @param  array{planet_type_id: int, radius_km: float, hotspot: array{0: float, 1: float}, toward?: float,
     *               types: array{ecu: int, launchpad: int, storage: int, basic: int}, p0: int, p1: int,
     *               heads?: int, cc_level?: int, comment?: string}  $o
     * @return array{template: array<string, mixed>, factories: int, power: float, cpu: float, ring_km: float}
     */
    public static function p1(array $o): array
    {
        $heads = $o['heads'] ?? 10;
        [$power, $cpu] = self::CC[$o['cc_level'] ?? 5];
        $radius = (float) $o['radius_km'];
        $best = null;
        for ($n = self::MAX_FACTORIES; $n >= 1; $n--) {
            $ring = self::ringKm($n + 1);
            $load = self::load($n, $ring, $heads);
            if ($load[0] <= $power && $load[1] <= $cpu) {
                $best = [$n, $ring, $load];
                break;
            }
        }
        if ($best === null) {
            throw new \InvalidArgumentException('Not even one factory fits next to that extractor.');
        }
        [$n, $ring, [$usedPower, $usedCpu]] = $best;
        [$hLat, $hLon] = $o['hotspot'];
        $away = ($o['toward'] ?? 0.0) + M_PI;
        // The launchpad: (ring + gap) km from the hotspot; the storage on the ring facing it.
        $hub = self::move($hLat, $hLon, $away, $ring + self::SPACING_KM, $radius);
        $back = self::bearing($hub[0], $hub[1], $hLat, $hLon);
        $pins = [];
        $pin = function (int $type, array $at, ?int $product = null, int $heads = 0) use (&$pins): int {
            $pins[] = ['H' => $heads, 'La' => round($at[0], 5), 'Lo' => round($at[1], 5), 'S' => $product, 'T' => $type];

            return count($pins);
        };
        $pad = $pin($o['types']['launchpad'], $hub);
        $store = $pin($o['types']['storage'], self::move($hub[0], $hub[1], $back, $ring, $radius));
        $ecu = $pin($o['types']['ecu'], [$hLat, $hLon], $o['p0'], $heads);
        $links = [['D' => $store, 'Lv' => 0, 'S' => $pad], ['D' => $ecu, 'Lv' => 0, 'S' => $store]];
        $routes = [['P' => [$ecu, $store], 'Q' => 1000, 'T' => $o['p0']]];
        for ($i = 1; $i <= $n; $i++) {
            $factory = $pin($o['types']['basic'], self::move($hub[0], $hub[1], $back + 2 * M_PI * $i / ($n + 1), $ring, $radius), $o['p1']);
            $links[] = ['D' => $factory, 'Lv' => 0, 'S' => $pad];
            $routes[] = ['P' => [$store, $pad, $factory], 'Q' => self::BASIC_IN, 'T' => $o['p0']];
            $routes[] = ['P' => [$factory, $pad], 'Q' => self::BASIC_OUT, 'T' => $o['p1']];
        }

        return [
            'template' => [
                'CmdCtrLv' => $o['cc_level'] ?? 5,
                'Cmt' => $o['comment'] ?? 'Devil\'s Rage P1 layout',
                'Diam' => round(2 * $radius, 1),
                'L' => $links,
                'P' => $pins,
                'Pln' => $o['planet_type_id'],
                'R' => $routes,
            ],
            'factories' => $n,
            'power' => $usedPower,
            'cpu' => $usedCpu,
            'ring_km' => $ring,
        ];
    }

    /** Ring radius (km) so `slots` buildings round it sit SPACING_KM apart (and the ring clears the launchpad). */
    public static function ringKm(int $slots): float
    {
        return max(self::SPACING_KM, self::SPACING_KM / 2 / sin(M_PI / max(2, $slots)));
    }

    /**
     * Power and CPU of the whole colony with n factories round a ring of `ring` km.
     *
     * @return array{0: float, 1: float}
     */
    public static function load(int $n, float $ring, int $heads): array
    {
        $power = self::ECU[0] + $heads * self::HEAD[0] + self::LAUNCHPAD[0] + self::STORAGE[0] + $n * self::BASIC[0];
        $cpu = self::ECU[1] + $heads * self::HEAD[1] + self::LAUNCHPAD[1] + self::STORAGE[1] + $n * self::BASIC[1];
        // Links: launchpad → storage and each factory (ring km), storage → extractor (gap km).
        foreach (array_merge(array_fill(0, $n + 1, $ring), [self::SPACING_KM]) as $km) {
            $power += self::LINK[0] + self::LINK[2] * $km;
            $cpu += self::LINK[1] + self::LINK[3] * $km;
        }

        return [$power, $cpu];
    }

    /**
     * The best spot for an extractor: the highest mean heat within `clusterKm` (where its heads
     * spread), keeping `avoidKm` clear of other extractors' heads on the same raw (depletion is shared).
     *
     * @param  list<array{0: float, 1: float}>  $avoid  heads already pulling this raw (EVE lat/lon)
     * @return array{lat: float, lon: float, heat: float, toward: float}|null
     */
    public static function hotspot(string $cells, float $radiusKm, array $avoid = [], float $clusterKm = 40.0, float $avoidKm = 150.0): ?array
    {
        $cluster = $clusterKm / $radiusKm;
        $best = null;
        for ($row = 6; $row < PiScan::GRID_H - 6; $row += 2) {
            $lat = ($row + 0.5) / PiScan::GRID_H * M_PI;
            for ($col = 0; $col < PiScan::GRID_W; $col += 2) {
                $lon = ($col + 0.5) / PiScan::GRID_W * 2 * M_PI;
                foreach ($avoid as [$aLat, $aLon]) {
                    if (self::distance($lat, $lon, $aLat, $aLon) * $radiusKm < $avoidKm) {
                        continue 2;
                    }
                }
                $mean = PiScan::meanAround($cells, $lat, $lon, max($cluster, 1.5 * M_PI / 180));
                if ($mean['heat'] !== null && $mean['cells'] >= 3 && ($best === null || $mean['heat'] > $best['heat'])) {
                    $best = ['lat' => $lat, 'lon' => $lon, 'heat' => $mean['heat']];
                }
            }
        }
        if ($best === null) {
            return null;
        }
        // Face the factories downhill: the launchpad goes on the coolest side, so they don't sit on the best ground.
        $coolest = null;
        for ($k = 0; $k < 8; $k++) {
            $bearing = $k * M_PI / 4;
            $at = self::move($best['lat'], $best['lon'], $bearing + M_PI, 3 * $clusterKm, $radiusKm);
            $heat = PiScan::meanAround($cells, $at[0], $at[1], $cluster)['heat'] ?? 1.0;
            if ($coolest === null || $heat < $coolest[1]) {
                $coolest = [$bearing, $heat];
            }
        }
        $best['toward'] = $coolest[0];

        return $best;
    }

    /** Great-circle angle between two EVE positions (latitude 0 … π from the north pole). */
    public static function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $a = self::vector($lat1, $lon1);
        $b = self::vector($lat2, $lon2);

        return acos(max(-1.0, min(1.0, $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2])));
    }

    /**
     * Walk `km` from a spot along `bearing` (0 = north, π/2 = east).
     *
     * @return array{0: float, 1: float}
     */
    public static function move(float $lat, float $lon, float $bearing, float $km, float $radiusKm): array
    {
        $p = self::vector($lat, $lon);
        [$east, $north] = self::frame($lat, $lon);
        $angle = $km / $radiusKm;
        $dir = [];
        for ($i = 0; $i < 3; $i++) {
            $dir[$i] = cos($bearing) * $north[$i] + sin($bearing) * $east[$i];
        }
        $q = [];
        for ($i = 0; $i < 3; $i++) {
            $q[$i] = cos($angle) * $p[$i] + sin($angle) * $dir[$i];
        }
        $lon2 = atan2($q[1], $q[0]);

        return [acos(max(-1.0, min(1.0, $q[2]))), $lon2 < 0 ? $lon2 + 2 * M_PI : $lon2];
    }

    /** Bearing from one spot to another (0 = north, π/2 = east). */
    public static function bearing(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        [$east, $north] = self::frame($lat1, $lon1);
        $b = self::vector($lat2, $lon2);

        return atan2($b[0] * $east[0] + $b[1] * $east[1] + $b[2] * $east[2], $b[0] * $north[0] + $b[1] * $north[1] + $b[2] * $north[2]);
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function vector(float $lat, float $lon): array
    {
        return [sin($lat) * cos($lon), sin($lat) * sin($lon), cos($lat)];
    }

    /** @return array{0: array{0: float, 1: float, 2: float}, 1: array{0: float, 1: float, 2: float}} east and north at a spot */
    private static function frame(float $lat, float $lon): array
    {
        return [[-sin($lon), cos($lon), 0.0], [-cos($lat) * cos($lon), -cos($lat) * sin($lon), sin($lat)]];
    }
}
