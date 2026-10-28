<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Pi;

use DevilsRage\Seat\Services\JitaPrices;
use DevilsRage\Seat\Skills\SkillCheck;
use DevilsRage\Seat\Skills\SkillMath;
use Illuminate\Support\Facades\DB;

/**
 * Patch 60: is a character skilled for the corp's PI bar (Command Center Upgrades 5, five
 * planets from Interplanetary Consolidation 4), and what it costs to get there: Large Skill
 * Injectors (ISK and PLEX at Jita) or training time. Worth is worked out by whoever shows it.
 */
final class PiSkills
{
    /** The corp's bar: no PI below these. */
    public const TARGETS = ['Command Center Upgrades' => 5, 'Interplanetary Consolidation' => 4];

    public const TYPE_INJECTOR = 40520;

    public const TYPE_PLEX = 44992;

    public function __construct(private readonly SkillCheck $skills, private readonly JitaPrices $prices) {}

    /**
     * @param  list<int>  $characterIds
     * @return array<int, array{ready: bool, missing: array<string, int>, missing_sp: int, seconds: int, injectors: int, isk: float, plex: float, cc_level: int, planets: int, planets_after: int}>
     */
    public function check(array $characterIds): array
    {
        if ($characterIds === []) {
            return [];
        }
        $ids = DB::table('invTypes')->whereIn('typeName', array_keys(self::TARGETS))->pluck('typeID', 'typeName');
        $required = [];
        foreach (self::TARGETS as $name => $level) {
            if (isset($ids[$name])) {
                $required[(int) $ids[$name]] = $level;
            }
        }
        if ($required === []) {
            return [];
        }
        foreach ($this->skills->requiredFor(array_keys($required)) as $skillId => $level) {
            $required[$skillId] = max($required[$skillId] ?? 0, $level);
        }
        $names = $this->skills->names(array_keys($required));
        $gaps = $this->skills->compare($characterIds, $required);
        $trained = DB::table('character_skills')->whereIn('character_id', $characterIds)->whereIn('skill_id', array_values($ids->all()))
            ->get(['character_id', 'skill_id', 'trained_skill_level'])
            ->groupBy('character_id');
        $totals = DB::table('character_skills')->whereIn('character_id', $characterIds)
            ->groupBy('character_id')->selectRaw('character_id, sum(skillpoints_in_skill) as sp')->pluck('sp', 'character_id');
        $prices = $this->prices->get([self::TYPE_INJECTOR, self::TYPE_PLEX]);
        $injectorPrice = (float) ($prices[self::TYPE_INJECTOR]['sell'] ?? 0);
        $plexPrice = (float) ($prices[self::TYPE_PLEX]['sell'] ?? 0);
        $ccId = (int) ($ids['Command Center Upgrades'] ?? 0);
        $icId = (int) ($ids['Interplanetary Consolidation'] ?? 0);

        $result = [];
        foreach ($characterIds as $characterId) {
            $gap = $gaps[$characterId] ?? ['missing' => [], 'missing_points' => 0, 'seconds' => 0];
            $levels = ($trained[$characterId] ?? collect())->pluck('trained_skill_level', 'skill_id');
            $injectors = SkillMath::injectorsFor((int) ($totals[$characterId] ?? 0), (int) $gap['missing_points']);
            $missing = [];
            foreach ($gap['missing'] as $skillId => $level) {
                $missing[(string) ($names[$skillId] ?? 'Skill '.$skillId)] = $level;
            }
            $isk = $injectors * $injectorPrice;
            $planets = 1 + (int) ($levels[$icId] ?? 0);
            $result[$characterId] = [
                'ready' => $missing === [],
                'missing' => $missing,
                'missing_sp' => (int) $gap['missing_points'],
                'seconds' => (int) $gap['seconds'],
                'injectors' => $injectors,
                'isk' => $isk,
                'plex' => $plexPrice > 0 ? $isk / $plexPrice : 0.0,
                'cc_level' => (int) ($levels[$ccId] ?? 0),
                'planets' => $planets,
                'planets_after' => max($planets, 1 + self::TARGETS['Interplanetary Consolidation']),
            ];
        }

        return $result;
    }

    /**
     * What a character would add per day once skilled: the new planets it could run, at what one
     * of our running colonies makes per day. Payback in days for buying the injectors.
     *
     * @param  array{isk: float, planets_after: int}  $skill
     * @return array{extra_planets: int, per_day: float, payback_days: float|null}
     */
    public static function potential(array $skill, int $colonies, float $perColonyPerDay): array
    {
        $extra = max(0, $skill['planets_after'] - $colonies);
        $perDay = $extra * $perColonyPerDay;

        return [
            'extra_planets' => $extra,
            'per_day' => $perDay,
            'payback_days' => $perDay > 0 && $skill['isk'] > 0 ? $skill['isk'] / $perDay : null,
        ];
    }
}
