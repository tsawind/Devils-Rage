<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Pi;

/**
 * Patch 65: factory planets fed from your other characters.
 *
 * Tsa's way: P0 → P2 planets on many characters, then the P2 is hauled to a factory planet
 * (e.g. Tsawind's J145735 IX and V) that turns it into P3 and P4 (Organic Mortar Applicators).
 * Such a planet's factories take inputs nothing on that planet makes. This works out what each
 * factory planet needs brought in per hour, shares out what your own planets make among your
 * factory planets, and says how fully each one is fed.
 *
 * Worth: a factory planet is worth what it makes minus what the goods it uses would sell for,
 * so the P2 isn't counted twice (once where it's made, again inside the P4).
 */
final class PiFeed
{
    /** Units per hour one factory takes of each input, by factory kind and the input's tier. */
    private const RATE = [
        'basic' => [0 => 6000],
        'advanced' => [1 => 40, 2 => 10],
        'hightech' => [1 => 40, 3 => 6],
    ];

    /**
     * What a planet must have brought in per hour (type id => units), empty if it feeds itself.
     *
     * @param  array<string, mixed>  $planet  PiData planet (factories with inputs / outputs, extractors)
     * @param  array<int, array{tier: int}>  $types
     * @return array<int, float>
     */
    public static function imports(array $planet, array $types): array
    {
        $made = [];
        foreach ($planet['extractors'] ?? [] as $extractor) {
            $made[(int) $extractor['product']] = true;
        }
        foreach ($planet['factories'] ?? [] as $factory) {
            foreach ($factory['outputs'] as $output) {
                $made[(int) $output] = true;
            }
        }
        $need = [];
        foreach ($planet['factories'] ?? [] as $factory) {
            foreach (array_unique($factory['inputs']) as $input) {
                $input = (int) $input;
                if (isset($made[$input])) {
                    continue;
                }
                $rate = self::RATE[$factory['kind']][$types[$input]['tier'] ?? -1] ?? 0;
                if ($rate > 0) {
                    $need[$input] = ($need[$input] ?? 0) + $rate;
                }
            }
        }

        return $need;
    }

    /**
     * Share what your planets make among your factory planets, in the order given.
     *
     * @param  array<int|string, array<int, float>>  $needs  factory planet key => type id => units / h
     * @param  array<int, float>  $supply  type id => units / h your other planets make
     * @return array<int|string, array{fed: float, items: array<int, array{need: float, got: float}>}>
     */
    public static function share(array $needs, array $supply): array
    {
        $left = $supply;
        $out = [];
        foreach ($needs as $key => $need) {
            $items = [];
            $fed = 1.0;
            foreach ($need as $type => $units) {
                $got = min($units, max(0.0, (float) ($left[$type] ?? 0)));
                $items[$type] = ['need' => $units, 'got' => $got];
                $fed = min($fed, $units > 0 ? $got / $units : 1.0);
            }
            // A chain runs at the pace of its scarcest input: only that much of each is used.
            foreach ($need as $type => $units) {
                $used = $units * $fed;
                $left[$type] = max(0.0, (float) ($left[$type] ?? 0) - $used);
                $items[$type]['got'] = $used;
            }
            $out[$key] = ['fed' => $fed, 'items' => $items];
        }

        return $out;
    }
}
