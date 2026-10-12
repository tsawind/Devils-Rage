<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Names for stations, structures and solar systems from what SeAT already has.
 */
final class Locations
{
    /**
     * @param  list<int>  $locationIds  station, structure or solar system IDs
     * @return array<int, array{name: string, system_id: int|null}>
     */
    public function resolve(array $locationIds): array
    {
        $ids = array_values(array_unique(array_filter($locationIds)));
        if ($ids === []) {
            return [];
        }

        $result = [];
        foreach (DB::table('staStations')->whereIn('stationID', $ids)->get(['stationID', 'stationName', 'solarSystemID']) as $row) {
            $result[(int) $row->stationID] = ['name' => (string) $row->stationName, 'system_id' => (int) $row->solarSystemID];
        }
        if (Schema::hasTable('universe_stations')) {
            foreach (DB::table('universe_stations')->whereIn('station_id', $ids)->get(['station_id', 'name', 'system_id']) as $row) {
                $result[(int) $row->station_id] ??= ['name' => (string) $row->name, 'system_id' => (int) $row->system_id];
            }
        }
        foreach (DB::table('universe_structures')->whereIn('structure_id', $ids)->get(['structure_id', 'name', 'solar_system_id']) as $row) {
            $result[(int) $row->structure_id] = ['name' => (string) $row->name, 'system_id' => (int) $row->solar_system_id];
        }

        $systems = $this->systems($ids);
        foreach ($ids as $id) {
            if (! isset($result[$id]) && isset($systems[$id])) {
                $result[$id] = ['name' => $systems[$id], 'system_id' => $id];
            }
        }

        return $result;
    }

    /**
     * @param  list<int>  $systemIds
     * @return array<int, string>
     */
    public function systems(array $systemIds): array
    {
        $ids = array_values(array_unique(array_filter($systemIds)));
        if ($ids === []) {
            return [];
        }

        return DB::table('solar_systems')->whereIn('system_id', $ids)->pluck('name', 'system_id')
            ->mapWithKeys(fn ($name, $id): array => [(int) $id => (string) $name])
            ->all();
    }
}
