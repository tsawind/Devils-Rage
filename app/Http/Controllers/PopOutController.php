<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Resources\SelectedMapSolarsystemResource;
use App\Models\MapSolarsystem;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Patch 39: the scanner pop-out's system, the same shape the signature panel gets for the
 * selected system (the pop-out asks again after each jump, paste and every few seconds).
 */
final class PopOutController extends Controller
{
    public function system(MapSolarsystem $mapSolarsystem, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('view', $mapSolarsystem);

        $isViewer = $mapSolarsystem->map->getUserPermission($user) === Permission::Viewer;
        $system = MapSolarsystem::query()->with('signatures', 'wormholes', 'details', 'details.audits')->findOrFail($mapSolarsystem->id);
        $system->details->hideNotes($isViewer);

        return response()->json(['data' => $system->toResource(SelectedMapSolarsystemResource::class)->resolve()]);
    }
}
