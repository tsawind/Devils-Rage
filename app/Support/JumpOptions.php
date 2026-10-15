<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MapConnectionJump;
use App\Models\ShipProfile;
use App\Models\Type;
use Illuminate\Support\Collection;

/**
 * Patch 37: the masses a logged jump could have had, for the jump log's buttons: HOT (the
 * biggest prop), SEMI (a smaller prop, e.g. a carrier on a 500MN), COLD, and 🧵 threaded
 * (entanglers on, cold). From the pilot's ship profile, else the bare hull with a typical
 * prop for its size.
 */
final class JumpOptions
{
    public const array KINDS = ['hot', 'semi', 'cold', 'thread'];

    /**
     * @param  Collection<int, MapConnectionJump>  $jumps
     * @return array<int, list<array{kind: string, label: string, mass: int}>> jump id → options
     */
    public static function forJumps(Collection $jumps): array
    {
        $pairs = $jumps->filter(fn (MapConnectionJump $jump): bool => $jump->ship_type_id !== null);
        $profiles = ShipProfile::query()
            ->whereIn('character_id', $pairs->pluck('character_id')->filter()->unique()->values()->all())
            ->whereIn('ship_type_id', $pairs->pluck('ship_type_id')->unique()->values()->all())
            ->get()
            ->keyBy(fn (ShipProfile $profile): string => $profile->character_id.':'.$profile->ship_type_id);
        $hulls = Type::query()->whereIn('id', $pairs->pluck('ship_type_id')->unique()->values()->all())->pluck('mass', 'id');

        $options = [];
        foreach ($pairs as $jump) {
            $profile = $jump->character_id === null ? null : $profiles->get($jump->character_id.':'.$jump->ship_type_id);
            $options[$jump->id] = $profile instanceof ShipProfile
                ? self::fromProfile($profile)
                : self::fromHull((int) round((float) ($hulls[$jump->ship_type_id] ?? 0)));
        }

        return $options;
    }

    /**
     * @return list<array{kind: string, label: string, mass: int}>
     */
    public static function fromProfile(ShipProfile $profile): array
    {
        $list = $profile->options ?? [
            ['key' => 'hot0', 'label' => 'HOT', 'mode' => 'hot', 'mass' => $profile->hot_mass],
            ['key' => 'cold', 'label' => 'COLD', 'mode' => 'cold', 'mass' => $profile->cold_mass],
        ];
        $hot = array_values(array_filter($list, fn (array $option): bool => $option['mode'] === 'hot' && ! str_contains($option['key'], '-e')));
        usort($hot, fn (array $a, array $b): int => $b['mass'] <=> $a['mass']);

        $options = [];
        foreach ($hot as $index => $option) {
            $options[] = ['kind' => $index === 0 ? 'hot' : 'semi', 'label' => $index === 0 ? $option['label'] : str_replace('HOT', 'SEMI', $option['label']), 'mass' => (int) $option['mass']];
        }
        foreach ($list as $option) {
            if ($option['mode'] === 'cold') {
                $thread = str_contains($option['key'], '-e');
                $options[] = ['kind' => $thread ? 'thread' : 'cold', 'label' => $thread ? '🧵 '.str_replace('COLD · ', '', $option['label']) : 'COLD', 'mass' => (int) $option['mass']];
            }
        }

        return $options;
    }

    /**
     * @return list<array{kind: string, label: string, mass: int}>
     */
    public static function fromHull(int $hull): array
    {
        if ($hull <= 0) {
            return [];
        }
        // A typical prop for the size (as the planner guesses): caps none known, BS 50m, cruisers 5m, frigates 0.5m.
        $prop = match (true) {
            $hull >= 500_000_000 => 0,
            $hull >= 50_000_000 => 50_000_000,
            $hull >= 5_000_000 => 5_000_000,
            default => 500_000,
        };

        return array_values(array_filter([
            $prop > 0 ? ['kind' => 'hot', 'label' => 'HOT', 'mass' => $hull + $prop] : null,
            ['kind' => 'cold', 'label' => 'COLD', 'mass' => $hull],
        ]));
    }
}
