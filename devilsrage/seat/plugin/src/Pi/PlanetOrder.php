<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Pi;

/**
 * Planet names in the order they sit in the system: "J145735 I, II, III, IV … IX, X", not
 * the text order "I, II, IV, IX, V" (which is what strnatcmp gives Roman numerals).
 */
final class PlanetOrder
{
    private const NUMERALS = ['M' => 1000, 'D' => 500, 'C' => 100, 'L' => 50, 'X' => 10, 'V' => 5, 'I' => 1];

    /** Compare two planet names: system first, then the planet's number. */
    public static function compare(string $a, string $b): int
    {
        [$systemA, $numberA] = self::split($a);
        [$systemB, $numberB] = self::split($b);

        return strnatcasecmp($systemA, $systemB) ?: $numberA <=> $numberB ?: strnatcasecmp($a, $b);
    }

    /**
     * "J145735 IX" => ["J145735", 9]. A name that doesn't end in a numeral keeps its whole name
     * and sorts after the numbered ones of its system.
     *
     * @return array{0: string, 1: int}
     */
    public static function split(string $name): array
    {
        $name = trim($name);
        if (preg_match('/^(.*\S)\s+([IVXLCDM]+)$/', $name, $match) === 1) {
            return [$match[1], self::number($match[2])];
        }

        return [$name, PHP_INT_MAX];
    }

    /** A Roman numeral's value ("IX" => 9). */
    public static function number(string $numeral): int
    {
        $total = 0;
        $digits = str_split(strtoupper($numeral));
        foreach ($digits as $i => $digit) {
            $value = self::NUMERALS[$digit] ?? 0;
            $next = self::NUMERALS[$digits[$i + 1] ?? ''] ?? 0;
            $total += $value < $next ? -$value : $value;
        }

        return $total;
    }
}
