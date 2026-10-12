<?php

declare(strict_types=1);

namespace App\Services\Seat;

use App\Models\Attribute;
use App\Models\Type;
use App\Models\TypeAttribute;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Patch 37: what a ship has fitted, from the assets SeAT keeps (refreshed about hourly).
 * The ship is the one with that item id (EVE says which ship a character sits in), else the
 * only ship of that hull the character owns. Fitted modules are the assets inside it in a
 * slot (LoSlot…, MedSlot…, RigSlot…). Null when SeAT isn't set up, doesn't answer, or the
 * ship can't be told apart.
 */
final class SeatFits
{
    private const int MAX_PAGES = 30;

    /**
     * @return array{higgs: bool, plates: int, plate_mass: float, prop_mass: float, props: list<array{name: string, mass: float}>, entanglers: int, modules: list<array{slot: string, name: string}>}|null
     */
    public function fitFor(int $characterId, int $shipTypeId, ?int $shipItemId): ?array
    {
        if (! SeatMains::configured()) {
            return null;
        }

        $assets = $this->assets($characterId);
        if ($assets === null) {
            return null;
        }

        $itemId = $shipItemId;
        if ($itemId === null || ! array_key_exists($itemId, $assets)) {
            $ships = array_filter($assets, fn (array $asset): bool => $asset['type_id'] === $shipTypeId);
            if (count($ships) !== 1) {
                return null;
            }
            $itemId = (int) array_key_first($ships);
        }

        $fitted = array_filter($assets, fn (array $asset): bool => $asset['location_id'] === $itemId
            && preg_match('/^(LoSlot|MedSlot|HiSlot|RigSlot)\d+$/', $asset['flag']) === 1);
        $empty = ['higgs' => false, 'plates' => 0, 'plate_mass' => 0.0, 'prop_mass' => 0.0, 'props' => [], 'entanglers' => 0, 'modules' => []];
        if ($fitted === []) {
            return $empty;
        }

        $typeIds = array_values(array_unique(array_column($fitted, 'type_id')));
        $types = Type::query()->with('group')->whereIn('id', $typeIds)->get()->keyBy('id');
        $massAdditionId = Attribute::query()->where('name', 'massAddition')->value('id');
        $additions = $massAdditionId === null ? collect() : TypeAttribute::query()
            ->where('attribute_id', $massAdditionId)
            ->whereIn('type_id', $typeIds)
            ->pluck('value', 'type_id');

        $fit = $empty;
        uasort($fitted, fn (array $a, array $b): int => strnatcmp($a['flag'], $b['flag']));
        foreach ($fitted as $asset) {
            $type = $types->get($asset['type_id']);
            $name = $type instanceof Type ? (string) $type->name : sprintf('#%d', $asset['type_id']);
            $fit['modules'][] = ['slot' => (string) preg_replace('/\d+$/', '', $asset['flag']), 'name' => $name];
            if (str_contains($name, 'Higgs Anchor')) {
                $fit['higgs'] = true;

                continue;
            }
            if (str_contains($name, 'Zero-Point Mass Entangler')) {
                $fit['entanglers']++;

                continue;
            }
            $addition = (float) ($additions[$asset['type_id']] ?? 0);
            if ($addition <= 0) {
                continue;
            }
            if ($type instanceof Type && $type->group->name === 'Propulsion Module') {
                // Each prop mod is its own hot jump (one runs at a time).
                $fit['props'][] = ['name' => $name, 'mass' => $addition];
                $fit['prop_mass'] = max($fit['prop_mass'], $addition);
            } else {
                $fit['plates']++;
                $fit['plate_mass'] += $addition;
            }
        }

        return $fit;
    }

    /**
     * Item id → type, location and slot, from SeAT's character assets.
     *
     * @return array<int, array{type_id: int, location_id: int, flag: string}>|null
     */
    private function assets(int $characterId): ?array
    {
        $url = mb_rtrim((string) config('services.seat.url'), '/').sprintf('/api/v2/character/assets/%d', $characterId);
        $items = [];
        $pages = 0;
        try {
            while ($url !== null && $pages < self::MAX_PAGES) {
                $pages++;
                $response = Http::timeout(20)
                    ->acceptJson()
                    ->withHeaders(['X-Token' => (string) config('services.seat.token')])
                    ->get($url);
                if (! $response->successful()) {
                    return null;
                }
                /** @var array<int, array<string, mixed>> $rows */
                $rows = $response->json('data') ?? [];
                foreach ($rows as $row) {
                    $itemId = $row['item_id'] ?? null;
                    $typeId = $row['type_id'] ?? data_get($row, 'type.typeID') ?? data_get($row, 'type.type_id');
                    if (! is_numeric($itemId) || ! is_numeric($typeId)) {
                        continue;
                    }
                    $items[(int) $itemId] = [
                        'type_id' => (int) $typeId,
                        'location_id' => (int) ($row['location_id'] ?? 0),
                        'flag' => (string) ($row['location_flag'] ?? ''),
                    ];
                }
                $next = $response->json('links.next');
                $url = is_string($next) && $next !== '' ? $next : null;
            }
        } catch (Throwable) {
            return null;
        }

        return $items;
    }
}
