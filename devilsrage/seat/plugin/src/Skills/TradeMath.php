<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Skills;

/**
 * Market fees and what one Large Skill Injector earns. Pure PHP, testable on its own.
 *
 * Sales tax: 7.5 % base, Accounting takes 11 % of it per level (3.375 % at V), the
 * same in NPC stations and Upwell structures. Broker fee in an NPC station such as
 * Jita 4-4: 3 % base, Broker Relations -0.3 % per level (1.5 % at V, before
 * standings). In a citadel the owner sets the broker fee (the page lets leaders type
 * it, including any surcharge).
 */
final class TradeMath
{
    public static function salesTax(int $accounting): float
    {
        return 0.075 * (1 - 0.11 * max(0, min(5, $accounting)));
    }

    public static function npcBrokerFee(int $brokerRelations): float
    {
        return max(0.0, 0.03 - 0.003 * max(0, min(5, $brokerRelations)));
    }

    /**
     * ISK left from one extraction: sell the injector, minus what the extractor cost.
     *
     * instant = sell the injector into the best buy order, buy the extractor off the cheapest sell order (no broker fee);
     * patient = put up a sell order for the injector and a buy order for the extractor (broker fee on both).
     *
     * @param  array{buy: float, sell: float}  $injector
     * @param  array{buy: float, sell: float}  $extractor
     * @return array{income: float, extractor_cost: float, profit: float}
     */
    public static function perInjector(string $mode, array $injector, array $extractor, float $salesTax, float $brokerFee): array
    {
        if ($mode === 'patient') {
            $income = $injector['sell'] * (1 - $salesTax - $brokerFee);
            $cost = $extractor['buy'] * (1 + $brokerFee);
        } else {
            $income = $injector['buy'] * (1 - $salesTax);
            $cost = $extractor['sell'];
        }

        return ['income' => $income, 'extractor_cost' => $cost, 'profit' => $income - $cost];
    }

    /**
     * Skill points per minute from two attributes (Omega).
     */
    public static function spPerMinute(int $primary, int $secondary): float
    {
        return $primary + $secondary / 2;
    }
}
