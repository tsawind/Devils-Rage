<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Things the bot posts into a Discord channel by itself (set up with /exits here and /fuel daily).
 */
enum DiscordFeedKind: string
{
    case ChainExits = 'chain_exits';
    case FuelDaily = 'fuel_daily';
    case FuelWeekly = 'fuel_weekly';
    case Health = 'health';

    public function label(): string
    {
        return match ($this) {
            self::ChainExits => 'New chain exits',
            self::FuelDaily => 'Daily structure fuel',
            self::FuelWeekly => 'Weekly structure fuel',
            self::Health => 'SeAT health check',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ChainExits => 'Posts each new k-space exit on the map, with its security, region and nearest trade hub.',
            self::FuelDaily => 'Posts the corp structures and how long their fuel lasts, every day at 16:00 EVE time.',
            self::FuelWeekly => 'Posts the corp structures and how long their fuel lasts, every Friday at 02:00 EVE time.',
            self::Health => 'Posts when a CEO/director SeAT login breaks, characters need to log in again, jobs fail or the nightly backup is missing (daily at 15:00 EVE time, "all good" on Fridays).',
        };
    }

    /**
     * Feeds that read corp data from SeAT, so only corp leaders may start them.
     */
    public function needsLeader(): bool
    {
        return $this !== self::ChainExits;
    }
}
