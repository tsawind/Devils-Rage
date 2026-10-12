<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use DevilsRage\Seat\Skills\TradeMath;
use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\Character\CharacterSkill;

/**
 * What one extraction leaves after selling the injector into Jita buy orders and
 * buying the extractor off the market, with the pilot's best Accounting.
 */
final class ExtractionValue
{
    public const TYPE_INJECTOR = 40520;

    public const TYPE_EXTRACTOR = 40519;

    public function __construct(private readonly JitaPrices $prices) {}

    /**
     * @param  list<int>  $characterIds
     */
    public function instantJita(array $characterIds): float
    {
        $accountingId = DB::table('invTypes')->where('typeName', 'Accounting')->value('typeID');
        $accounting = $accountingId === null || $characterIds === [] ? 0 : (int) CharacterSkill::query()
            ->whereIn('character_id', $characterIds)
            ->where('skill_id', $accountingId)
            ->max('trained_skill_level');

        $orders = $this->prices->get([self::TYPE_INJECTOR, self::TYPE_EXTRACTOR]);
        $injector = $orders[self::TYPE_INJECTOR] ?? ['buy' => 0.0, 'sell' => 0.0];
        $extractor = $orders[self::TYPE_EXTRACTOR] ?? ['buy' => 0.0, 'sell' => 0.0];

        return TradeMath::perInjector('instant', $injector, $extractor, TradeMath::salesTax($accounting), 0.0)['profit'];
    }
}
