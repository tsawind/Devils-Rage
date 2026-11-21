<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Character;
use App\Models\EsiToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use NicolasKion\Esi\DTO\JumpFatigue;
use NicolasKion\Esi\Enums\EsiScope;
use NicolasKion\Esi\Esi;
use Throwable;

/**
 * 20 Nov: a pilot's jump fatigue, read with the Jump fatigue permission (cached two minutes;
 * EVE itself refreshes it every five). Null when the pilot hasn't granted it or ESI fails.
 */
final readonly class PilotJumpFatigue
{
    public function __construct(private Esi $esi) {}

    /**
     * @return array{ends: string|null, last_jump: string|null}|null fatigue end (null when none left) and the last jump, as ISO times
     */
    public function of(Character $character): ?array
    {
        if (! $character->getEsiTokenWithScope(EsiScope::ReadFatigue) instanceof EsiToken) {
            return null;
        }

        /** @var array{ends: string|null, last_jump: string|null}|null $read */
        $read = Cache::remember(sprintf('pilot-jump-fatigue:%d', $character->id), now()->addMinutes(2), function () use ($character): ?array {
            try {
                $result = $this->esi->getCharacterFatigue($character);
            } catch (Throwable) {
                return null;
            }
            /** @var mixed $data */
            $data = $result->data;
            if ($result->failed() || ! $data instanceof JumpFatigue) {
                return null;
            }

            return [
                'ends' => self::iso($data->jump_fatigue_expire_date),
                'last_jump' => self::iso($data->last_jump_date),
            ];
        });
        if ($read === null) {
            return null;
        }
        // Fatigue that has run out since it was read counts as none.
        if ($read['ends'] !== null && Carbon::parse($read['ends'])->isPast()) {
            $read['ends'] = null;
        }

        return $read;
    }

    private static function iso(?string $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }
        try {
            return Carbon::parse($time)->utc()->toIso8601ZuluString();
        } catch (Throwable) {
            return null;
        }
    }
}
