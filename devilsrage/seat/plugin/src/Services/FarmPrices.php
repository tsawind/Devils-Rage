<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use DevilsRage\Seat\Models\Setting;

/**
 * EVE Store ($) and New Eden Store (PLEX) price lists. Defaults are the standard
 * prices (October 2026); leaders change them on the SP farming page during a sale.
 */
final class FarmPrices
{
    public const KEY = 'farm_prices';

    /**
     * List key => label, what the amount column means, unit of the price, default rows [amount, price].
     */
    public const LISTS = [
        'plex_packs' => ['label' => 'PLEX packs, EVE Store', 'amount' => 'PLEX (with bonus)', 'unit' => '$', 'rows' => [[100, 5], [200, 10], [500, 25], [1000, 45], [1500, 65], [3000, 125], [6000, 240], [12000, 420], [20000, 650]]],
        'omega_plex' => ['label' => 'Omega, New Eden Store', 'amount' => 'months', 'unit' => 'PLEX', 'rows' => [[1, 500], [3, 1200], [6, 2100], [12, 3600], [24, 6600]]],
        'omega_usd' => ['label' => 'Omega, EVE Store', 'amount' => 'months', 'unit' => '$', 'rows' => [[1, 20], [3, 48], [6, 84], [12, 144], [24, 264]]],
        'mct_plex' => ['label' => 'MCT, New Eden Store', 'amount' => 'certificates (30 days each)', 'unit' => 'PLEX', 'rows' => [[1, 350], [2, 600], [3, 800], [6, 1500], [12, 2700], [24, 4600]]],
        'mct_usd' => ['label' => 'MCT, EVE Store', 'amount' => 'certificates (30 days each)', 'unit' => '$', 'rows' => [[1, 10], [2, 18], [3, 24], [6, 42], [12, 72], [24, 120]]],
        'extractor_plex' => ['label' => 'Skill extractors, New Eden Store', 'amount' => 'extractors', 'unit' => 'PLEX', 'rows' => [[1, 120], [10, 1200], [50, 5600]]],
        'extractor_usd' => ['label' => 'Skill extractors, EVE Store', 'amount' => 'extractors', 'unit' => '$', 'rows' => [[1, 4], [10, 35], [50, 165]]],
    ];

    /**
     * Every list, saved values over the defaults, smallest amount first.
     *
     * @return array<string, list<array{0: float, 1: float}>>
     */
    public function lists(): array
    {
        $saved = json_decode((string) Setting::read(self::KEY, '{}'), true);
        $saved = is_array($saved) ? $saved : [];

        $lists = [];
        foreach (self::LISTS as $key => $list) {
            $rows = isset($saved[$key]) && is_array($saved[$key]) && $saved[$key] !== [] ? $saved[$key] : $list['rows'];
            $rows = array_values(array_filter(
                array_map(fn ($row): array => [(float) ($row[0] ?? 0), (float) ($row[1] ?? 0)], $rows),
                fn (array $row): bool => $row[0] > 0 && $row[1] > 0,
            ));
            usort($rows, fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $lists[$key] = $rows;
        }

        return $lists;
    }

    /**
     * @param  array<string, list<array{0: float|int|string|null, 1: float|int|string|null}>>  $lists
     */
    public function save(array $lists): void
    {
        $clean = [];
        foreach (array_keys(self::LISTS) as $key) {
            $clean[$key] = array_values(array_filter(
                array_map(fn ($row): array => [(float) ($row[0] ?? 0), (float) ($row[1] ?? 0)], $lists[$key] ?? []),
                fn (array $row): bool => $row[0] > 0 && $row[1] > 0,
            ));
        }

        Setting::write(self::KEY, json_encode($clean));
    }

    /** PLEX for one month of Omega when bought as a 12-month package (for "per month" figures). */
    public function omegaPlexPerMonth(int $months = 12): float
    {
        return $this->perUnit('omega_plex', $months);
    }

    /** PLEX for one MCT certificate (30 days of one extra queue) at the given bundle size. */
    public function mctPlexEach(int $certificates = 1): float
    {
        return $this->perUnit('mct_plex', $certificates);
    }

    /** Cheapest $ per PLEX across the packs. */
    public function bestUsdPerPlex(): float
    {
        $rates = array_map(fn (array $row): float => $row[1] / $row[0], $this->lists()['plex_packs']);

        return $rates === [] ? 0.0 : min($rates);
    }

    private function perUnit(string $list, int $amount): float
    {
        $rows = $this->lists()[$list];
        foreach ($rows as [$size, $price]) {
            if ((int) $size === $amount) {
                return $price / $size;
            }
        }

        return $rows === [] ? 0.0 : $rows[0][1] / $rows[0][0];
    }
}
