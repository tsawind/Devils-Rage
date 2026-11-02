<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Pi;

/**
 * Patch 64: setup options for a P0 → P2 planet, as effort against ISK.
 *
 * Tsa measured (2026-10-28) that a program's output depends only on how long it has run, not
 * on the length that was set, so "restart every N hours" makes A(N) / N per hour, where A is
 * CCP's extraction formula (PiPlanner::programUnits) for the planet's own extractors. Heads
 * stay where they are; repacking heads tighter for short programs can add more on top (not
 * promised here).
 *
 * Pure: give it each raw line's numbers and what one P2 unit is worth, get the options back.
 */
final class PiOptions
{
    /** P0 one basic factory takes per hour; P1 one makes; P2 one advanced makes. */
    private const BASIC_IN = 6000;

    private const BASIC_OUT = 40;

    private const ADVANCED_OUT = 5;

    /** Power per factory (MW). */
    private const BASIC_MW = 800;

    private const ADVANCED_MW = 700;

    /** Minutes one planet takes per visit (restart, a glance, the odd haul): an honest guess. */
    public const VISIT_MINUTES = 3;

    /**
     * The choices, shortest effort first: key => [title, hours it runs between restarts,
     * restarts a week, note].
     */
    public const CHOICES = [
        'weekly' => ['Weekly 6 d 20 h (today)', 164, 1.0, 'One login a week, one-click Restart Extraction.'],
        'safety' => ['Long 8 d 6 h program, restarted weekly', 168, 1.0, 'Same login a week, but it keeps going about 30 h more if someone misses it. Restarting early is stop, start, Submit per extractor.'],
        'four' => ['Every 4 days (4 d 3 h program)', 99, 168 / 99, 'Shorter heads can sit tighter on the hotspot: often more than shown.'],
        'two' => ['Every 2 days (2 d 1 h program)', 48, 168 / 48, 'Needs more basic factories (power) or exporting the extra raw.'],
        'daily' => ['Daily (1 d program)', 24, 7.0, 'Most ISK, most clicks: for a main who logs in every day anyway.'],
    ];

    /**
     * Options for one planet.
     *
     * @param  list<array{qty: int, cycle: int, heads: int}>  $lines  its raw lines (two for P0 → P2)
     * @param  array{basic: int, advanced: int}  $factories  what it has now
     * @param  float  $p2Value  ISK one P2 unit is worth after export tax
     * @return list<array{key: string, title: string, note: string, hours: int, logins: float, raw: list<int>, p2_week: int, isk_week: float, basics: int, advanced: int, extra_mw: int, m3_week: float}>
     */
    public static function forPlanet(array $lines, array $factories, float $p2Value): array
    {
        if (count($lines) < 2) {
            return [];
        }
        $out = [];
        foreach (self::CHOICES as $key => [$title, $hours, $perWeek, $note]) {
            $raw = [];
            foreach ($lines as $line) {
                $units = PiPlanner::programUnits((int) $line['qty'], (int) $line['cycle'], $hours * 3600);
                $raw[] = (int) round($units / $hours);
            }
            $basics = 0;
            $p1 = [];
            foreach ($raw as $perHour) {
                $basics += (int) ceil($perHour / self::BASIC_IN);
                $p1[] = $perHour / self::BASIC_IN * self::BASIC_OUT;
            }
            // Two P1 feed one P2: the smaller line sets the pace.
            $p2PerHour = min($p1) / self::BASIC_OUT * self::ADVANCED_OUT;
            $advanced = (int) ceil($p2PerHour / self::ADVANCED_OUT);
            $p2Week = (int) round($p2PerHour * 168);
            $extraMw = max(0, $basics - (int) $factories['basic']) * self::BASIC_MW + max(0, $advanced - (int) $factories['advanced']) * self::ADVANCED_MW;
            $out[] = [
                'key' => $key,
                'title' => $title,
                'note' => $note,
                'hours' => $hours,
                'logins' => round($perWeek, 1),
                'raw' => $raw,
                'p2_week' => $p2Week,
                'isk_week' => $p2Week * $p2Value,
                'basics' => $basics,
                'advanced' => $advanced,
                'extra_mw' => $extraMw,
                'm3_week' => $p2Week * 0.75,
            ];
        }

        return $out;
    }

    /**
     * Add the options of several planets together (a pilot, an account or a launch group).
     *
     * @param  list<list<array<string, mixed>>>  $planets  forPlanet() results
     * @return list<array{key: string, title: string, note: string, planets: int, logins: float, isk_week: float, effort_hours: float, extra_isk_per_hour: float|null, needs_more: int, m3_week: float}>
     */
    public static function total(array $planets): array
    {
        $planets = array_values(array_filter($planets));
        if ($planets === []) {
            return [];
        }
        $rows = [];
        foreach (array_keys(self::CHOICES) as $key) {
            $isk = 0.0;
            $m3 = 0.0;
            $needs = 0;
            $logins = 0.0;
            foreach ($planets as $options) {
                foreach ($options as $option) {
                    if ($option['key'] !== $key) {
                        continue;
                    }
                    $isk += $option['isk_week'];
                    $m3 += $option['m3_week'];
                    $logins = max($logins, $option['logins']);
                    $needs += $option['extra_mw'] > 0 ? 1 : 0;
                }
            }
            $effort = $logins * count($planets) * self::VISIT_MINUTES / 60;
            $rows[$key] = [
                'key' => $key,
                'title' => self::CHOICES[$key][0],
                'note' => self::CHOICES[$key][3],
                'planets' => count($planets),
                'logins' => $logins,
                'isk_week' => $isk,
                'effort_hours' => $effort,
                'extra_isk_per_hour' => null,
                'needs_more' => $needs,
                'm3_week' => $m3,
            ];
        }
        // What each extra hour of your time earns over the weekly routine.
        $base = $rows['weekly'];
        foreach ($rows as $key => &$row) {
            $extraHours = $row['effort_hours'] - $base['effort_hours'];
            if ($key !== 'weekly' && $extraHours > 0.01) {
                $row['extra_isk_per_hour'] = ($row['isk_week'] - $base['isk_week']) / $extraHours;
            }
        }
        unset($row);

        return array_values($rows);
    }
}
