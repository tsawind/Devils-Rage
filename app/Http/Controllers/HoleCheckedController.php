<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MapConnection;
use App\Models\Signature;
use App\Support\Actor;
use App\Support\Broadcasting\MapBroadcaster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Patch 37: ✓ Checked in game: someone looked at the hole's Show Info and its mass and
 * life are as the map shows. Resets the 4 h purple "?" on the pipe.
 */
final class HoleCheckedController extends Controller
{
    public function __construct(private readonly MapBroadcaster $mapBroadcaster) {}

    public function connection(MapConnection $mapConnection): RedirectResponse
    {
        Gate::authorize('update', $mapConnection);

        $mapConnection->forceFill(['checked_at' => now(), 'checked_by_name' => Actor::name(), 'checked_note' => 'checked'])->save();
        // Patch 37: "still this status at this mass" narrows the hole's real size too.
        $status = $mapConnection->mass_status instanceof \BackedEnum ? (string) $mapConnection->mass_status->value : (string) ($mapConnection->mass_status ?? 'fresh');
        \App\Models\MassObservation::note($mapConnection->id, $status, Actor::name());

        $this->mapBroadcaster->connectionsUpserted($mapConnection->map_id, MapConnection::query()
            ->whereKey($mapConnection->id)
            ->with('signatures.signatureType', 'signatures.wormhole')
            ->withJumpSummary()
            ->get());

        return back();
    }

    public function signature(Signature $signature): RedirectResponse
    {
        Gate::authorize('update', $signature);

        $signature->forceFill(['checked_at' => now(), 'checked_by_name' => Actor::name(), 'checked_note' => 'checked'])->save();

        $this->mapBroadcaster->signaturesChanged($signature->mapSolarsystem);

        return back();
    }
}
