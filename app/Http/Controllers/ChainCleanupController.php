<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Combat\ChainCleanupAction;
use App\Models\MapSolarsystem;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Patch 14: clean a combat chain up into another chain, one system at a time
 * (see ChainCleanupAction).
 */
final class ChainCleanupController extends Controller
{
    /**
     * Right-click the combat home → "Clean up Red chain".
     *
     * @throws Throwable
     */
    public function store(MapSolarsystem $mapSolarsystem, ChainCleanupAction $action, #[CurrentUser] User $user): RedirectResponse
    {
        Gate::authorize('update', $mapSolarsystem);

        $action->start($mapSolarsystem, $user);

        return back();
    }

    /**
     * Done on a cleanup row: this one system leaves the chain with its new name.
     *
     * @throws Throwable
     */
    public function convert(Request $request, MapSolarsystem $mapSolarsystem, ChainCleanupAction $action): RedirectResponse
    {
        Gate::authorize('update', $mapSolarsystem);

        $validated = $request->validate([
            'parent_id' => ['required', 'integer'],
            'alias' => ['required', 'string', 'max:32'],
        ]);

        $parent = MapSolarsystem::query()->where('map_id', $mapSolarsystem->map_id)->findOrFail((int) $validated['parent_id']);
        $action->convert($mapSolarsystem, $parent, (string) $validated['alias']);

        return back();
    }

    /**
     * Done on the way-back row: "*" was re-bookmarked in game.
     */
    public function returnDone(MapSolarsystem $mapSolarsystem, ChainCleanupAction $action): RedirectResponse
    {
        Gate::authorize('update', $mapSolarsystem);

        $action->returnDone($mapSolarsystem);

        return back();
    }
}
