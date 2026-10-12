<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Skills;

use Illuminate\Support\Facades\Cache;
use Seat\Eveapi\Models\Character\CharacterSkill;
use Seat\Eveapi\Models\Sde\DgmTypeAttribute;
use Seat\Eveapi\Models\Sde\InvType;
use Seat\Eveapi\Models\Skills\CharacterAttribute;

/**
 * Skill requirements of ships/modules and how far characters are from them,
 * from SeAT's SDE tables and the skills SeAT already syncs.
 */
final class SkillCheck
{
    /** Under this many seconds of training counts as "close". */
    public const CLOSE_SECONDS = 7 * 86400;

    /** @var array<int, array{rank: float, primary: int, secondary: int}> */
    private array $skillInfo = [];

    /** @var array<string, array{skills: array<int, array<int, array{level: int, points: int}>>, attributes: \Illuminate\Support\Collection}> loaded once per character list (the planner compares many fits) */
    private array $characterData = [];

    /**
     * Every skill (with prerequisites) needed to use all of these types.
     *
     * @param  list<int>  $typeIds
     * @return array<int, int> skill ID => level
     */
    public function requiredFor(array $typeIds): array
    {
        $required = [];
        $queue = array_values(array_unique($typeIds));
        $seen = [];

        while ($queue !== []) {
            $typeId = array_shift($queue);
            if (isset($seen[$typeId])) {
                continue;
            }
            $seen[$typeId] = true;

            foreach ($this->directRequirements($typeId) as $skillId => $level) {
                $required[$skillId] = max($required[$skillId] ?? 0, $level);
                $queue[] = $skillId;
            }
        }

        return $required;
    }

    /**
     * @param  list<int>  $characterIds
     * @param  array<int, int>  $required  skill ID => level
     * @return array<int, array{missing: array<int, int>, missing_points: int, seconds: int, done_points: int, total_points: int}>
     */
    public function compare(array $characterIds, array $required): array
    {
        $this->loadSkillInfo(array_keys($required));
        $cacheKey = implode(',', $characterIds);
        $this->characterData[$cacheKey] ??= [
            'skills' => $this->characterSkills($characterIds),
            'attributes' => CharacterAttribute::query()->whereIn('character_id', $characterIds)->get()->keyBy('character_id'),
        ];
        $skills = $this->characterData[$cacheKey]['skills'];
        $attributes = $this->characterData[$cacheKey]['attributes'];

        $result = [];
        foreach ($characterIds as $characterId) {
            $missing = [];
            $missingPoints = 0;
            $seconds = 0;
            $donePoints = 0;
            $totalPoints = 0;
            $attribute = $attributes->get($characterId);

            foreach ($required as $skillId => $level) {
                $info = $this->skillInfo[$skillId] ?? ['rank' => 1.0, 'primary' => 165, 'secondary' => 168];
                $have = $skills[$characterId][$skillId] ?? ['level' => 0, 'points' => 0];
                $needed = SkillMath::skillPointsAt($level, $info['rank']);
                $totalPoints += $needed;
                $donePoints += min($needed, $have['points']);

                $gap = SkillMath::missingPoints($level, $info['rank'], $have['level'], $have['points']);
                if ($have['level'] >= $level) {
                    continue;
                }

                $missing[$skillId] = $level;
                $missingPoints += $gap;
                $seconds += (int) ceil($gap / $this->pointsPerMinute($attribute, $info) * 60);
            }

            $result[$characterId] = [
                'missing' => $missing,
                'missing_points' => $missingPoints,
                'seconds' => $seconds,
                'done_points' => $donePoints,
                'total_points' => $totalPoints,
            ];
        }

        return $result;
    }

    /**
     * @param  list<int>  $typeIds
     * @return array<int, string>
     */
    public function names(array $typeIds): array
    {
        return InvType::query()->whereIn('typeID', $typeIds)->pluck('typeName', 'typeID')->all();
    }

