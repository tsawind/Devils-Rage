<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Fits;

/**
 * Turns an in-game (ESI) fitting as SeAT stores it into seat-fitting's item rows.
 *
 * SeAT keeps ESI's slot names ("LoSlot0", "DroneBay", ...); seat-fitting uses EVE's
 * numeric inventory flags (low 11-18, mid 19-26, high 27-34, rigs 92-99,
 * subsystems 125-132, drone bay 87, fighter bay 158, cargo 5).
 *
 * Pure PHP (no Laravel), so it can be tested on its own.
 */
final class EsiFitConverter
{
    public const FLAG_CARGO = 5;

    public const FLAG_DRONE_BAY = 87;

    public const FLAG_FIGHTER_BAY = 158;

    /** @var array<string, array{0: int, 1: int}> slot name prefix => [first flag, slot count] */
    private const SLOT_RANGES = [
        'LoSlot' => [11, 8],
        'MedSlot' => [19, 8],
        'HiSlot' => [27, 8],
        'RigSlot' => [92, 8],
        'SubSystemSlot' => [125, 8],
    ];

    /** @var array<int, true> numeric flags seat-fitting understands */
    private const KNOWN_NUMERIC = [self::FLAG_CARGO => true, self::FLAG_DRONE_BAY => true, self::FLAG_FIGHTER_BAY => true];

    /**
     * @param  iterable<array{type_id: int|string, flag: int|string, quantity: int|string}>  $esiItems
     * @return array{items: list<array{type_id: int, flag: int, quantity: int}>, skipped: int}
     */
    public function convert(iterable $esiItems): array
    {
        $items = [];
        $skipped = 0;

        foreach ($esiItems as $item) {
            $flag = $this->flag((string) $item['flag']);
            $quantity = (int) $item['quantity'];

            if ($flag === null || $quantity < 1) {
                $skipped++;

                continue;
            }

            $items[] = ['type_id' => (int) $item['type_id'], 'flag' => $flag, 'quantity' => $quantity];
        }

        usort($items, fn (array $a, array $b): int => [$a['flag'], $a['type_id'], $a['quantity']] <=> [$b['flag'], $b['type_id'], $b['quantity']]);

        return ['items' => $items, 'skipped' => $skipped];
    }

    /**
     * seat-fitting flag for an ESI slot name, or null for slots seat-fitting can't show
     * (structure service slots, "Invalid", ...).
     */
    public function flag(string $esiFlag): ?int
    {
        if (ctype_digit($esiFlag)) {
            $numeric = (int) $esiFlag;

            foreach (self::SLOT_RANGES as [$first, $count]) {
                if ($numeric >= $first && $numeric < $first + $count) {
                    return $numeric;
                }
            }

            return isset(self::KNOWN_NUMERIC[$numeric]) ? $numeric : null;
        }

        foreach (self::SLOT_RANGES as $prefix => [$first, $count]) {
            if (preg_match('/^'.$prefix.'(\d+)$/', $esiFlag, $match) === 1) {
                $index = (int) $match[1];

                return $index < $count ? $first + $index : null;
            }
        }

        return match (true) {
            $esiFlag === 'DroneBay' => self::FLAG_DRONE_BAY,
            $esiFlag === 'FighterBay', str_starts_with($esiFlag, 'FighterTube') => self::FLAG_FIGHTER_BAY,
            $esiFlag === 'Cargo' => self::FLAG_CARGO,
            default => null,
        };
    }

    /**
     * Fit name as shown in SeAT: the library prefix removed. Null when the fit doesn't
     * carry the prefix (so it is not part of the library).
     */
    public function libraryName(string $name, string $prefix): ?string
    {
        $name = trim($name);
        $prefix = trim($prefix);

        if ($prefix === '') {
            return $name === '' ? null : $name;
        }

        if (strncasecmp($name, $prefix, strlen($prefix)) !== 0) {
            return null;
        }

        $stripped = trim(substr($name, strlen($prefix)));

        return $stripped === '' ? null : $stripped;
    }

    /** Key that identifies "the same fit" across in-game edits: hull + name, case-insensitive. */
    public function matchKey(int $shipTypeId, string $name): string
    {
        return $shipTypeId.':'.mb_strtolower(trim($name));
    }

    /**
     * @param  list<array{type_id: int, flag: int, quantity: int}>  $items  as returned by convert()
     */
    public function fingerprint(int $shipTypeId, string $name, string $description, array $items): string
    {
        return sha1(json_encode([$shipTypeId, $name, $description, $items], JSON_THROW_ON_ERROR));
    }
}
