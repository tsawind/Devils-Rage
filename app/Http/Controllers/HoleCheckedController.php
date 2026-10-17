<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MapConnection;
use App\Models\Signature;
use App\Support\Actor;
use App\Support\Broadcasting\MapBroadcaster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        // Patch 37: "still this status" restarts the mass math like marking it again: only jumps
        // after this count against the band (the range tightens, the amber "?" goes away).
        $mapConnection->forceFill([
            'checked_at' => now(),
            'checked_by_name' => Actor::name(),
            'checked_note' => 'checked',
            'mass_status_updated_at' => now(),
            'mass_status_from_log' => false,
            'mass_status_set_by_name' => Actor::name(),
        ])->save();
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

    /**
     * Patch 37: 👁 Watching on / off: every jump through this hole is logged (anyone may turn it
     * off; the mass math goes back to the status bands).
     */
    public function watch(Request $request, MapConnection $mapConnection): RedirectResponse
    {
        Gate::authorize('update', $mapConnection);

        // Patch 39: fold / open the banner, or "still on it?" (a click in it): the watch goes on.
        if ($request->has('open') || $request->boolean('touch')) {
            $mapConnection->forceFill([
                ...($request->has('open') ? ['mass_watch_open' => $request->boolean('open')] : []),
                'mass_watch_touched_at' => now(),
            ])->save();
            $this->broadcast($mapConnection);

            return back();
        }

        $on = $request->boolean('on');
        $mapConnection->forceFill([
            'mass_watched_at' => $on ? now() : null,
            'mass_watched_by_name' => $on ? Actor::name() : null,
            'mass_watch_open' => $on,
            'mass_watch_touched_at' => $on ? now() : null,
        ])->save();
        $this->broadcast($mapConnection);

        return back();
    }

    /**
     * Patch 39: Mark fresh: someone saw it "not significantly disrupted". Its range goes back to
     * its size ±10%; the jumps logged before stay in the log but stop counting ("ours only" from
     * here). With `watch`, the full watch banner opens; without, a small Fresh chip shows.
     */
    public function fresh(Request $request, MapConnection $mapConnection): RedirectResponse
    {
        Gate::authorize('update', $mapConnection);

        $mapConnection->forceFill([
            'mass_status' => 'fresh',
            'mass_status_updated_at' => now(),
            'mass_status_from_log' => false,
            'mass_status_set_by_name' => Actor::name(),
            'should_have_rolled' => false,
            'checked_at' => now(),
            'checked_by_name' => Actor::name(),
            'checked_note' => 'marked Fresh',
            'mass_watched_at' => now(),
            'mass_watched_by_name' => Actor::name(),
            'mass_watch_open' => $request->boolean('watch'),
            'mass_watch_touched_at' => now(),
        ])->save();
        $this->broadcast($mapConnection);

        return back();
    }

    private function broadcast(MapConnection $mapConnection): void
    {
        $this->mapBroadcaster->connectionsUpserted($mapConnection->map_id, MapConnection::query()
            ->whereKey($mapConnection->id)
            ->with('signatures.signatureType', 'signatures.wormhole')
            ->withJumpSummary()
            ->get());
    }

    public function signature(Signature $signature): RedirectResponse
    {
        Gate::authorize('update', $signature);

        $signature->forceFill(['checked_at' => now(), 'checked_by_name' => Actor::name(), 'checked_note' => 'checked'])->save();

        $this->mapBroadcaster->signaturesChanged($signature->mapSolarsystem);

        return back();
    }
}
