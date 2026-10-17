<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Skills;

/**
 * EVE skill arithmetic. Pure PHP, no Laravel, so it can be tested on its own.
 */
final class SkillMath
{
    /** dgmTypeAttributes attribute IDs. */
    public const ATTR_RANK = 275;

    public const ATTR_PRIMARY = 180;

    public const ATTR_SECONDARY = 181;

    /** Required-skill attribute => matching required-level attribute. */
    public const REQUIRED_SKILL_ATTRIBUTES = [182 => 277, 183 => 278, 184 => 279, 1285 => 1286, 1289 => 1287, 1290 => 1288];

    /** Character attribute ID => character_attributes column. */
    public const ATTRIBUTE_COLUMNS = [164 => 'charisma', 165 => 'intelligence', 166 => 'memory', 167 => 'perception', 168 => 'willpower'];

    /** Total skill points a skill of this rank has at this level (0-5). */
    public static function skillPointsAt(int $level, float $rank): int
    {
        if ($level <= 0) {
            return 0;
        }

        return (int) ceil(250 * $rank * (32 ** (($level - 1) / 2)));
    }

    /** Skill points per minute from the primary and secondary attribute values. */
    public static function pointsPerMinute(int $primary, int $secondary): float
    {
        return max(1, $primary + $secondary / 2);
    }

    /**
     * Skill points still missing for a skill.
     */
    public static function missingPoints(int $requiredLevel, float $rank, int $trainedLevel, int $currentPoints): int
    {
        if ($trainedLevel >= $requiredLevel) {
            return 0;
        }

        return max(0, self::skillPointsAt($requiredLevel, $rank) - $currentPoints);
    }

    /**
     * Readable training time: "3d 6h", "5h 20m", "12m".
     */
    public static function duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0m';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return match (true) {
            $days > 0 => $days.'d '.$hours.'h',
            $hours > 0 => $hours.'h '.$minutes.'m',
            default => max(1, $minutes).'m',
        };
    }

    /**
     * Patch 39: skill points one Large Skill Injector gives at a character's total SP.
     */
    public static function injectorYield(int $totalSp): int
    {
        return match (true) {
            $totalSp < 5_000_000 => 500_000,
            $totalSp < 50_000_000 => 400_000,
            $totalSp < 80_000_000 => 300_000,
            default => 150_000,
        };
    }

    /**
     * Patch 39: Large Skill Injectors needed to cover the missing SP, starting at the
     * character's total SP (each one gives less once the total crosses 5M / 50M / 80M).
     */
    public static function injectorsFor(int $totalSp, int $missingSp): int
    {
        $count = 0;
        while ($missingSp > 0 && $count < 10_000) {
            $gain = self::injectorYield($totalSp);
            $missingSp -= $gain;
            $totalSp += $gain;
            $count++;
        }

        return $count;
    }

    /**
     * Patch 39: a plan as EVE's skill planner pastes it: one skill level per line
     * ("Caldari Frigate 1", "Caldari Frigate 2", ...), from the level after $from.
     *
     * @param  array<int, int>  $levels  skill ID => level wanted
     * @param  array<int, string>  $names  skill ID => name
     * @param  array<int, int>  $from  skill ID => level already trained (0 when missing)
     */
    public static function planText(array $levels, array $names, array $from = []): string
    {
        $lines = [];
        foreach ($levels as $skillId => $level) {
            for ($step = ($from[$skillId] ?? 0) + 1; $step <= $level; $step++) {
                $lines[] = ($names[$skillId] ?? (string) $skillId).' '.$step;
            }
        }

        return implode("\n", $lines);
    }
}
