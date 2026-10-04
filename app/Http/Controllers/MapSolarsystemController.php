<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\MapSolarsystem\DeleteMapSolarsystemAction;
use App\Actions\MapSolarsystem\StoreMapSolarsystemAction;
use App\Actions\MapSolarsystem\UpdateMapSolarsystemAction;
use App\Http\Requests\StoreMapSolarsystemRequest;
use App\Http\Requests\UpdateMapSolarsystemRequest;
use App\Models\MapSolarsystem;
use App\Models\User;
use App\Support\Undo\MapUndoSnapshots;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class MapSolarsystemController extends Controller
{
    public function store(StoreMapSolarsystemRequest $request, StoreMapSolarsystemAction $action): RedirectResponse
    {
        $action->handle($request->map, $request->validated());

        return back()->notify(
            'Solarsystem created!',
            'You have successfully created a new solarsystem on the map.'
        );
    }

    public function update(UpdateMapSolarsystemRequest $request, MapSolarsystem $mapSolarsystem, UpdateMapSolarsystemAction $action): RedirectResponse
    {
        $action->handle($mapSolarsystem, $request->validated());

        if ($request->boolean('suppress_notification')) {
            return back();
        }

        return back()
            ->notify(
                'Solarsystem updated!',
                'You have successfully updated the solarsystem on the map.'
            );
    }

    public function destroy(Request $request, MapSolarsystem $mapSolarsystem, DeleteMapSolarsystemAction $action, MapUndoSnapshots $undo, #[CurrentUser] User $user): RedirectResponse
    {
        Gate::authorize('delete', $mapSolarsystem);

        // Patch 21: saved first, so Undo can put it back with its signatures and pipes.
        $token = MapUndoSnapshots::tokenFrom($request);
        if ($token !== null) {
            $undo->capture($mapSolarsystem->map, $user->id, $token, [$mapSolarsystem->id]);
        }

        $action->handle($mapSolarsystem);

        return back()->notify(
            'Solarsystem deleted!',
            'You have successfully deleted the solarsystem from the map.'
        );
    }
}
