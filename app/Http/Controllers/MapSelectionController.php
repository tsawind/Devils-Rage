<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\MapSelection\DeleteMapSelectionAction;
use App\Actions\MapSelection\UpdateMapSelectionAction;
use App\Http\Requests\DeleteMapSelectionRequest;
use App\Http\Requests\UpdateMapSelectionRequest;
use App\Models\Map;
use App\Models\MapSolarsystem;
use App\Models\User;
use App\Support\Undo\MapUndoSnapshots;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Throwable;

final class MapSelectionController extends Controller
{
    /**
     * @throws Throwable
     */
    public function update(UpdateMapSelectionRequest $request, UpdateMapSelectionAction $action): RedirectResponse
    {
        $action->handle($request->validated());

        return back();
    }

    /**
     * @throws Throwable
     */
    public function destroy(DeleteMapSelectionRequest $request, DeleteMapSelectionAction $action, MapUndoSnapshots $undo, #[CurrentUser] User $user): RedirectResponse
    {
        // Patch 21: saved first, so Undo can put the whole selection back.
        $token = MapUndoSnapshots::tokenFrom($request);
        $ids = array_map(intval(...), $request->validated()['map_solarsystem_ids']);
        $map = MapSolarsystem::query()->whereIn('id', $ids)->first()?->map;
        if ($token !== null && $map instanceof Map) {
            $undo->capture($map, $user->id, $token, $ids);
        }

        $action->handle($request->validated()['map_solarsystem_ids']);

        return back()->notify(
            'Selection deleted!',
            'You successfully deleted the selected items!'
        );
    }
}
