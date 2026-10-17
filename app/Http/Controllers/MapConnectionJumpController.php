<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\MapConnections\CreateMapConnectionJumpAction;
use App\Actions\MapConnections\DeleteMapConnectionJumpAction;
use App\Actions\MapConnections\UpdateMapConnectionJumpAction;
use App\Http\Requests\StoreMapConnectionJumpRequest;
use App\Http\Requests\UpdateMapConnectionJumpRequest;
use App\Models\MapConnection;
use App\Models\MapConnectionJump;
use App\Support\JumpOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class MapConnectionJumpController extends Controller
{
    /**
     * Patch 37: the whole jump log of a pipe (click the mass bar), each jump with the masses it
     * could have had (HOT / SEMI / COLD / 🧵) and who changed it by hand.
     */
    public function index(MapConnection $mapConnection): JsonResponse
    {
        Gate::authorize('view', $mapConnection->map);

        $jumps = MapConnectionJump::query()
            ->where('map_connection_id', $mapConnection->id)
            ->with('character:id,name', 'shipType:id,name')
            ->latest('id')
            ->limit(500)
            ->get();
        $options = JumpOptions::forJumps($jumps);

        return response()->json([
            'jumps' => $jumps->map(fn (MapConnectionJump $jump): array => [
                'id' => $jump->id,
                'character_id' => $jump->character_id,
                'character_name' => $jump->character?->name,
                'ship_type_id' => $jump->ship_type_id,
                'ship_type_name' => $jump->shipType?->name,
                'mass' => $jump->mass,
                'is_manual' => $jump->is_manual,
                'is_hot' => (bool) $jump->getAttribute('is_hot'),
                'is_thread' => (bool) $jump->getAttribute('is_thread'),
                'edited_by_name' => $jump->getAttribute('edited_by_name'),
                'outbound' => $jump->from_solarsystem_id === $mapConnection->fromMapSolarsystem->solarsystem_id,
                // Patch 39: hand-added rows: hostile / friendly, ship class, fit, direction seen or not.
                'side' => $jump->side,
                'ship_class' => $jump->ship_class,
                'direction_known' => $jump->direction_known,
                'fit' => $jump->fit,
                'created_at' => $jump->created_at,
                'options' => $options[$jump->id] ?? [],
            ])->values(),
        ]);
    }

    public function store(StoreMapConnectionJumpRequest $request, CreateMapConnectionJumpAction $action): RedirectResponse
    {
        $action->handle($request->validated());

        return back()->notify(
            'Jump logged!',
            'The jump has been added to the connection\'s mass estimate.'
        );
    }

    public function update(UpdateMapConnectionJumpRequest $request, MapConnectionJump $mapConnectionJump, UpdateMapConnectionJumpAction $action): RedirectResponse
    {
        $action->handle($mapConnectionJump, $request->validated());

        return back()->notify(
            'Jump updated!',
            'The jump entry has been updated.'
        );
    }

    public function destroy(MapConnectionJump $mapConnectionJump, DeleteMapConnectionJumpAction $action): RedirectResponse
    {
        Gate::authorize('delete', $mapConnectionJump);

        $action->handle($mapConnectionJump);

        return back()->notify(
            'Jump removed!',
            'The jump entry has been removed from the connection.'
        );
    }
}
