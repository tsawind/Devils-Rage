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

    public function label(): string
    {
        return match ($this) {
            self::ChainExits => 'New chain exits',
            self::FuelDaily => 'Daily structure fuel',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ChainExits => 'Posts each new k-space exit on the map, with its security, region and nearest trade hub.',
            self::FuelDaily => 'Posts the corp structures and how long their fuel lasts, every day at 16:00 EVE time.',
        };
    }
}
