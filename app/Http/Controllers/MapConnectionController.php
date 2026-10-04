<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\MapConnections\CreateMapConnectionAction;
use App\Actions\MapConnections\DeleteMapConnectionAction;
use App\Actions\MapConnections\UpdateMapConnectionAction;
use App\Data\MapConnectionData;
use App\Http\Requests\StoreMapConnectionRequest;
use App\Http\Requests\UpdateMapConnectionRequest;
use App\Models\MapConnection;
use App\Models\User;
use App\Support\Undo\MapUndoSnapshots;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class MapConnectionController extends Controller
{
    public function store(StoreMapConnectionRequest $request, CreateMapConnectionAction $action): RedirectResponse
    {
        $action->handle($request->validated());

        return back()->notify(
            'Connection created!',
            'You have successfully created a new map connection.'
        );
    }

    public function destroy(Request $request, MapConnection $mapConnection, DeleteMapConnectionAction $action, MapUndoSnapshots $undo, #[CurrentUser] User $user): RedirectResponse
    {
        Gate::authorize('delete', $mapConnection);

        // Patch 21: saved first (with its signatures and logged jumps), so Undo can put it back.
        $token = MapUndoSnapshots::tokenFrom($request);
        if ($token !== null) {
            $undo->capture($mapConnection->map, $user->id, $token, connectionIds: [$mapConnection->id]);
        }

        $action->handle($mapConnection);

        return back()->notify(
            'Connection deleted!',
            'You have successfully deleted the map connection.'
        );
    }

    public function update(UpdateMapConnectionRequest $request, MapConnection $mapConnection, UpdateMapConnectionAction $action): RedirectResponse
    {
        Gate::authorize('update', $mapConnection);

        $action->handle($mapConnection, MapConnectionData::from($request->validated()));

        return back()->notify(
            'Connection updated!',
            'You have successfully updated the map connection.'
        );
    }
}
