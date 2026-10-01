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
use Illuminate\Validation\ValidationException;
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
            'map_solarsystem_id' => ['nullable', 'integer'],
            'solarsystem_id' => ['nullable', 'integer', 'exists:solarsystems,id'],
            'color' => ['nullable', Rule::in(CombatModeAction::COLORS)],
        ]);

        if ($validated['mode'] === 'start' && empty($validated['map_solarsystem_id']) && empty($validated['solarsystem_id'])) {
            throw ValidationException::withMessages(['combat' => 'Pick the system to start the chain in.']);
        }

        match ($validated['mode']) {
            // Your system isn't on the map yet: it is added, then becomes the combat home.
            'start' => empty($validated['map_solarsystem_id'])
                ? $action->startFromSolarsystem($user, $map, (int) $validated['solarsystem_id'])
                : $action->start(
                    $user,
                    $map,
                    MapSolarsystem::query()->where('map_id', $map->id)->findOrFail((int) $validated['map_solarsystem_id']),
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
    public function clear(MapSolarsystem $mapSolarsystem, CombatModeAction $action, #[CurrentUser] User $user): RedirectResponse
    {
        Gate::authorize('update', $mapSolarsystem);

        $action->clear($mapSolarsystem, $user);

        return back();
    }

    /**
     * Right-click the map → "Clear Red chain" (patch 12).
     *
     * @throws Throwable
     */
    public function clearChain(Map $map, string $color, CombatModeAction $action, #[CurrentUser] User $user): RedirectResponse
    {
        Gate::authorize('update', $map);

        if (! in_array($color, CombatModeAction::COLORS, true)) {
            throw ValidationException::withMessages(['combat' => 'Unknown chain color.']);
        }

        $action->clearChain($map, $color, $user);
        $this->refreshSessionSettings($user, $map);

        return back();
    }

    /** The map page reads your settings from the session cache; keep it in step with the database. */
    private function refreshSessionSettings(User $user, Map $map): void
    {
        $settings = $user->mapUserSettings()->firstOrCreate(['map_id' => $map->id]);
        Session::put(MapSettingsFeature::sessionKey($map->id), $settings->fresh()?->attributesToArray() ?? $settings->attributesToArray());
    }
}
