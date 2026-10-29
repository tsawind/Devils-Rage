<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\Cameras\CameraClientsChangedEvent;
use App\Events\Cameras\CameraControlEvent;
use App\Models\Map;
use App\Models\User;
use App\Support\CameraPass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Patch 62k: the EVE clients your share window has up (one per shared window), so the map can
 * run them: the pilot tabs and the hole watcher turn a client's cameras on / off and pick
 * the hole it watches. The share window reports them (kept a couple of minutes); the map's
 * asks go to it over your own channel. Nothing here touches the game.
 */
final class MapCameraClientController extends Controller
{
    /** How long a report counts without a new one (the share window reports every 30 s). */
    private const int KEEP_SECONDS = 90;

    /**
     * Your clients on this map, and the places they can watch.
     */
    public function index(Request $request, Map $map): JsonResponse
    {
        $user = $this->member($request, $map);

        return response()->json([
            'clients' => Cache::get(self::key($user, $map), []),
            'places' => MapCameraController::placesFor($map),
        ]);
    }

    /**
     * The share window says which clients it has and how they stand.
     */
    public function report(Request $request, Map $map): JsonResponse
    {
        $user = $this->member($request, $map);
        $data = $request->validate([
            'clients' => ['present', 'array', 'max:12'],
            'clients.*.name' => ['required', 'string', 'max:80'],
            'clients.*.on' => ['required', 'boolean'],
            'clients.*.pin' => ['nullable', 'string', 'regex:/^([chs]:\d+)?$/'],
            'clients.*.boxes' => ['required', 'integer', 'min:0', 'max:100'],
            'clients.*.live' => ['required', 'integer', 'min:0', 'max:100'],
        ]);
        $clients = array_values(array_map(fn (array $client): array => [
            'name' => (string) $client['name'],
            'on' => (bool) $client['on'],
            'pin' => (string) ($client['pin'] ?? ''),
            'boxes' => (int) $client['boxes'],
            'live' => (int) $client['live'],
        ], $data['clients']));
        $key = self::key($user, $map);
        $changed = Cache::get($key) !== $clients;
        Cache::put($key, $clients, self::KEEP_SECONDS);
        if ($changed) {
            CameraClientsChangedEvent::dispatch($user->id, $map->id);
        }

        return response()->json(['saved' => true]);
    }

    /**
     * Turn a client's cameras on / off, or point them at a hole (sent to your share window).
     */
    public function control(Request $request, Map $map): JsonResponse
    {
        $user = $this->member($request, $map);
        $data = $request->validate([
            'client' => ['required', 'string', 'max:80'],
            'on' => ['nullable', 'boolean'],
            'pin' => ['nullable', 'string', 'regex:/^([chs]:\d+)?$/'],
        ]);
        /** @var list<array{name: string}> $clients */
        $clients = Cache::get(self::key($user, $map), []);
        $known = collect($clients)->contains(fn (array $client): bool => $client['name'] === $data['client']);
        if (! $known) {
            return response()->json(['sent' => false, 'message' => 'That client isn\'t in your share window (share its EVE window there first).'], 422);
        }
        CameraControlEvent::dispatch($user->id, $map->id, (string) $data['client'], isset($data['on']) ? (bool) $data['on'] : null, $request->has('pin') ? (string) ($data['pin'] ?? '') : null);

        return response()->json(['sent' => true]);
    }

    private function member(Request $request, Map $map): User
    {
        abort_unless(CameraPass::enabled(), 404);
        $user = $request->user();
        abort_unless($user instanceof User && $user->can('update', $map), 403);

        return $user;
    }

    private static function key(User $user, Map $map): string
    {
        return sprintf('camera-clients:%d:%d', $user->id, $map->id);
    }
}
