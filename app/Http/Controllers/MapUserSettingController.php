<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\Characters\CharacterStatusUpdatedEvent;
use App\Features\MapSettingsFeature;
use App\Http\Requests\UpdateMapUserSettingRequest;
use App\Models\Map;
use App\Models\MapUserSetting;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Throwable;

final class MapUserSettingController extends Controller
{
    /**
     * @throws Throwable
     */
    public function update(UpdateMapUserSettingRequest $request, Map $map, #[CurrentUser] ?User $user): RedirectResponse
    {
        $validated = $request->validated();
        $sessionKey = MapSettingsFeature::sessionKey($map->id);

        if ($user instanceof User) {
            // Authenticated: update DB, then refresh session from full DB state
            $settings = DB::transaction(function () use ($user, $map, $validated): MapUserSetting {
                $settings = $user->mapUserSettings()->firstOrCreate(['map_id' => $map->id]);
                // Patch 53: a pop-out's own HOT / COLD / 🧵 for its character; the main switch resets them all.
                $jumpingFor = $validated['jumping_for'] ?? null;
                unset($validated['jumping_for']);
                if (array_key_exists('jumping_hot', $validated) || array_key_exists('jumping_thread', $validated)) {
                    $validated['jumping_by_character'] = null;
                }
                if (is_array($jumpingFor)) {
                    $all = $settings->jumping_by_character ?? [];
                    $key = (int) $jumpingFor['character_id'];
                    $all[$key] = [...(array_key_exists($key, $all) ? $all[$key] : []), ...array_intersect_key($jumpingFor, array_flip(['hot', 'thread']))];
                    $validated['jumping_by_character'] = $all;
                }
                $settings->update($validated);

                // Patch 41c: one card layout for every map tab: a saved layout goes to all your maps.
                $layout = array_intersect_key($validated, array_flip(['layout_breakpoints', 'hidden_cards']));
                if ($layout !== []) {
                    $settings->forceFill(['layout_saved_at' => now()])->save();
                    $user->mapUserSettings()->whereKeyNot($settings->getKey())->get()
                        ->each(fn (MapUserSetting $other) => $other->forceFill([...$layout, 'layout_saved_at' => now()])->save());
                }

                broadcast(new CharacterStatusUpdatedEvent($map->id))->toOthers();

                return $settings->fresh();
            });

            Session::put($sessionKey, $settings->attributesToArray());
        } else {
            // Guest: merge into session only
            $current = Session::get($sessionKey, array_merge(MapSettingsFeature::DEFAULTS, ['map_id' => $map->id]));
            unset($validated['jumping_for']);
            Session::put($sessionKey, array_merge($current, $validated));
        }

        return back();
    }
}
