<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\Assets\CharacterAsset;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Character\CharacterSkill;

/**
 * Where characters' things are, by solar system, from the assets SeAT already syncs.
 */
final class AssetLocator
{
    /** EVE category of ships. */
    private const CATEGORY_SHIP = 6;

    /** Jump Drive Calibration skill. */
    private const SKILL_JDC = 21611;

    /** Ship group ID => the mapper's jump ship class. */
    public const JUMP_SHIP_GROUPS = [
        485 => 'dreadnought',
        4594 => 'dreadnought',
        547 => 'carrier',
        1538 => 'force_auxiliary',
        659 => 'supercarrier',
        30 => 'titan',
        902 => 'jump_freighter',
        883 => 'rorqual',
        898 => 'black_ops',
    ];

    /**
     * Assets grouped by solar system.
     *
     * @param  list<int>  $characterIds
     * @return list<array{system_id: int, items: int, value: float, ships: list<string>}>
     */
    public function systems(array $characterIds, bool $shipsOnly = false): array
    {
        $assets = $this->assets($characterIds);
        $systems = [];

        foreach ($assets as $asset) {
            $systemId = $asset['system_id'];
            if ($systemId === null) {
                continue;
            }
            if ($shipsOnly && ! $asset['is_ship']) {
                continue;
            }

            $systems[$systemId] ??= ['system_id' => $systemId, 'items' => 0, 'value' => 0.0, 'ships' => []];
            $systems[$systemId]['items'] += $asset['quantity'];
            $systems[$systemId]['value'] += $asset['quantity'] * $asset['price'];
            if ($asset['is_ship'] && $asset['top_level']) {
                $systems[$systemId]['ships'][] = $asset['type_name'];
            }
        }

        usort($systems, fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return array_map(function (array $system): array {
            $system['ships'] = array_values(array_unique($system['ships']));

            return $system;
        }, $systems);
    }

    /**
     * Ships with a jump drive (and Black Ops), where they are parked or flown.
     *
     * @param  list<int>  $characterIds
     * @return list<array{character: string, ship: string, jump_class: string, system_id: int, jdc: int}>
     */
    public function jumpShips(array $characterIds): array
    {
        $names = CharacterInfo::query()->whereIn('character_id', $characterIds)->pluck('name', 'character_id');
        $jdc = CharacterSkill::query()
            ->whereIn('character_id', $characterIds)
            ->where('skill_id', self::SKILL_JDC)
            ->pluck('trained_skill_level', 'character_id');

        $ships = [];
        foreach ($this->assets($characterIds) as $asset) {
            $class = self::JUMP_SHIP_GROUPS[$asset['group_id']] ?? null;
            if ($class === null || $asset['system_id'] === null || ! $asset['top_level']) {
                continue;
            }

            $ships[] = [
                'character' => (string) ($names[$asset['character_id']] ?? $asset['character_id']),
                'ship' => $asset['type_name'],
                'jump_class' => $class,
                'system_id' => $asset['system_id'],
                'jdc' => (int) ($jdc[$asset['character_id']] ?? 0),
            ];
        }

        return $ships;
    }

    /**
     * Every asset with its solar system resolved (items inside containers/ships follow
     * their parent up to the station, structure or space they sit in).
     *
     * @param  list<int>  $characterIds
     * @return list<array{character_id: int, type_id: int, type_name: string, group_id: int, quantity: int, price: float, is_ship: bool, top_level: bool, system_id: ?int}>
     */
    private function assets(array $characterIds): array
    {
        if ($characterIds === []) {
            return [];
        }

        $rows = CharacterAsset::query()
            ->whereIn('character_assets.character_id', $characterIds)
            ->leftJoin('invTypes', 'invTypes.typeID', '=', 'character_assets.type_id')
            ->leftJoin('invGroups', 'invGroups.groupID', '=', 'invTypes.groupID')
            ->leftJoin('market_prices', 'market_prices.type_id', '=', 'character_assets.type_id')
            ->get([
                'character_assets.item_id', 'character_assets.character_id', 'character_assets.type_id',
                'character_assets.quantity', 'character_assets.location_id', 'character_assets.location_type',
                'invTypes.typeName', 'invTypes.groupID', 'invGroups.categoryID', 'market_prices.adjusted_price',
            ]);

        $byItem = $rows->keyBy('item_id');
        $locationIds = $rows->pluck('location_id')->unique()->filter(fn ($id): bool => ! $byItem->has($id))->values()->all();

        $stationSystems = DB::table('universe_stations')->whereIn('station_id', $locationIds)->pluck('system_id', 'station_id');
        $structureSystems = DB::table('universe_structures')->whereIn('structure_id', $locationIds)->pluck('solar_system_id', 'structure_id');
        $sdeStationSystems = DB::table('staStations')->whereIn('stationID', $locationIds)->pluck('solarSystemID', 'stationID');

        $resolve = function ($row) use ($byItem, $stationSystems, $structureSystems, $sdeStationSystems): array {
            $topLevel = true;
            $guard = 0;
            while ($byItem->has($row->location_id) && $guard++ < 10) {
                $row = $byItem->get($row->location_id);
                $topLevel = false;
            }

            $locationId = $row->location_id;
            $systemId = match (true) {
                $row->location_type === 'solar_system' => (int) $locationId,
                isset($stationSystems[$locationId]) => (int) $stationSystems[$locationId],
                isset($sdeStationSystems[$locationId]) => (int) $sdeStationSystems[$locationId],
                isset($structureSystems[$locationId]) => (int) $structureSystems[$locationId],
                default => null,
            };

            return [$systemId, $topLevel];
        };

        return $rows->map(function ($row) use ($resolve): array {
            [$systemId, $topLevel] = $resolve($row);

            return [
                'character_id' => (int) $row->character_id,
                'type_id' => (int) $row->type_id,
                'type_name' => (string) ($row->typeName ?? 'Unknown'),
                'group_id' => (int) ($row->groupID ?? 0),
                'quantity' => max(1, (int) $row->quantity),
                'price' => (float) ($row->adjusted_price ?? 0),
                'is_ship' => (int) ($row->categoryID ?? 0) === self::CATEGORY_SHIP,
                'top_level' => $topLevel,
                'system_id' => $systemId,
            ];
        })->all();
    }
}
