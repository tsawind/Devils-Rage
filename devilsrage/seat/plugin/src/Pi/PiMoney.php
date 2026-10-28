<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Pi;

/**
 * Patch 63: "Make more ISK". Ranks what would add the most ISK a month: restarting what's
 * stopped, feeding factories that run short of raw, selling at the better hub, selling what's
 * waiting, turning P2 into P3, and more colonies (skills / free slots). Plain PHP: the
 * controller hands it the numbers it already has.
 */
final class PiMoney
{
    public const DAYS_PER_MONTH = 30;

    /** A P3 factory cycle (1 h): 10 of each P2 in, 3 P3 out. */
    public const P3_INPUT_PER_CYCLE = 10;

    public const P3_OUTPUT_PER_CYCLE = 3;

    /** P3 product => the P2 it is made from. */
    public const P3_RECIPES = [
        'Biotech Research Reports' => ['Nanites', 'Livestock', 'Construction Blocks'],
        'Camera Drones' => ['Silicate Glass', 'Rocket Fuel'],
        'Condensates' => ['Oxides', 'Coolant'],
        'Cryoprotectant Solution' => ['Test Cultures', 'Synthetic Oil', 'Fertilizer'],
        'Data Chips' => ['Supertensile Plastics', 'Microfiber Shielding'],
        'Gel-Matrix Biopaste' => ['Oxides', 'Biocells', 'Superconductors'],
        'Guidance Systems' => ['Water-Cooled CPU', 'Transmitter'],
        'Hazmat Detection Systems' => ['Polytextiles', 'Viral Agent', 'Transmitter'],
        'Hermetic Membranes' => ['Polyaramids', 'Genetically Enhanced Livestock'],
        'High-Tech Transmitters' => ['Polyaramids', 'Transmitter'],
        'Industrial Explosives' => ['Fertilizer', 'Polytextiles'],
        'Neocoms' => ['Biocells', 'Silicate Glass'],
        'Nuclear Reactors' => ['Microfiber Shielding', 'Enriched Uranium'],
        'Planetary Vehicles' => ['Supertensile Plastics', 'Mechanical Parts', 'Miniature Electronics'],
        'Robotics' => ['Mechanical Parts', 'Consumer Electronics'],
        'Smartfab Units' => ['Construction Blocks', 'Miniature Electronics'],
        'Supercomputers' => ['Water-Cooled CPU', 'Coolant', 'Consumer Electronics'],
        'Synthetic Synapses' => ['Supertensile Plastics', 'Test Cultures'],
        'Transcranial Microcontrollers' => ['Biocells', 'Nanites'],
        'Ukomi Superconductors' => ['Synthetic Oil', 'Superconductors'],
        'Vaccines' => ['Livestock', 'Viral Agent'],
    ];

    /** @return list<string> every P3 and P2 name the recipes use (for prices) */
    public static function recipeNames(): array
    {
        $names = array_keys(self::P3_RECIPES);
        foreach (self::P3_RECIPES as $inputs) {
            array_push($names, ...$inputs);
        }

        return array_values(array_unique($names));
    }

    /**
     * P3 that can be made from the P2 made now, best first. Each uses as much of its P2 as the
     * scarcest input allows; the gain is what it sells for minus what that P2 would have sold
     * for (and customs tax both ways).
     *
     * @param  array<string, int>  $p2PerDay  P2 name => units made a day
     * @param  array<string, float>  $price  name => split price
     * @return list<array{p3: string, inputs: list<string>, per_day: int, factories: int, sell_p3: float, sell_p2: float, gain_per_day: float}>
     */
    public static function upgrades(array $p2PerDay, array $price, float $taxRate = 0.0): array
    {
        $options = [];
        foreach (self::P3_RECIPES as $p3 => $inputs) {
            if (! isset($price[$p3]) || $price[$p3] <= 0) {
                continue;
            }
            $cycles = PHP_INT_MAX;
            foreach ($inputs as $input) {
                $cycles = min($cycles, intdiv((int) ($p2PerDay[$input] ?? 0), self::P3_INPUT_PER_CYCLE));
            }
            if ($cycles <= 0) {
                continue;
            }
            $made = $cycles * self::P3_OUTPUT_PER_CYCLE;
            $used = $cycles * self::P3_INPUT_PER_CYCLE;
            $sellP2 = 0.0;
            foreach ($inputs as $input) {
                $sellP2 += $used * (float) ($price[$input] ?? 0);
            }
            $tax = PiPlanner::exportTax(3, $made, $taxRate)
                + count($inputs) * PiPlanner::exportTax(2, $used, $taxRate) / 2
                - count($inputs) * PiPlanner::exportTax(2, $used, $taxRate);
            $sellP3 = $made * $price[$p3];
            $options[] = [
                'p3' => $p3,
                'inputs' => $inputs,
                'per_day' => $made,
                'factories' => (int) ceil($cycles / 24),
                'sell_p3' => $sellP3,
                'sell_p2' => $sellP2,
                'gain_per_day' => $sellP3 - $sellP2 - $tax,
            ];
        }
        $options = array_values(array_filter($options, fn (array $o): bool => $o['gain_per_day'] > 0));
        usort($options, fn (array $a, array $b): int => $b['gain_per_day'] <=> $a['gain_per_day']);

        return $options;
    }

