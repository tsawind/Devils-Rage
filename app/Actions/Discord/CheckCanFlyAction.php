<?php

declare(strict_types=1);

namespace App\Actions\Discord;

use App\Services\Seat\SeatClient;
use App\Services\Seat\SeatUnavailableException;

/**
 * "/canfly": which of the pilot's characters can fly a corp fit, and what the others miss.
 */
final readonly class CheckCanFlyAction
{
    private const int MAX_CHARACTERS = 12;

    public function __construct(
        private SeatClient $seat,
        private ShowSeatFitAction $fits,
    ) {}

    public function handle(string $discordUserId, string $search): string
    {
        try {
            $found = $this->fits->find($search);
            if (is_string($found)) {
                return $found;
            }

            $result = $this->seat->canFly($discordUserId, $found['id']);
        } catch (SeatUnavailableException $exception) {
            return 'SeAT could not be asked: '.$exception->getMessage();
        }

        if ($result === null) {
            return SeatMessages::NOT_LINKED;
        }

        if ($result['characters'] === []) {
            return 'SeAT has no characters for you yet.';
        }

        $lines = array_map(function (array $character): string {
            if ($character['can_fly']) {
                return sprintf('✅ **%s** can fly it', $character['character']);
            }

            return sprintf(
                '%s **%s** · %d %s, ≈%s · %s%s',
                $character['close'] ? '🟡' : '🔴',
                $character['character'],
                $character['missing_count'],
                $character['missing_count'] === 1 ? 'skill' : 'skills',
                $character['train_time'],
                implode(', ', $character['missing']),
                $character['missing_count'] > count($character['missing']) ? ' …' : '',
            );
        }, array_slice($result['characters'], 0, self::MAX_CHARACTERS));

        return implode("\n", [sprintf('**%s** (%s)', $result['fit']['name'], $result['fit']['ship']), ...$lines]);
    }
}
