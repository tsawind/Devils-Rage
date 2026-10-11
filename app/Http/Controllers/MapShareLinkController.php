<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\MapShareLink;
use App\Models\User;
use App\Support\Actor;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Patch 37: viewer links from the Share button (and map settings). Members make links for
 * 1 h – 7 days and remove their own; managers also make permanent links and add or cut
 * time on any link. A link never runs longer than 7 days from now (unless permanent).
 */
final class MapShareLinkController extends Controller
{
    public function store(Request $request, Map $map, #[CurrentUser] User $user): RedirectResponse
    {
        Gate::authorize('update', $map);

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:80'],
            'hours' => ['nullable', 'integer', 'min:1', 'max:'.MapShareLink::MAX_HOURS],
            'permanent' => ['sometimes', 'boolean'],
        ]);

        $permanent = (bool) ($validated['permanent'] ?? false);
        if ($permanent) {
            Gate::authorize('manageAccess', $map);
        }

        $map->shareLinks()->create([
            'token' => MapShareLink::newToken(),
            'kind' => 'map',
            'label' => $validated['label'] ?? null,
            'created_by_user_id' => $user->id,
            'created_by_name' => Actor::name(),
            'expires_at' => $permanent ? null : now()->addHours((int) ($validated['hours'] ?? 12)),
        ]);

        return back();
    }

    public function update(Request $request, MapShareLink $mapShareLink): RedirectResponse
    {
        Gate::authorize('manageAccess', $mapShareLink->map);

        $validated = $request->validate([
            'add_hours' => ['nullable', 'integer', 'min:-'.MapShareLink::MAX_HOURS, 'max:'.MapShareLink::MAX_HOURS],
            'permanent' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('permanent', $validated)) {
            $mapShareLink->update(['expires_at' => $validated['permanent'] ? null : now()->addHours(12)]);

            return back();
        }

        $base = $mapShareLink->expires_at ?? now()->toImmutable();
        $next = $base->addHours((int) ($validated['add_hours'] ?? 0));
        $latest = now()->toImmutable()->addHours(MapShareLink::MAX_HOURS);
        if ($next->greaterThan($latest)) {
            $next = $latest;
        }

        if ($next->lessThanOrEqualTo(now())) {
            $mapShareLink->delete();
        } else {
            $mapShareLink->update(['expires_at' => $next]);
        }

        return back();
    }

    public function destroy(MapShareLink $mapShareLink, #[CurrentUser] User $user): RedirectResponse
    {
        if ($mapShareLink->created_by_user_id !== $user->id) {
            Gate::authorize('manageAccess', $mapShareLink->map);
        }

        $mapShareLink->delete();

        return back();
    }

    /**
     * The links a viewer of the Share popup sees: managers every live link, members their own.
     *
     * @return list<array<string, mixed>>
     */
    public static function linksFor(Map $map, ?User $user): array
    {
        if (! $user instanceof User || ! Gate::forUser($user)->allows('update', $map)) {
            return [];
        }

        $manager = Gate::forUser($user)->allows('manageAccess', $map);

        return $map->shareLinks()
            ->live()
            ->when(! $manager, fn ($query) => $query->where('created_by_user_id', $user->id))
            ->latest()
            ->get()
            ->map(fn (MapShareLink $link): array => $link->toPayload($user))
            ->values()
            ->all();
    }
}