    /**
     * The ranked list. Every number is per day except stock (ISK waiting now).
     *
     * @param  array{
     *   stopped: array{count: int, per_day: float},
     *   short: array{count: int, per_day: float, planets: list<array{name: string, per_day: float}>},
     *   hub: array{name: string, per_day: float, share: float},
     *   stock: array{value: float, m3: float},
     *   upgrade: array{p3: string, inputs: list<string>, per_day: int, factories: int, gain_per_day: float}|null,
     *   grow: array{planets: int, per_day: float, characters: int}
     * }  $facts
     * @return list<array{kind: string, title: string, why: string, per_month: float, now: float}>
     */
    public static function suggestions(array $facts, callable $isk): array
    {
        $month = self::DAYS_PER_MONTH;
        $list = [];
        if ($facts['stopped']['count'] > 0 && $facts['stopped']['per_day'] > 0) {
            $list[] = self::item('restart', 'Restart the '.$facts['stopped']['count'].' stopped '.($facts['stopped']['count'] === 1 ? 'colony' : 'colonies'),
                'Each day they sit idle costs '.$isk($facts['stopped']['per_day']).'. Lining everyone up on one reset (the PI run does it) keeps it from happening again.',
                $facts['stopped']['per_day'] * $month);
        }
        if ($facts['short']['count'] > 0 && $facts['short']['per_day'] > 0) {
            $top = implode(', ', array_map(fn (array $p): string => $p['name'].' +'.$isk($p['per_day'] * $month), array_slice($facts['short']['planets'], 0, 3)));
            $list[] = self::item('feed', 'Feed your factories: '.$facts['short']['count'].' planets run short of raw',
                'Fresh spots, balanced heads and shorter links bring them up to full. Biggest: '.$top.'.',
                $facts['short']['per_day'] * $month);
        }
        if ($facts['upgrade'] !== null) {
            $u = $facts['upgrade'];
            $list[] = self::item('upgrade', 'Turn '.implode(' + ', $u['inputs']).' into '.$u['p3'],
                number_format($u['per_day']).' '.$u['p3'].' a day from the P2 you already make, on '.$u['factories'].' advanced '.($u['factories'] === 1 ? 'factory' : 'factories').' on a factory planet (haul the P2 there once a week). Sells for more than the P2 it uses.',
                $u['gain_per_day'] * $month);
        }
        if ($facts['grow']['planets'] > 0 && $facts['grow']['per_day'] > 0) {
            $list[] = self::item('grow', $facts['grow']['planets'].' more '.($facts['grow']['planets'] === 1 ? 'colony' : 'colonies').' on '.$facts['grow']['characters'].' '.($facts['grow']['characters'] === 1 ? 'character' : 'characters'),
                'Free slots and skills (CC 5, IC 4) at what an average colony of yours makes. The Skills tab has the cost.',
                $facts['grow']['per_day'] * $month);
        }
        if ($facts['hub']['per_day'] > 0 && $facts['hub']['share'] >= 0.01) {
            $list[] = self::item('hub', 'Sell at '.$facts['hub']['name'],
                'Your products are '.number_format($facts['hub']['share'] * 100, 1).'% dearer there right now (split prices). Worth it when you haul there anyway.',
                $facts['hub']['per_day'] * $month);
        }
        if ($facts['stock']['value'] > 0) {
            $list[] = self::item('stock', 'Sell what is waiting: '.$isk($facts['stock']['value']),
                number_format($facts['stock']['m3']).' m³ in launchpads, storage and customs offices. ISK sitting on a planet is ISK an attack can take.',
                0.0, $facts['stock']['value']);
        }
        usort($list, fn (array $a, array $b): int => max($b['per_month'], $b['now']) <=> max($a['per_month'], $a['now']));

        return $list;
    }

    /** @return array{kind: string, title: string, why: string, per_month: float, now: float} */
    private static function item(string $kind, string $title, string $why, float $perMonth, float $now = 0.0): array
    {
        return ['kind' => $kind, 'title' => $title, 'why' => $why, 'per_month' => $perMonth, 'now' => $now];
    }
}
