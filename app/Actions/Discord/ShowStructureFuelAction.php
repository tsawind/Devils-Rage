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
        $towers = count(array_filter($structures, fn (array $structure): bool => ($structure['kind'] ?? 'structure') === 'pos'));

        // Towers alike (same type, system, state, fuel and strontium) share one line.
        $groups = [];
        foreach ($structures as $structure) {
            $isPos = ($structure['kind'] ?? 'structure') === 'pos';
            $key = $isPos
                ? implode('|', ['pos', $structure['type'], $structure['system_id'], $structure['state'], $structure['hours_left'] ?? '-', $structure['strontium_hours'] ?? '-'])
                : 'structure|'.count($groups);
            $groups[$key] ??= ['structure' => $structure, 'names' => []];
            $groups[$key]['names'][] = $structure['name'];
        }

        $lines = [sprintf(
            '⛽ **Structure fuel** · %d structure%s%s, %d under a week',
            count($structures) - $towers,
            count($structures) - $towers === 1 ? '' : 's',
            $towers > 0 ? ', '.$towers.' POS' : '',
            $low,
        )];
        foreach (array_slice(array_values($groups), 0, self::STRUCTURES) as $group) {
            $structure = $group['structure'];
            $hours = $structure['hours_left'];
            $system = $systems[$structure['system_id']] ?? $structure['system_id'];
            $isPos = ($structure['kind'] ?? 'structure') === 'pos';
            $what = $isPos && count($group['names']) > 1
                ? sprintf('**%d× %s** (%s: %s)', count($group['names']), $structure['type'], $system, self::moons($group['names'], (string) $system))
                : sprintf('**%s** (%s, %s)', $structure['name'], $structure['type'], $system);
            $lines[] = sprintf(
                '%s %s · %s%s',
                match (true) {
                    $hours === null => '⚫',
                    $hours < self::RED_HOURS => '🔴',
                    $hours < self::YELLOW_HOURS => '🟡',
                    default => '🟢',
                },
                $what,
                match (true) {
                    $hours !== null => self::duration($hours).' left',
                    $isPos => $structure['state'].', not burning fuel',
                    default => 'no fuel (low power)',
                },
                $isPos && ($structure['strontium_hours'] ?? null) !== null ? ' · stront '.$structure['strontium_hours'].'h' : '',
            );
        }

        $structures = array_values($groups);
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

    /**
     * "VII-4, VII-3, VIII-1" from "J145735 VII - Moon 4", ...
     *
     * @param  list<string>  $names
     */
    private static function moons(array $names, string $system): string
    {
        $short = array_map(
            fn (string $name): string => (string) preg_replace('/\s*-\s*Moon\s*/i', '-', trim(str_replace($system, '', $name))),
            $names,
        );

        return implode(', ', array_slice($short, 0, 8)).(count($short) > 8 ? ' …' : '');
    }

    private static function duration(int $hours): string
    {
        return $hours < 48 ? $hours.'h' : intdiv($hours, 24).'d '.($hours % 24).'h';
    }
}
