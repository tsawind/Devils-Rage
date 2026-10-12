<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use Seat\Eveapi\Models\Character\CharacterAffiliation;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Eveapi\Models\RefreshToken;

/**
 * The corporation this SeAT is for: DR_CORPORATION_ID, or else the corporation most
 * pilots with a SeAT login are in.
 */
final class Corporation
{
    private ?int $mainId = null;

    public function mainId(): ?int
    {
        if ($this->mainId !== null) {
            return $this->mainId;
        }

        $configured = trim((string) config('devilsrage.corporation_id', ''));
        if (ctype_digit($configured)) {
            return $this->mainId = (int) $configured;
        }

        $id = CharacterAffiliation::query()
            ->whereIn('character_id', RefreshToken::query()->select('character_id'))
            ->selectRaw('corporation_id, count(*) as pilots')
            ->groupBy('corporation_id')
            ->orderByDesc('pilots')
            ->value('corporation_id');

        return $this->mainId = $id === null ? null : (int) $id;
    }

    public function ceoId(): ?int
    {
        $id = $this->mainId();
        $ceo = $id === null ? null : CorporationInfo::query()->where('corporation_id', $id)->value('ceo_id');

        return $ceo === null ? null : (int) $ceo;
    }

    /**
     * Character IDs (with a SeAT login) currently in the main corporation.
     *
     * @return list<int>
     */
    public function memberCharacterIds(): array
    {
        $id = $this->mainId();
        if ($id === null) {
            return [];
        }

        return CharacterAffiliation::query()
            ->where('corporation_id', $id)
            ->whereIn('character_id', RefreshToken::query()->select('character_id'))
            ->pluck('character_id')
            ->map(fn ($characterId): int => (int) $characterId)
            ->all();
    }
}