    /**
     * @return array<int, int> skill ID => level
     */
    private function directRequirements(int $typeId): array
    {
        return Cache::remember('devilsrage.skillreq.'.$typeId, 86400, function () use ($typeId): array {
            $ids = array_merge(array_keys(SkillMath::REQUIRED_SKILL_ATTRIBUTES), array_values(SkillMath::REQUIRED_SKILL_ATTRIBUTES));
            $values = DgmTypeAttribute::query()
                ->where('typeID', $typeId)
                ->whereIn('attributeID', $ids)
                ->get()
                ->mapWithKeys(fn ($row): array => [(int) $row->attributeID => (int) ($row->valueInt ?? $row->valueFloat)]);

            $requirements = [];
            foreach (SkillMath::REQUIRED_SKILL_ATTRIBUTES as $skillAttribute => $levelAttribute) {
                $skillId = $values[$skillAttribute] ?? 0;
                if ($skillId > 0) {
                    $requirements[$skillId] = max(1, min(5, $values[$levelAttribute] ?? 1));
                }
            }

            return $requirements;
        });
    }

    /**
     * Rank and training attributes of skills.
     *
     * @param  list<int>  $skillIds
     * @return array<int, array{rank: float, primary: int, secondary: int}>
     */
    public function skillInfo(array $skillIds): array
    {
        $this->loadSkillInfo($skillIds);

        return array_intersect_key($this->skillInfo, array_flip($skillIds));
    }

    /**
     * Omega skill points per minute for a character's attributes (Alpha trains at half).
     *
     * @param  array{rank: float, primary: int, secondary: int}  $info
     */
    public function omegaRate(?CharacterAttribute $attribute, array $info): float
    {
        return $this->pointsPerMinute($attribute, $info);
    }

    /**
     * @param  list<int>  $skillIds
     */
    private function loadSkillInfo(array $skillIds): void
    {
        $todo = array_values(array_diff($skillIds, array_keys($this->skillInfo)));
        if ($todo === []) {
            return;
        }

        $rows = DgmTypeAttribute::query()
            ->whereIn('typeID', $todo)
            ->whereIn('attributeID', [SkillMath::ATTR_RANK, SkillMath::ATTR_PRIMARY, SkillMath::ATTR_SECONDARY])
            ->get()
            ->groupBy('typeID');

        foreach ($todo as $skillId) {
            $values = ($rows->get($skillId) ?? collect())
                ->mapWithKeys(fn ($row): array => [(int) $row->attributeID => (float) ($row->valueFloat ?? $row->valueInt)]);
            $this->skillInfo[$skillId] = [
                'rank' => max(1.0, (float) ($values[SkillMath::ATTR_RANK] ?? 1)),
                'primary' => (int) ($values[SkillMath::ATTR_PRIMARY] ?? 165),
                'secondary' => (int) ($values[SkillMath::ATTR_SECONDARY] ?? 168),
            ];
        }
    }

    /**
     * @param  list<int>  $characterIds
     * @return array<int, array<int, array{level: int, points: int}>>
     */
    private function characterSkills(array $characterIds): array
    {
        $skills = [];
        CharacterSkill::query()
            ->whereIn('character_id', $characterIds)
            ->get(['character_id', 'skill_id', 'skillpoints_in_skill', 'trained_skill_level'])
            ->each(function ($row) use (&$skills): void {
                $skills[(int) $row->character_id][(int) $row->skill_id] = [
                    'level' => (int) $row->trained_skill_level,
                    'points' => (int) $row->skillpoints_in_skill,
                ];
            });

        return $skills;
    }

    /**
     * @param  array{rank: float, primary: int, secondary: int}  $info
     */
    private function pointsPerMinute(?CharacterAttribute $attribute, array $info): float
    {
        if ($attribute === null) {
            return SkillMath::pointsPerMinute(20, 20);
        }

        $primary = SkillMath::ATTRIBUTE_COLUMNS[$info['primary']] ?? 'intelligence';
        $secondary = SkillMath::ATTRIBUTE_COLUMNS[$info['secondary']] ?? 'memory';

        return SkillMath::pointsPerMinute((int) $attribute->{$primary}, (int) $attribute->{$secondary});
    }
}
