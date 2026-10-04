<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\RestoreMapUndoRequest;
use App\Models\Map;
use App\Models\User;
use App\Support\Undo\MapUndoSnapshots;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

final class MapUndoController extends Controller
{
    /**
     * Patch 21: put back a change saved before it was made (Undo / Redo).
     *
     * @throws Throwable
     */
    public function store(RestoreMapUndoRequest $request, string $token, MapUndoSnapshots $snapshots, #[CurrentUser] User $user): RedirectResponse
    {
        $snapshot = $snapshots->find($token, $user->id);
        if ($snapshot === null) {
            throw ValidationException::withMessages(['undo' => 'That change can no longer be undone (it is a few hours old, or not yours).']);
        }

        $map = Map::query()->findOrFail($snapshot['map_id']);
        Gate::authorize('update', $map);

        $redo = $request->validated('redo_token');
        if (is_string($redo)) {
            $snapshots->captureLike($snapshot, $map, $user->id, $redo);
        }

        $snapshots->restore($snapshot, $map);

        return back();
    }
}
