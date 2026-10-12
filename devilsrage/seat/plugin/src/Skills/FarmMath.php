<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Skills;

/**
 * Skill point farming arithmetic. Pure PHP, so it can be tested on its own.
 */
final class FarmMath
{
    /** Skill points a Skill Extractor takes out and a Large Skill Injector holds. */
    public const SP_PER_EXTRACTOR = 500_000;

    /** Extractors can't take a character below this many skill points. */
    public const EXTRACT_FLOOR = 5_000_000;

    public const DAYS_PER_MONTH = 30;

    /** Extractors that can be used right now. */
    public static function extractorsReady(int $totalSp): int
    {
        return intdiv(max(0, $totalSp - self::EXTRACT_FLOOR), self::SP_PER_EXTRACTOR);
    }

    public static function spPerMonth(float $spPerMinute): float
    {
        return $spPerMinute * 60 * 24 * self::DAYS_PER_MONTH;
    }

    /**
     * ISK per month: injectors made (each costs an extractor) minus the training cost.
     *
     * @return array{injectors: float, revenue: float, cost: float, profit: float}
     */
    public static function monthly(float $spPerMinute, float $injectorPrice, float $extractorPrice, float $slotCost): array
    {
        $injectors = self::spPerMonth($spPerMinute) / self::SP_PER_EXTRACTOR;
        $revenue = $injectors * ($injectorPrice - $extractorPrice);

        return [
            'injectors' => $injectors,
            'revenue' => $revenue,
            'cost' => $slotCost,
            'profit' => $revenue - $slotCost,
        ];
    }
}
