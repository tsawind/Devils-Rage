<?php

declare(strict_types=1);

namespace App\Actions\Discord;

use App\Services\Seat\SeatClient;
use App\Services\Seat\SeatUnavailableException;

/**
 * "/fit" and "/canfly": find one corp fit (seat-fitting, via the Devil's Rage SeAT) by name.
 */
final readonly class ShowSeatFitAction
{
    private const int EFT_LIMIT = 1700;

    public function __construct(private SeatClient $seat) {}

    public function handle(string $search): string
    {
        try {
            $found = $this->find($search);
            if (is_string($found)) {
                return $found;
            }

            $fit = $this->seat->fit($found['id']);
        } catch (SeatUnavailableException $exception) {
            return 'SeAT could not be asked: '.$exception->getMessage();
        }

        if ($fit === null) {
            return 'That fit is gone from SeAT.';
        }

        $eft = mb_strlen($fit['eft']) > self::EFT_LIMIT ? mb_substr($fit['eft'], 0, self::EFT_LIMIT)."\n…" : $fit['eft'];

        return sprintf("**%s** (%s) · copy and import in EVE's fitting window:\n```\n%s\n```", $fit['name'], $fit['ship'], $eft);
    }

    /**
     * The one fit meant by the search, or the reply to send instead.
     *
     * @return array{id: int, name: string, ship: string}|string
     *
     * @throws SeatUnavailableException
     */
    public function find(string $search): array|string
    {
        $search = trim($search);
        $fits = $this->seat->fits($search);
        if ($fits === []) {
            return sprintf('No corp fit matches "%s". Fits come from SeAT → Fittings.', $search);
        }

        foreach ($fits as $fit) {
            if (strcasecmp($fit['name'], $search) === 0) {
                return $fit;
            }
        }

        if (count($fits) === 1) {
            return $fits[0];
        }

        $names = array_map(fn (array $fit): string => sprintf('%s (%s)', $fit['name'], $fit['ship']), array_slice($fits, 0, 10));

        return "Several fits match, use the exact name:\n• ".implode("\n• ", $names);
    }
}
