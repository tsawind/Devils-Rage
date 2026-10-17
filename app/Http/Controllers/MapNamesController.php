<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\MapSolarsystem\MapNamesAction;
use App\Http\Requests\UpdateMapNamesRequest;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Models\User;
use App\Support\Undo\MapUndoSnapshots;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Throwable;

/**
 * Patch 39: a system's names: the loop popup (Switch), ✕ on a floating name, and an old
 * chain's rename rows (Done / Keep old). See MapNamesAction.
 */
final class MapNamesController extends Controller
{
    /**
     * @throws Throwable
     */
    public function update(UpdateMapNamesRequest $request, MapSolarsystem $mapSolarsystem, MapNamesAction $action, MapUndoSnapshots $undo, #[CurrentUser] User $user): RedirectResponse
    {
        $validated = $request->validated();
        $signature = isset($validated['signature_id'])
            ? Signature::query()->whereHas('mapSolarsystem', fn ($query) => $query->where('map_id', $mapSolarsystem->map_id))->find((int) $validated['signature_id'])
            : null;

        // Undo puts the system, its pipes and the holes on both sides back as they were.
        $token = MapUndoSnapshots::tokenFrom($request);
        if ($token !== null) {
            // A rename may also rename whoever had the name (swap / next number).
            $holders = $validated['action'] === 'rename' && filled($validated['name'] ?? null)
                ? MapSolarsystem::query()->where('map_id', $mapSolarsystem->map_id)->where('alias', $validated['name'])->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()
                : [];
            $connection = $signature?->map_connection_id !== null ? MapConnection::query()->find($signature->map_connection_id) : null;
            $ends = $connection instanceof MapConnection ? [(int) $connection->from_map_solarsystem_id, (int) $connection->to_map_solarsystem_id] : [];
            $undo->capture($mapSolarsystem->map, $user->id, $token, systemIds: array_values(array_unique([$mapSolarsystem->id, ...$holders, ...$ends])), signatureSystemIds: $signature instanceof Signature ? [(int) $signature->map_solarsystem_id] : []);
        }

        match ($validated['action']) {
            'switch' => $action->switchTo($mapSolarsystem, $this->required($signature)),
            'drop' => $action->drop($mapSolarsystem, $signature, $validated['name'] ?? null),
            'adopt' => $action->adopt($mapSolarsystem, $this->required($signature)),
            'keep' => $action->keep($mapSolarsystem),
            'hole' => $action->renameHole($mapSolarsystem, $this->required($signature), (string) $validated['name']),
            'rename' => $action->renameTo($mapSolarsystem, $this->required($signature), (string) $validated['name'], (string) ($validated['mode'] ?? 'plain'), $validated['next'] ?? null),
        };

        return back();
    }

    private function required(?Signature $signature): Signature
    {
        abort_unless($signature instanceof Signature, 404);

        return $signature;
    }
}
