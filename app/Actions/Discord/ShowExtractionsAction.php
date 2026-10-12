<?php

declare(strict_types=1);

namespace App\Actions\Discord;

use App\Services\Seat\AssetProximity;
use App\Services\Seat\SeatClient;
use App\Services\Seat\SeatUnavailableException;

/**
 * "/extract": skill points the pilot could turn into injectors right now without touching
 * anything a corp fit, skill plan or the keep list needs (worked out in SeAT).
 */
final readonly class ShowExtractionsAction
{
    private const int CHARACTERS = 10;

    public function __construct(private SeatClient $seat) {}

    public function handle(string $discordUserId): string
    {
        try {
            $data = $this->seat->extractions($discordUserId);
        } catch (SeatUnavailableException $exception) {
            return 'SeAT could not be asked: '.$exception->getMessage();
        }

        if ($data === null) {
            return SeatMessages::NOT_LINKED;
        }

        if ($data['characters'] === []) {
            return 'Nothing to extract without touching protected skills (or no character is above 5m SP).';
        }

        $count = array_sum(array_column($data['characters'], 'count'));
        $lines = [sprintf(
            '**Quick ISK** · %d extractions ≈ %s ISK (sold into Jita buy orders, %s each after the extractor)',
            $count,
            AssetProximity::isk($count * $data['per_extraction']),
            AssetProximity::isk($data['per_extraction']),
        )];

        foreach (array_slice($data['characters'], 0, self::CHARACTERS) as $character) {
            $skills = array_map(fn (array $skill): string => $skill['name'].' '.$skill['level'], array_slice($character['skills'], 0, 3));
            $lines[] = sprintf(
                '• **%s** · %d× ≈ %s · retrain %s · from %s',
                $character['character'],
                $character['count'],
                AssetProximity::isk($character['isk']),
                $character['retrain_days'] === null ? '?' : round($character['retrain_days']).' days',
                implode(', ', $skills),
            );
        }

        if (count($data['characters']) > self::CHARACTERS) {
            $lines[] = sprintf('…and %d more characters.', count($data['characters']) - self::CHARACTERS);
        }

        $lines[] = '-# Never offered: skills corp fits or skill plans need, freighters, Minmatar Dreadnought, Capital Ships, Cybernetics, Biology, PI. Details: SeAT → SP farming.';

        return implode("\n", $lines);
    }
}
