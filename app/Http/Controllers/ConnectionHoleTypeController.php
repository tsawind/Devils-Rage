<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Signatures\ConnectionHoleTypeAction;
use App\Models\MapConnection;
use App\Models\Signature;
use App\Models\SignatureType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Patch 14: set a jumped hole's type from the map (connection right-click →
 * Type), and let a paste fill in the ID of a hole typed that way.
 */
final class ConnectionHoleTypeController extends Controller
{
    /**
     * @throws Throwable
     */
    public function store(Request $request, MapConnection $mapConnection, ConnectionHoleTypeAction $action): RedirectResponse
    {
        Gate::authorize('update', $mapConnection);

        $validated = $request->validate([
            'map_solarsystem_id' => ['required', 'integer'],
            'signature_type_id' => ['required', 'integer', 'exists:signature_types,id'],
        ]);

        $action->setType($mapConnection, (int) $validated['map_solarsystem_id'], SignatureType::query()->findOrFail((int) $validated['signature_type_id']));

        return back();
    }

    /**
     * @throws Throwable
     */
    public function absorb(Request $request, Signature $signature, ConnectionHoleTypeAction $action): RedirectResponse
    {
        Gate::authorize('update', $signature);

        $validated = $request->validate(['pasted_id' => ['required', 'integer']]);
        $pasted = Signature::query()->findOrFail((int) $validated['pasted_id']);
        Gate::authorize('update', $pasted);

        $action->absorb($signature, $pasted);

        return back();
    }
}
