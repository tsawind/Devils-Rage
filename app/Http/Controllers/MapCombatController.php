<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Combat\CombatModeAction;
use App\Features\MapSettingsFeature;
use App\Models\Map;
use App\Models\MapSolarsystem;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Turn your own combat mode on (start a chain, join one, or plain combat
 * speed) or off, and clear a chain's colors. See CombatModeAction.
 */
final class MapCombatController extends Controller
{
    /**
     * @throws Throwable
     */
    public function store(Request $request, Map $map, CombatModeAction $action, #[CurrentUser] User $user): RedirectResponse
    {
        Gate::authorize('update', $map);

        $validated = $request->validate([
            'mode' => ['required', Rule::in(['start', 'join', 'solo'])],
            'map_solarsystem_id' => ['required_if:mode,start', 'nullable', 'integer'],
            'color' => ['nullable', Rule::in(CombatModeAction::COLORS)],
        ]);

        match ($validated['mode']) {
            'start' => $action->start(
                $user,
                $map,
                MapSolarsystem::query()->where('map_id', $map->id)->findOrFail((int) ($validated['map_solarsystem_id'] ?? 0)),
            ),
            'join' => $action->join($user, $map, (string) ($validated['color'] ?? '')),
            default => $action->solo($user, $map),
        };

        $this->refreshSessionSettings($user, $map);

        return back();
    }

    /**
     * @throws Throwable
     */
    public function destroy(Map $map, CombatModeAction $action, #[CurrentUser] User $user): RedirectResponse
    {
        Gate::authorize('update', $map);

        $action->stop($user, $map);

        $this->refreshSessionSettings($user, $map);

        return back();
    }

    /**
     * @throws Throwable
     */
    public function clear(MapSolarsystem $mapSolarsystem, CombatModeAction $action): RedirectResponse
    {
        Gate::authorize('update', $mapSolarsystem);

        $action->clear($mapSolarsystem);

        return back();
    }

    /** The map page reads your settings from the session cache; keep it in step with the database. */
    private function refreshSessionSettings(User $user, Map $map): void
    {
        $settings = $user->mapUserSettings()->firstOrCreate(['map_id' => $map->id]);
        Session::put(MapSettingsFeature::sessionKey($map->id), $settings->fresh()?->attributesToArray() ?? $settings->attributesToArray());
    }
}
