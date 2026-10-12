<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use DevilsRage\Seat\Models\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Seat\Eveapi\Models\Assets\CharacterAsset;

/**
 * PLEX and ISK a pilot holds. PLEX items in hangars, containers and ships come from
 * SeAT's asset sync; the PLEX vault isn't in ESI, so it's the number typed per account.
 */
final class Holdings
{
    public const TYPE_PLEX = 44992;

    /**
     * @param  list<int>  $characterIds
     * @return array<int, array{plex: int, isk: float}> per character
     */
    public function characters(array $characterIds): array
    {
        if ($characterIds === []) {
            return [];
        }

        $plex = CharacterAsset::query()
            ->whereIn('character_id', $characterIds)
            ->where('type_id', self::TYPE_PLEX)
            ->groupBy('character_id')
            ->selectRaw('character_id, SUM(quantity) as total')
            ->pluck('total', 'character_id');

        $isk = Schema::hasTable('character_wallet_balances')
            ? DB::table('character_wallet_balances')->whereIn('character_id', $characterIds)->pluck('balance', 'character_id')
            : collect();

        $result = [];
        foreach ($characterIds as $characterId) {
            $result[$characterId] = [
                'plex' => (int) ($plex[$characterId] ?? 0),
                'isk' => (float) ($isk[$characterId] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @param  iterable<Account>  $accounts  with characters loaded
     * @param  array<int, array{plex: int, isk: float}>  $characters  from characters()
     * @return array<int, array{hangar: int, vault: int|null, vault_at: \Illuminate\Support\Carbon|null, plex: int, isk: float}> per account
     */
    public function accounts(iterable $accounts, array $characters): array
    {
        $result = [];
        foreach ($accounts as $account) {
            $hangar = 0;
            $isk = 0.0;
            foreach ($account->characters as $link) {
                $hangar += $characters[(int) $link->character_id]['plex'] ?? 0;
                $isk += $characters[(int) $link->character_id]['isk'] ?? 0.0;
            }

            $result[$account->id] = [
                'hangar' => $hangar,
                'vault' => $account->plex_vault,
                'vault_at' => $account->plex_vault_at,
                'plex' => $hangar + (int) $account->plex_vault,
                'isk' => $isk,
            ];
        }

        return $result;
    }
}
