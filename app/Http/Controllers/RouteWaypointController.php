<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SetWaypointAction;
use App\Builders\EsiTokenBuilder;
use App\Models\User;
use App\Scopes\CharacterIsOnline;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Patch 37: "Set route in game": a copied route's waypoints (the end of every gate leg, in
 * order) on your own online characters. The first replaces the in-game route, the rest are
 * added after it. Autopilot can't use holes: you take those, and it re-routes from there.
 */
final class RouteWaypointController extends Controller
{
    /**
     * @throws ConnectionException
     */
    public function store(Request $request, #[CurrentUser] User $user, SetWaypointAction $setWaypointAction): RedirectResponse
    {
        $validated = $request->validate([
            'destination_ids' => ['required', 'array', 'min:1', 'max:20'],
            'destination_ids.*' => ['integer', 'exists:solarsystems,id'],
            'character_ids' => ['nullable', 'array', 'max:50'],
            'character_ids.*' => ['integer'],
        ]);

        $characters = $user->characters()
            ->whereHas('esiTokens', function (Builder $query): void {
                assert($query instanceof EsiTokenBuilder);
                $query->hasWaypointScopes();
            })
            ->tap(new CharacterIsOnline)
            ->when($validated['character_ids'] ?? null, fn (Builder $query, array $ids) => $query->whereIn('characters.id', $ids))
            ->get();

        /** @var list<int> $destinations */
        $destinations = array_values(array_map(intval(...), $validated['destination_ids']));

        foreach ($characters as $character) {
            foreach ($destinations as $position => $destination) {
                $setWaypointAction->handle($character, [
                    'destination_id' => $destination,
                    'clear_other_waypoints' => $position === 0,
                    'add_to_beginning' => false,
                ]);
            }
        }

        $count = $characters->count();

        return back()->notify('Route set in game', message: $count === 0
            ? 'None of your characters is online with the waypoint permission.'
            : sprintf('%d waypoint%s set for %d character%s.', count($destinations), count($destinations) === 1 ? '' : 's', $count, $count === 1 ? '' : 's'));
    }
}
