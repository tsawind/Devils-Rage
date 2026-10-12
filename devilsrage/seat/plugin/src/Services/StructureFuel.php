<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The main corporation's Upwell structures and control towers (POS) and when their fuel
 * runs out, from SeAT's corporation structure and starbase syncs (director token).
 */
final class StructureFuel
{
    /** Fuel blocks (any race) and strontium clathrates. */
    private const FUEL_BLOCKS = [4051, 4246, 4247, 4312];

    private const STRONTIUM = 16275;

    public function __construct(private readonly Corporation $corporation) {}

    /**
     * Soonest out of fuel first; structures without fuel (low power / abandoned) last.
     *
     * @return list<array{kind: string, name: string, type: string, system_id: int, state: string, fuel_expires: string|null, hours_left: int|null, strontium_hours: int|null}>
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
                'kind' => 'structure',
                'strontium_hours' => null,
                'name' => (string) ($row->name ?? 'Structure '.$row->structure_id),
                'type' => (string) ($row->typeName ?? '?'),
                'system_id' => (int) $row->system_id,
                'state' => (string) ($row->state ?? ''),
                'fuel_expires' => $expires?->toIso8601String(),
                'hours_left' => $expires === null ? null : (int) max(0, $now->diffInHours($expires, false)),
            ];
        })->all();
        $structures = [...$structures, ...$this->starbases($corporationId)];

        usort($structures, fn (array $a, array $b): int => [$a['hours_left'] === null, $a['hours_left']] <=> [$b['hours_left'] === null, $b['hours_left']]);

        return $structures;
    }

    /**
     * Control towers: fuel hours = fuel blocks ÷ blocks per hour (small 10, medium 20,
     * large 40; faction towers 10 % or 20 % less), strontium hours the same way for
     * reinforcement (small 100, medium 200, large 400 an hour).
     *
     * @return list<array{kind: string, name: string, type: string, system_id: int, state: string, fuel_expires: string|null, hours_left: int|null, strontium_hours: int|null}>
     */
    private function starbases(int $corporationId): array
    {
        if (! Schema::hasTable('corporation_starbases')) {
            return [];
        }

        $towers = DB::table('corporation_starbases as s')
            ->leftJoin('invTypes as t', 't.typeID', '=', 's.type_id')
            ->where('s.corporation_id', $corporationId)
            ->get(['s.starbase_id', 's.moon_id', 's.system_id', 's.state', 's.type_id', 't.typeName']);
        if ($towers->isEmpty()) {
            return [];
        }

        $fuel = Schema::hasTable('corporation_starbase_fuels')
            ? DB::table('corporation_starbase_fuels')->where('corporation_id', $corporationId)->get(['starbase_id', 'type_id', 'quantity'])->groupBy('starbase_id')
            : collect();
        $moons = Schema::hasTable('mapDenormalize')
            ? DB::table('mapDenormalize')->whereIn('itemID', $towers->pluck('moon_id')->filter()->all())->pluck('itemName', 'itemID')
            : collect();
        $now = Carbon::now();

        return $towers->map(function ($tower) use ($fuel, $moons, $now): array {
            $type = (string) ($tower->typeName ?? 'Control Tower');
            $size = str_contains($type, 'Small') ? 1 : (str_contains($type, 'Medium') ? 2 : 4);
            $discount = match (true) {
                (bool) preg_match('/^(Dark Blood|True Sansha|Dread Guristas|Shadow)/', $type) => 0.8,
                (bool) preg_match('/^(Blood|Sansha|Guristas|Serpentis|Angel|Domination)/', $type) => 0.9,
                default => 1.0,
            };
            $bay = $fuel->get($tower->starbase_id) ?? collect();
            $blocks = (int) $bay->whereIn('type_id', self::FUEL_BLOCKS)->sum('quantity');
            $strontium = (int) $bay->where('type_id', self::STRONTIUM)->sum('quantity');
            $online = in_array($tower->state, ['online', 'reinforced', 'onlining'], true);
            $hours = $online ? (int) floor($blocks / max(1, round(10 * $size * $discount))) : null;

            return [
                'kind' => 'pos',
                'name' => (string) ($moons[$tower->moon_id] ?? 'POS '.$tower->starbase_id),
                'type' => $type,
                'system_id' => (int) $tower->system_id,
                'state' => (string) $tower->state,
                'fuel_expires' => $hours === null ? null : $now->copy()->addHours($hours)->toIso8601String(),
                'hours_left' => $hours,
                'strontium_hours' => (int) floor($strontium / (100 * $size)),
            ];
        })->values()->all();
    }
}
