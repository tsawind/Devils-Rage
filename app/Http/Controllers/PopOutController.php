<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\SignatureResource;
use App\Models\MapSolarsystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Patch 39: the scanner pop-out's view of the system its character is in: the system's
 * name and its signatures (the pop-out asks again after each jump, paste and every few
 * seconds while open).
 */
final class PopOutController extends Controller
{
    public function system(MapSolarsystem $mapSolarsystem): JsonResponse
    {
        Gate::authorize('view', $mapSolarsystem);

        $mapSolarsystem->load(['signatures.signatureType', 'signatures.signatureCategory', 'signatures.wormhole']);

        return response()->json([
            'id' => $mapSolarsystem->id,
            'map_id' => $mapSolarsystem->map_id,
            'solarsystem_id' => $mapSolarsystem->solarsystem_id,
            'alias' => $mapSolarsystem->alias,
            'signatures' => SignatureResource::collection($mapSolarsystem->signatures)->resolve(),
        ]);
    }
}
