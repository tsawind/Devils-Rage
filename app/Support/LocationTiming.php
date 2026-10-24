<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Patch 54: how long ESI location rounds take and how far into a round a jump was noticed, kept
 * in the cache (the newest 40 lines each) for the Server workflow's `mapper-timing` action.
 * No names, only character ids and seconds.
 */
final class LocationTiming
{
    public const string ROUNDS = 'dr:timing:location-rounds';

    public const string MOVES = 'dr:timing:location-moves';

    private const int KEEP = 40;

    public static function round(int $characters, ?DateTimeInterface $startedAt): void
    {
        self::push(self::ROUNDS, sprintf('%s round: %d characters, %.1f s', now()->format('H:i:s'), $characters, self::since($startedAt)));
    }

    public static function moved(int $characterId, ?DateTimeInterface $roundStartedAt): void
    {
        self::push(self::MOVES, sprintf('%s ESI move: character %d, seen %.1f s into the round', now()->format('H:i:s'), $characterId, self::since($roundStartedAt)));
    }

    public static function appMoved(int $characterId): void
    {
        self::push(self::MOVES, sprintf('%s app move: character %d (chat log)', now()->format('H:i:s'), $characterId));
    }

    /**
     * @return list<string>
     */
    public static function lines(string $key): array
    {
        /** @var list<string> $lines */
        $lines = Cache::get($key, []);

        return $lines;
    }

    private static function since(?DateTimeInterface $startedAt): float
    {
        return $startedAt === null ? 0.0 : CarbonImmutable::instance($startedAt)->diffInMilliseconds(now(), true) / 1000;
    }

    private static function push(string $key, string $line): void
    {
        try {
            $lines = self::lines($key);
            $lines[] = $line;
            Cache::put($key, array_slice($lines, -self::KEEP), now()->addDay());
        } catch (Throwable) {
            // Timing is a nice-to-have: never break location tracking over it.
        }
    }
}
