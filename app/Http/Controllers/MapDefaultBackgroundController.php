<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MapBackgroundMode;
use App\Http\Requests\StoreMapDefaultBackgroundRequest;
use App\Models\Map;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Patch 37: the map's default background, set by managers. Every pilot sees it unless
 * they pick "None" or their own picture. A manager can upload one, or use the picture
 * they already have as their own background.
 */
final class MapDefaultBackgroundController extends Controller
{
    public function store(StoreMapDefaultBackgroundRequest $request, Map $map, #[CurrentUser] User $user): RedirectResponse
    {
        $previous = $map->background_image_path;
        $path = null;
        if ($request->hasFile('background_image')) {
            $path = $request->file('background_image')?->store("map-backgrounds/{$map->id}/default", 'public');
        } else {
            $own = $user->mapUserSettings()->where('map_id', $map->id)->value('background_image_path');
            if (is_string($own) && Storage::disk('public')->exists($own)) {
                $path = "map-backgrounds/{$map->id}/default/".basename($own);
                Storage::disk('public')->copy($own, $path);
            }
        }

        if (! is_string($path)) {
            return back()->withErrors(['background_image' => 'Upload a picture, or set your own background first.']);
        }

        // The same picture copied again lands on the same path: only delete a different old one.
        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }
        $map->forceFill([
            'background_image_path' => $path,
            'background_image_mode' => $map->background_image_mode ?? MapBackgroundMode::Viewport->value,
        ])->save();

        return back();
    }

    public function update(Request $request, Map $map): RedirectResponse
    {
        Gate::authorize('updateSettings', $map);

        $validated = $request->validate(['mode' => ['required', 'string', Rule::enum(MapBackgroundMode::class)]]);
        $map->forceFill(['background_image_mode' => $validated['mode']])->save();

        return back();
    }

    public function destroy(Map $map): RedirectResponse
    {
        Gate::authorize('updateSettings', $map);

        $this->deleteDefault($map);
        $map->forceFill(['background_image_path' => null])->save();

        return back();
    }

    private function deleteDefault(Map $map): void
    {
        if (is_string($map->background_image_path) && $map->background_image_path !== '') {
            Storage::disk('public')->delete($map->background_image_path);
        }
    }
}
