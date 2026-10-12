<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Best buy order and cheapest sell order in Jita 4-4, from Fuzzwork's free market
 * aggregates (cached for an hour). When Fuzzwork can't be reached or has no orders,
 * SeAT's own market average is used for both sides.
 */
final class JitaPrices
{
    private const URL = 'https://market.fuzzwork.co.uk/aggregates/';

    private const JITA_44 = 60003760;

    private const CACHE_SECONDS = 3600;

    /**
     * @param  list<int>  $typeIds
     * @return array<int, array{buy: float, sell: float, source: string}>
     */
    public function get(array $typeIds): array
    {
        $typeIds = array_values(array_unique(array_map('intval', $typeIds)));
        if ($typeIds === []) {
            return [];
        }

        sort($typeIds);
        $orders = Cache::remember('devilsrage.jita.'.md5(implode(',', $typeIds)), self::CACHE_SECONDS, fn (): array => $this->fetch($typeIds));
        $average = DB::table('market_prices')
            ->whereIn('type_id', $typeIds)
            ->get(['type_id', 'average', 'average_price'])
            ->mapWithKeys(fn ($row): array => [(int) $row->type_id => (float) ($row->average > 0 ? $row->average : $row->average_price)]);

        $prices = [];
        foreach ($typeIds as $typeId) {
            $buy = (float) ($orders[$typeId]['buy'] ?? 0);
            $sell = (float) ($orders[$typeId]['sell'] ?? 0);
            $prices[$typeId] = $buy > 0 && $sell > 0
                ? ['buy' => $buy, 'sell' => $sell, 'source' => 'Jita 4-4 orders']
                : ['buy' => (float) ($average[$typeId] ?? 0), 'sell' => (float) ($average[$typeId] ?? 0), 'source' => 'market average'];
        }

        return $prices;
    }

    /**
     * @param  list<int>  $typeIds
     * @return array<int, array{buy: float, sell: float}>
     */
    private function fetch(array $typeIds): array
    {
        try {
            $response = Http::timeout(8)->acceptJson()->get(self::URL, [
                'station' => self::JITA_44,
                'types' => implode(',', $typeIds),
            ]);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $orders = [];
        foreach ((array) $response->json() as $typeId => $row) {
            $orders[(int) $typeId] = [
                'buy' => (float) ($row['buy']['max'] ?? 0),
                'sell' => (float) ($row['sell']['min'] ?? 0),
            ];
        }

        return $orders;
    }
}
