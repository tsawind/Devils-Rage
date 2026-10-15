<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Type;

/**
 * Patch 37: a ship's jump masses from its fit.
 * - cold (prop off) = hull + plates; plates lighter by Armor Layering (5% per level) and
 *   Advanced Armor Layering (2% per level);
 * - hot = cold + one prop mod's mass addition: each prop is its own option (a carrier's
 *   10,000MN AB or 50,000MN MWD);
 * - a Higgs Anchor rig doubles all of it (hull, plates and prop);
 * - each active Zero-Point Mass Entangler takes 80% off the whole mass, stacking penalized
 *   (1 → ×0.2, 2 → ×0.061, 3 → ×0.033): a Devoter with a Higgs is 32.4m cold, 6.48m with one,
 *   1.97m with two, 1.07m with three ("tiny" / "thread it").
 */
final class ShipMass
{
    /** EVE's stacking penalty for the 1st, 2nd, 3rd… module of a kind. */
    private const array STACKING = [1.0, 0.869119980800, 0.570583143511, 0.282955154023, 0.105992649743];

    private const float ENTANGLER_REDUCTION = 0.8;

    /** The mass left with this many entanglers active. */
    public static function entanglerFactor(int $active): float
    {
        $factor = 1.0;
        for ($index = 0; $index < $active; $index++) {
            $factor *= 1 - self::ENTANGLER_REDUCTION * (self::STACKING[$index] ?? 0.0);
        }

        return $factor;
    }

    /** Plate mass after Armor Layering / Advanced Armor Layering. */
    public static function plateMass(float $plateMass, int $armorLayering, int $advancedArmorLayering): float
    {
        return $plateMass * (1 - 0.05 * max(0, min(5, $armorLayering))) * (1 - 0.02 * max(0, min(5, $advancedArmorLayering)));
    }

    /**
     * Every jump mass the fit can make, heaviest first.
     *
     * @param  list<array{name: string, mass: float}>  $props
     * @return list<array{key: string, label: string, mode: string, mass: int}>
     */
    public static function options(int $hullTypeId, bool $higgs, float $plateMass, array $props, int $entanglers): array
    {
        $hull = (float) (Type::query()->whereKey($hullTypeId)->value('mass') ?? 0);
        $factor = $higgs ? 2.0 : 1.0;
        $cold = ($hull + $plateMass) * $factor;

        $base = [['key' => 'cold', 'label' => 'COLD', 'mode' => 'cold', 'mass' => $cold]];
        // Patch 37: props of one size add the same mass (50000MN MWD = 10000MN AB): one option each.
        $bySize = [];
        foreach ($props as $prop) {
            $bySize[(string) round($prop['mass'])][] = self::propLabel($prop['name']);
        }
        $index = 0;
        foreach ($bySize as $mass => $labels) {
            $labels = array_values(array_unique($labels));
            $base[] = [
                'key' => sprintf('hot%d', $index++),
                'label' => sprintf('HOT %s', implode(' / ', $labels)),
                'mode' => 'hot',
                'mass' => $cold + (float) $mass * $factor,
            ];
        }

        $options = [];
        foreach ($base as $option) {
            for ($active = 0; $active <= min($entanglers, count(self::STACKING)); $active++) {
                $options[] = [
                    'key' => $active === 0 ? $option['key'] : sprintf('%s-e%d', $option['key'], $active),
                    'label' => $active === 0 ? $option['label'] : sprintf('%s · %d entangler%s', $option['label'], $active, $active === 1 ? '' : 's'),
                    'mode' => $option['mode'],
                    'mass' => (int) round($option['mass'] * self::entanglerFactor($active)),
                ];
            }
        }
        usort($options, fn (array $a, array $b): int => $b['mass'] <=> $a['mass']);

        return $options;
    }

    /** "10000MN Monopropellant Enduring Afterburner" → "10000MN AB". */
    public static function propLabel(string $name): string
    {
        $size = preg_match('/(\d+MN)/', $name, $match) === 1 ? $match[1].' ' : '';

        return $size.(str_contains($name, 'Microwarpdrive') ? 'MWD' : (str_contains($name, 'Afterburner') ? 'AB' : 'prop'));
    }

    /**
     * Without a known fit: the bare hull cold, and hot with a typical prop mod for its size
     * (50m for a battleship-sized hull, 5m for cruisers, 0.5m for frigates; none for caps).
     * HICs and the Odysseus count with one entangler on, as in the jump log.
     *
     * @return array{cold: int, hot: int}
     */
    public static function bareHull(int $hullTypeId): array
    {
        $hull = Type::loggedJumpMass($hullTypeId);
        $rawMass = (float) (Type::query()->whereKey($hullTypeId)->value('mass') ?? 0);
        $prop = match (true) {
            $rawMass >= 500_000_000 => 0,
            $rawMass >= 50_000_000 => 50_000_000,
            $rawMass >= 5_000_000 => 5_000_000,
            default => 500_000,
        };

        return ['cold' => $hull, 'hot' => $hull + $prop];
    }
}
