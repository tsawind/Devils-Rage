<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use Illuminate\Database\Eloquent\Builder;
use Seat\Eveapi\Models\Assets\CharacterAsset;
use Seat\Eveapi\Models\Character\CharacterInfo;

/**
 * Search all of a pilot's characters' assets by item name, with where each item is
 * (system and station / structure) and what it sits in (container or ship).
 */
final class AssetSearch
{
    public const LIMIT = 300;

    private const CATEGORY_SHIP = 6;

    public function __construct(private readonly Locations $locations) {}

    /**
     * @param  list<int>  $characterIds
     * @return list<array{character: string, item: string, type_id: int, quantity: int, value: float, system: string, location: string, inside: string|null, is_ship: bool}>
     */
    public function search(array $characterIds, string $query, bool $shipsOnly): array
    {
        $query = trim($query);
        if ($characterIds === [] || ($query === '' && ! $shipsOnly)) {
            return [];
        }

        $columns = [
            'character_assets.item_id', 'character_assets.character_id', 'character_assets.type_id', 'character_assets.quantity',
            'character_assets.location_id', 'character_assets.location_type', 'character_assets.location_flag', 'character_assets.name',
            'invTypes.typeName', 'invGroups.categoryID', 'market_prices.adjusted_price',
        ];
        $rows = $this->base($characterIds)
            ->when($query !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('invTypes.typeName', 'like', '%'.addcslashes($query, '%_\\').'%')
                ->orWhere('character_assets.name', 'like', '%'.addcslashes($query, '%_\\').'%')))
            ->when($shipsOnly, fn ($q) => $q->where('invGroups.categoryID', self::CATEGORY_SHIP))
            ->orderBy('invTypes.typeName')
            ->limit(self::LIMIT)
            ->get($columns);

        // Walk up through containers and ships (at most 4 levels) to the station / structure / space.
        $parents = collect();
        $pending = $rows->pluck('location_id')->unique()->values();
        for ($level = 0; $level < 4 && $pending->isNotEmpty(); $level++) {
            $found = $this->base($characterIds)->whereIn('character_assets.item_id', $pending->all())->get($columns)->keyBy('item_id');
            $parents = $parents->union($found);
            $pending = $found->pluck('location_id')->unique()->diff($parents->keys())->values();
        }

        $tops = [];
        $resolved = $rows->map(function ($row) use ($parents, &$tops): array {
            $inside = null;
            $cursor = $row;
            $guard = 0;
            while ($parents->has($cursor->location_id) && $guard++ < 5) {
                $cursor = $parents->get($cursor->location_id);
                $inside ??= trim(((string) $cursor->name !== '' && $cursor->name !== null ? $cursor->name.' · ' : '').$cursor->typeName);
            }
            $tops[] = (int) $cursor->location_id;

            return ['row' => $row, 'inside' => $inside, 'top' => (int) $cursor->location_id];
        });

        $places = $this->locations->resolve($tops);
        $systems = $this->locations->systems(array_values(array_filter(array_column($places, 'system_id'))));
        $names = CharacterInfo::query()->whereIn('character_id', $characterIds)->pluck('name', 'character_id');

        return $resolved->map(function (array $hit) use ($places, $systems, $names): array {
            $row = $hit['row'];
            $place = $places[$hit['top']] ?? null;
            $systemId = $place['system_id'] ?? null;

            return [
                'character' => (string) ($names[$row->character_id] ?? $row->character_id),
                'item' => (string) ($row->typeName ?? 'Unknown').(($row->name ?? '') !== '' ? ' "'.$row->name.'"' : ''),
                'type_id' => (int) $row->type_id,
                'quantity' => max(1, (int) $row->quantity),
                'value' => max(1, (int) $row->quantity) * (float) ($row->adjusted_price ?? 0),
                'system' => $systemId === null ? '?' : ($systems[$systemId] ?? (string) $systemId),
                'location' => $place['name'] ?? 'Unknown location '.$hit['top'],
                'inside' => $hit['inside'],
                'is_ship' => (int) ($row->categoryID ?? 0) === self::CATEGORY_SHIP,
            ];
        })->sortBy([['system', 'asc'], ['location', 'asc'], ['item', 'asc']])->values()->all();
    }

    /**
     * @param  list<int>  $characterIds
     */
    private function base(array $characterIds): Builder
    {
        return CharacterAsset::query()
            ->whereIn('character_assets.character_id', $characterIds)
            ->leftJoin('invTypes', 'invTypes.typeID', '=', 'character_assets.type_id')
            ->leftJoin('invGroups', 'invGroups.groupID', '=', 'invTypes.groupID')
            ->leftJoin('market_prices', 'market_prices.type_id', '=', 'character_assets.type_id');
    }
}
