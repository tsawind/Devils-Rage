<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Item prices from SeAT's market_prices: the market's average price (what EVE shows as the
 * estimated price), else the sell price, else CCP's adjusted price (which is often far lower
 * for T2 / faction hulls and modules).
 */
final class Prices
{
    private static ?string $expression = null;

    /** SQL for one row's price, e.g. inside a select or a sum. */
    public static function expression(): string
    {
        if (self::$expression === null) {
            $parts = [];
            foreach (['average_price', 'sell_price', 'adjusted_price'] as $column) {
                if (Schema::hasColumn('market_prices', $column)) {
                    $parts[] = "NULLIF(market_prices.{$column}, 0)";
                }
            }
            self::$expression = $parts === [] ? '0' : 'COALESCE('.implode(', ', $parts).', 0)';
        }

        return self::$expression;
    }

    /**
     * @param  array<int, int>  $typeIds
     * @return array<int, float>
     */
    public static function forTypes(array $typeIds): array
    {
        if ($typeIds === []) {
            return [];
        }

        return DB::table('market_prices')->whereIn('type_id', array_values(array_unique($typeIds)))
            ->selectRaw('type_id, '.self::expression().' as price')->pluck('price', 'type_id')
            ->map(fn ($p) => (float) $p)->all();
    }
}
