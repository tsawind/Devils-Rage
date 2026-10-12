<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Public EVE data about a character that isn't on our SeAT: ID, corporation and
 * alliance, from ESI's public endpoints (no login, no skills, no assets).
 */
final class PublicCharacter
{
    private const ESI = 'https://esi.evetech.net/latest';

    /**
     * @return array{id: int, name: string, corporation: string|null, alliance: string|null}|null null when no character has that exact name
     */
    public function find(string $name): ?array
    {
        try {
            $ids = $this->esi()->post(self::ESI.'/universe/ids/?datasource=tranquility', [trim($name)])->throw()->json('characters') ?? [];
            if ($ids === []) {
                return null;
            }

            $id = (int) $ids[0]['id'];
            $character = $this->esi()->get(self::ESI.'/characters/'.$id.'/?datasource=tranquility')->throw()->json();
            $orgIds = array_values(array_filter([(int) ($character['corporation_id'] ?? 0), (int) ($character['alliance_id'] ?? 0)]));
            $names = $orgIds === [] ? [] : collect($this->esi()->post(self::ESI.'/universe/names/?datasource=tranquility', $orgIds)->throw()->json())
                ->pluck('name', 'id');

            return [
                'id' => $id,
                'name' => (string) $ids[0]['name'],
                'corporation' => isset($character['corporation_id']) ? ($names[(int) $character['corporation_id']] ?? null) : null,
                'alliance' => isset($character['alliance_id']) ? ($names[(int) $character['alliance_id']] ?? null) : null,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function esi(): PendingRequest
    {
        return Http::acceptJson()->timeout(10)->withHeaders(['User-Agent' => "Devil's Rage SeAT plugin"]);
    }
}
