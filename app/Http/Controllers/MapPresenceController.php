<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\MapShareLink;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Patch 37: who has the map open right now. Every open map (pilots and viewer-link
 * visitors) checks in every 45 s; only managers can read the list. The list lives in
 * the cache, so nobody else's browser ever receives it.
 */
final class MapPresenceController extends Controller
{
    /** Someone counts as here this long after their last check-in. */
    public const int HERE_SECONDS = 100;

    public function store(Request $request, Map $map): JsonResponse
    {
        Gate::authorize('view', $map);

        $user = $request->user();
        $key = self::cacheKey($map);
        $entry = $this->entryFor($user instanceof User ? $user : null, $map, $request);
        $now = now()->getTimestamp();

        /** @var array<string, array{name: string, main: string|null, permission: string, kind: string, seen: int}> $present */
        $present = Cache::get($key, []);
        $present = array_filter($present, fn (array $other): bool => $other['seen'] > $now - self::HERE_SECONDS);
        $present[$request->session()->getId()] = [...$entry, 'seen' => $now];
        Cache::put($key, $present, now()->addMinutes(10));

        return response()->json(['ok' => true]);
    }

    public function index(Map $map): JsonResponse
    {
        Gate::authorize('manageAccess', $map);

        $now = now()->getTimestamp();
        /** @var array<string, array{name: string, main: string|null, permission: string, kind: string, seen: int}> $present */
        $present = Cache::get(self::cacheKey($map), []);
        $people = [];
        foreach ($present as $entry) {
            if ($entry['seen'] <= $now - self::HERE_SECONDS) {
                continue;
            }
            $id = $entry['kind'].':'.$entry['name'];
            $people[$id] = [
                'id' => $id,
                'name' => $entry['name'],
                'main' => $entry['main'],
                'permission' => $entry['permission'],
                'kind' => $entry['kind'],
                'tabs' => ($people[$id]['tabs'] ?? 0) + 1,
            ];
        }

        return response()->json(['present' => array_values($people)]);
    }

    public static function cacheKey(Map $map): string
    {
        return sprintf('map-presence:%d', $map->id);
    }

    /**
     * @return array{name: string, main: string|null, permission: string, kind: string}
     */
    private function entryFor(?User $user, Map $map, Request $request): array
    {
        $permission = $user instanceof User ? $map->getUserPermission($user) : null;
        if ($user instanceof User && $permission !== null) {
            $character = $user->active_character;

            return [
                'name' => $character?->name ?? (string) $user->name,
                'main' => $character?->seat_main_name,
                'permission' => $permission->value,
                'kind' => 'pilot',
            ];
        }

        $token = $request->session()->get("map_share_token_{$map->id}");
        $label = is_string($token) && $token !== ''
            ? MapShareLink::query()->where('map_id', $map->id)->where('token', $token)->value('label')
            : null;

        return [
            'name' => is_string($label) && $label !== '' ? $label : 'Viewer link',
            'main' => null,
            'permission' => 'viewer',
            'kind' => 'link',
        ];
    }
}
