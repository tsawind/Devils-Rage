<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\MapSolarsystem;
use App\Models\Type;
use App\Models\User;
use App\Support\JumpPlanner;
use App\Support\PilotJumpSkills;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Patch 37: "Jump in" in the search: how to get a Black Ops bridge or a carrier conduit
 * jump into a system from the chain's k-space exits, with your own pilots' skills.
 */
final class JumpInController extends Controller
{
    public function show(Request $request, Map $map, #[CurrentUser] User $user, PilotJumpSkills $pilotJumpSkills): JsonResponse
    {
        Gate::authorize('view', $map);
        $validated = $request->validate(['to' => ['required', 'integer']]);
        $targetId = (int) $validated['to'];

        $systems = JumpPlanner::systems();
        if (! isset($systems[$targetId])) {
            return response()->json(['ok' => false, 'reason' => 'Jump drives only go to k-space.']);
        }
        $landing = JumpPlanner::landing($targetId);
        if ($landing === null) {
            return response()->json(['ok' => false, 'reason' => 'No system near it can take a cyno.']);
        }

        $pilots = $this->pilots($user, $pilotJumpSkills);
        $known = array_filter($pilots, fn (array $pilot): bool => $pilot['skills_read']);
        $best = fn (string $key): float => $known === [] ? ($key === 'blops' ? JumpPlanner::range(JumpPlanner::BLOPS_BASE_LY, 5) : JumpPlanner::range(JumpPlanner::CARRIER_BASE_LY, 5)) : max(array_column($known, $key.'_ly'));

        $exits = MapSolarsystem::query()
            ->where('map_id', $map->id)
            ->where('solarsystem_id', '<', 31_000_000)
            ->get(['id', 'solarsystem_id', 'alias']);

        $kinds = [];
        foreach (['blops' => 'BLOPS bridge', 'carrier' => 'Carrier conduit'] as $key => $label) {
            $range = $best($key);
            $rows = [];
            foreach ($exits as $exit) {
                $row = JumpPlanner::fromExit((int) $exit->solarsystem_id, $landing['id'], $range);
                if ($row !== null) {
                    $rows[] = [...$row, 'alias' => $exit->alias];
                }
            }
            usort($rows, fn (array $a, array $b): int => [! $a['direct'], $a['via']['gates'] ?? 99, $a['ly']] <=> [! $b['direct'], $b['via']['gates'] ?? 99, $b['ly']]);
            $kinds[] = ['key' => $key, 'label' => $label, 'range_ly' => $range, 'assumed' => $known === [], 'exits' => array_slice($rows, 0, 6)];
        }

        return response()->json([
            'ok' => true,
            'target' => ['id' => $targetId, 'name' => $systems[$targetId]['name'], 'security' => round($systems[$targetId]['security'], 1)],
            'landing' => $landing,
            'kinds' => $kinds,
            'pilots' => $pilots,
        ]);
    }

    /**
     * @return list<array{name: string, ship: string|null, skills_read: bool, jdc: int, blops_ly: float, carrier_ly: float, conduit_ships: int, command_ships: int|null, advice: string|null}>
     */
    private function pilots(User $user, PilotJumpSkills $pilotJumpSkills): array
    {
        $pilots = [];
        foreach ($user->characters()->with('characterStatus')->get() as $character) {
            $levels = $pilotJumpSkills->levels($character);
            $shipTypeId = $character->characterStatus?->ship_type_id;
            $ship = $shipTypeId !== null ? Type::query()->whereKey($shipTypeId)->value('name') : null;
            $jdc = $levels['jdc'] ?? 0;
            $racial = $levels === null ? 0 : max($levels['amarr'], $levels['caldari'], $levels['gallente'], $levels['minmatar']);
            $pilots[] = [
                'name' => (string) $character->name,
                'ship' => is_string($ship) ? $ship : null,
                'skills_read' => $levels !== null,
                'jdc' => $jdc,
                'blops_ly' => JumpPlanner::range(JumpPlanner::BLOPS_BASE_LY, $jdc),
                'carrier_ly' => JumpPlanner::range(JumpPlanner::CARRIER_BASE_LY, $jdc),
                'conduit_ships' => JumpPlanner::conduitShips($levels['portal'] ?? 0),
                'command_ships' => ($levels['command'] ?? 0) > 0 ? JumpPlanner::conduitShips($levels['portal'] ?? 0, true, $racial) : null,
                'advice' => $levels !== null && $jdc < JumpPlanner::JDC_ADVISED ? sprintf('Jump Drive Calibration %d: train or inject it to IV or V', $jdc) : null,
            ];
        }

        return $pilots;
    }
}
