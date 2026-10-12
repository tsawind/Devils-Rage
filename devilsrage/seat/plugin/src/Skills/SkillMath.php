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
}
