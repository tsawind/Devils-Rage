<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The main corporation's Upwell structures and when their fuel runs out, from SeAT's
 * corporation structure sync (needs a director / station manager token).
 */
final class StructureFuel
{
    public function __construct(private readonly Corporation $corporation) {}

    /**
     * Soonest out of fuel first; structures without fuel (low power / abandoned) last.
     *
     * @return list<array{name: string, type: string, system_id: int, state: string, fuel_expires: string|null, hours_left: int|null}>
     */
    public function structures(): array
    {
        $corporationId = $this->corporation->mainId();
        if ($corporationId === null || ! Schema::hasTable('corporation_structures')) {
            return [];
        }

        $systemColumn = Schema::hasColumn('corporation_structures', 'system_id') ? 'system_id' : 'solar_system_id';
        $rows = DB::table('corporation_structures as s')
            ->leftJoin('universe_structures as u', 'u.structure_id', '=', 's.structure_id')
            ->leftJoin('invTypes as t', 't.typeID', '=', 's.type_id')
            ->where('s.corporation_id', $corporationId)
            ->get(['s.structure_id', 's.'.$systemColumn.' as system_id', 's.state', 's.fuel_expires', 'u.name', 't.typeName']);

        $now = Carbon::now();
        $structures = $rows->map(function ($row) use ($now): array {
            $expires = $row->fuel_expires === null ? null : Carbon::parse($row->fuel_expires);

            return [
                'name' => (string) ($row->name ?? 'Structure '.$row->structure_id),
                'type' => (string) ($row->typeName ?? '?'),
                'system_id' => (int) $row->system_id,
                'state' => (string) ($row->state ?? ''),
                'fuel_expires' => $expires?->toIso8601String(),
                'hours_left' => $expires === null ? null : (int) max(0, $now->diffInHours($expires, false)),
            ];
        })->all();

        usort($structures, fn (array $a, array $b): int => [$a['hours_left'] === null, $a['hours_left']] <=> [$b['hours_left'] === null, $b['hours_left']]);

        return $structures;
    }
}
