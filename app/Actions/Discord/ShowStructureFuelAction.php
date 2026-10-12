<?php

declare(strict_types=1);

namespace App\Actions\Discord;

use App\Models\Solarsystem;
use App\Services\Seat\SeatClient;
use App\Services\Seat\SeatForbiddenException;
use App\Services\Seat\SeatUnavailableException;

/**
 * "/fuel" and the daily fuel post: corp structures, soonest out of fuel first.
 */
final readonly class ShowStructureFuelAction
{
    private const int STRUCTURES = 25;

    private const int RED_HOURS = 72;

    private const int YELLOW_HOURS = 168;

    public function __construct(private SeatClient $seat) {}

    /**
     * @throws SeatUnavailableException when SeAT can't be asked (the daily post retries tomorrow)
     */
    public function summary(string $discordUserId): string
    {
        try {
            $structures = $this->seat->fuel($discordUserId);
        } catch (SeatForbiddenException $exception) {
            return $exception->getMessage();
        }

        if ($structures === null) {
            return SeatMessages::NOT_LINKED;
        }

        if ($structures === []) {
            return 'SeAT has no corp structures yet (it needs a director or station manager token to sync them).';
        }

        $systems = Solarsystem::query()->whereIn('id', array_column($structures, 'system_id'))->pluck('name', 'id');
        $low = count(array_filter($structures, fn (array $structure): bool => $structure['hours_left'] !== null && $structure['hours_left'] < self::YELLOW_HOURS));

        $lines = [sprintf('⛽ **Structure fuel** · %d structures, %d under a week', count($structures), $low)];
        foreach (array_slice($structures, 0, self::STRUCTURES) as $structure) {
            $hours = $structure['hours_left'];
            $lines[] = sprintf(
                '%s **%s** (%s, %s) · %s',
                match (true) {
                    $hours === null => '⚫',
                    $hours < self::RED_HOURS => '🔴',
                    $hours < self::YELLOW_HOURS => '🟡',
                    default => '🟢',
                },
                $structure['name'],
                $structure['type'],
                $systems[$structure['system_id']] ?? $structure['system_id'],
                $hours === null ? 'no fuel (low power)' : self::duration($hours).' left',
            );
        }

        if (count($structures) > self::STRUCTURES) {
            $lines[] = sprintf('…and %d more.', count($structures) - self::STRUCTURES);
        }

        return implode("\n", $lines);
    }

    public function handle(string $discordUserId): string
    {
        try {
            return $this->summary($discordUserId);
        } catch (SeatUnavailableException $exception) {
            return 'SeAT could not be asked: '.$exception->getMessage();
        }
    }

    private static function duration(int $hours): string
    {
        return $hours < 48 ? $hours.'h' : intdiv($hours, 24).'d '.($hours % 24).'h';
    }
}
