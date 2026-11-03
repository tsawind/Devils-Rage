<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Fleets\BuildFleetAction;
use App\Actions\Fleets\QuickFleet;
use App\Http\Requests\QuickFleetRequest;
use App\Http\Requests\ResolveFleetNamesRequest;
use App\Http\Requests\SaveFleetLayoutRequest;
use App\Models\Character;
use App\Models\CharacterStatus;
use App\Models\EsiToken;
use App\Models\FleetLayout;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use NicolasKion\Esi\Enums\EsiScope;
use NicolasKion\Esi\Esi;
use Throwable;

/**
 * Patch 67: fleet layouts, like a fleet invite script but for the whole corp: save wings and
 * squads with who goes where (yours, or shared with everyone), then the fleet boss builds the
 * fleet and invites everyone to their place in one go (BuildFleetAction).
 */
final class FleetLayoutController extends Controller
{
    public function index(#[CurrentUser] User $user): Response
    {
        $layouts = FleetLayout::query()->visibleTo($user)->with('user:id,name')->orderByDesc('shared')->orderBy('name')->get();
        $mine = $user->characters()->orderBy('name')->get(['id', 'name']);

        return Inertia::render('fleets/Layouts', [
            'layouts' => $layouts->map(fn (FleetLayout $layout): array => [
                'id' => $layout->id,
                'name' => $layout->name,
                'shared' => $layout->shared,
                'mine' => $layout->user_id === $user->id,
                'owner' => $layout->user?->name,
                'layout' => $layout->layout,
                'updated_at' => $layout->updated_at->toIso8601String(),
            ])->values(),
            'characters' => $mine->map(fn (Character $character): array => [
                'id' => $character->id,
                'name' => $character->name,
                'can_boss' => $character->getEsiTokenWithScope(EsiScope::WriteFleet) instanceof EsiToken,
            ])->values(),
        ]);
    }

    public function store(SaveFleetLayoutRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $layout = FleetLayout::query()->create([
            'user_id' => $user->id,
            'name' => (string) $request->validated('name'),
            'shared' => (bool) $request->validated('shared', false),
            'layout' => $request->validated('layout'),
        ]);

        return response()->json(['id' => $layout->id]);
    }

    public function update(SaveFleetLayoutRequest $request, FleetLayout $fleetLayout, #[CurrentUser] User $user): JsonResponse
    {
        abort_unless($fleetLayout->user_id === $user->id, 403, 'Only whoever made this layout can change it: save a copy instead.');
        $fleetLayout->update([
            'name' => (string) $request->validated('name'),
            'shared' => (bool) $request->validated('shared', false),
            'layout' => $request->validated('layout'),
        ]);

        return response()->json(['id' => $fleetLayout->id]);
    }

    public function destroy(FleetLayout $fleetLayout, #[CurrentUser] User $user): JsonResponse
    {
        abort_unless($fleetLayout->user_id === $user->id, 403);
        $fleetLayout->delete();

        return response()->json(['deleted' => true]);
    }

    public function build(FleetLayout $fleetLayout, #[CurrentUser] User $user, BuildFleetAction $build): JsonResponse
    {
        abort_unless($fleetLayout->user_id === $user->id || $fleetLayout->shared, 403);

        return response()->json($build->handle($user, $fleetLayout));
    }

    /**
     * Patch 72: your characters for a quick fleet: who's online, what they're flying, where,
     * and the squad that ship goes in.
     */
    public function quick(#[CurrentUser] User $user): JsonResponse
    {
        $characters = $user->characters()->with(['characterStatus.shipType.group', 'characterStatus.solarsystem'])->orderBy('name')->get();

        return response()->json([
            'squads' => QuickFleet::SQUADS,
            'characters' => $characters->map(function (Character $character): array {
                $status = $character->characterStatus;
                $ship = $status instanceof CharacterStatus ? $status->shipType : null;

                return [
                    'id' => (int) $character->id,
                    'name' => $character->name,
                    'online' => $status instanceof CharacterStatus && $status->is_online,
                    'ship' => $ship?->name,
                    'ship_type_id' => $ship?->id,
                    'system' => $status?->solarsystem?->name,
                    'role' => QuickFleet::roleFor($ship?->group?->name, $ship?->name),
                ];
            })->values(),
        ]);
    }

    /** Patch 72: put these characters in the fleet you're boss of, a squad per role (again after a roll: only who's missing). */
    public function quickBuild(QuickFleetRequest $request, #[CurrentUser] User $user, BuildFleetAction $build): JsonResponse
    {
        /** @var list<array{id: int, name: string, role: string}> $members */
        $members = array_values((array) $request->validated('members'));
        $layout = new FleetLayout(['name' => 'Quick fleet', 'shared' => false, 'layout' => QuickFleet::layout($members)]);

        return response()->json($build->handle($user, $layout));
    }

    /** Pasted names → characters (the mapper's own first, then EVE's exact-name lookup). */
    public function resolve(ResolveFleetNamesRequest $request, Esi $esi): JsonResponse
    {
        /** @var list<string> $names */
        $names = array_values(array_unique(array_filter(array_map(fn (mixed $name): string => trim((string) $name), (array) $request->validated('names')))));
        $known = Character::query()->whereIn('name', $names)->get(['id', 'name']);
        $found = [];
        foreach ($known as $character) {
            $found[mb_strtolower($character->name)] = ['id' => $character->id, 'name' => $character->name];
        }
        $missing = array_values(array_filter($names, fn (string $name): bool => ! isset($found[mb_strtolower($name)])));
        if ($missing !== []) {
            try {
                $result = $esi->getIds($missing);
                if ($result->wasSuccessful()) {
                    foreach ($result->data->characters as $character) {
                        $found[mb_strtolower($character->name)] = ['id' => $character->id, 'name' => $character->name];
                    }
                }
            } catch (Throwable) {
                // Unknown names are reported back as not found.
            }
        }

        $out = [];
        $notFound = [];
        foreach ($names as $name) {
            if (isset($found[mb_strtolower($name)])) {
                $out[] = $found[mb_strtolower($name)];
            } else {
                $notFound[] = $name;
            }
        }

        return response()->json(['characters' => $out, 'not_found' => $notFound]);
    }
}
