<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\Cameras\CamerasChangedEvent;
use App\Events\Cameras\CameraWantedEvent;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapCamera;
use App\Models\MapCameraEvent;
use App\Models\MapConnection;
use App\Models\MapShareLink;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Models\User;
use App\Support\Broadcasting\MapBroadcaster;
use App\Support\CameraPass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Patch 62: cameras. A member streams part (or all) of an EVE window or their screen through
 * the video relay; members watch, and so do the guest links a camera allows. The browser
 * talks to the relay itself (WHIP / WHEP); the mapper only hands out short passes.
 */
final class MapCameraController extends Controller
{
    /**
     * The camera wall (members, and guests whose link a camera allows).
     */
    public function wall(Request $request, Map $map): Response
    {
        abort_unless(CameraPass::enabled(), 404);
        $user = $request->user();
        $member = $user instanceof User && $user->can('update', $map);
        abort_unless($member || $this->guestLink($map) instanceof MapShareLink, 403);

        return Inertia::render('maps/Cameras', [
            'map' => ['id' => $map->id, 'slug' => $map->slug, 'name' => $map->name],
            'member' => $member,
            'places' => $member ? self::placesFor($map) : ['systems' => [], 'connections' => [], 'holes' => []],
        ]);
    }

    /**
     * "Share a camera": pick a window or screen, draw boxes, stream (members).
     */
    public function share(Request $request, Map $map): Response
    {
        abort_unless(CameraPass::enabled(), 404);
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('update', $map), 403);

        return Inertia::render('maps/CameraShare', [
            'map' => ['id' => $map->id, 'slug' => $map->slug, 'name' => $map->name],
            'places' => self::placesFor($map),
            // Online characters first (the one whose window is being shared is almost always online).
            'characters' => $user->characters()->with('characterStatus')->orderBy('name')->get()
                ->sortByDesc(fn (Character $character): bool => (bool) $character->characterStatus?->is_online)
                ->values()
                ->map(fn (Character $character): array => ['id' => $character->id, 'name' => $character->name, 'online' => (bool) $character->characterStatus?->is_online])
                ->all(),
            'share_links' => array_values(array_filter(MapShareLinkController::linksFor($map, $user), fn (array $link): bool => $link['kind'] === 'map')),
        ]);
    }

    /**
     * Cameras that are on, and recent hole activity, for everyone who may see them.
     */
    public function index(Request $request, Map $map): JsonResponse
    {
        abort_unless(CameraPass::enabled(), 404);
        $user = $request->user();
        $member = $user instanceof User && $user->can('update', $map);
        $link = $member ? null : $this->guestLink($map);
        abort_unless($member || $link instanceof MapShareLink, 403);
        $linkId = $link instanceof MapShareLink ? $link->id : null;

        $cameras = MapCamera::query()
            ->where('map_id', $map->id)
            ->whereIn('status', MapCamera::ON_STATUSES)
            ->where('last_seen_at', '>', now()->subSeconds((int) config('cameras.stale_seconds')))
            ->orderBy('name')
            ->get()
            ->filter(fn (MapCamera $camera): bool => $member || in_array($linkId, $camera->guest_share_link_ids ?? [], true))
            ->values();
        $visible = $cameras->pluck('id')->all();
        $events = MapCameraEvent::query()
            ->where('map_id', $map->id)
            ->where('expires_at', '>', now())
            ->when(! $member, fn ($query) => $query->whereIn('map_camera_id', $visible))
            ->latest('happened_at')
            ->limit(100)
            ->get();

        return response()->json([
            'member' => $member,
            'cameras' => $cameras->map(fn (MapCamera $camera): array => $this->present($camera, $user))->all(),
            'events' => $events->map(fn (MapCameraEvent $event): array => $this->presentEvent($event))->all(),
        ]);
    }

    /**
     * Start a camera: the browser then publishes to the relay with the pass it gets back.
     */
    public function store(Request $request, Map $map): JsonResponse
    {
        abort_unless(CameraPass::enabled(), 404);
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('update', $map), 403);
        $data = $this->validated($request, $map, true);

        $character = $user->characters()->find($request->integer('character_id')) ?? $user->active_character;
        $camera = MapCamera::query()->create($data + [
            'map_id' => $map->id,
            'user_id' => $user->id,
            'character_id' => $character?->id,
            'character_name' => $character?->name,
            'owner_name' => $character instanceof Character ? ($character->seat_main_name ?? $character->name) : $user->name,
            'path' => MapCamera::newPath(),
            'status' => 'starting',
            'last_seen_at' => now(),
        ]);
        CamerasChangedEvent::dispatch($map->id);
        $this->watchHole($camera);

        return response()->json([
            'camera' => $this->present($camera, $user),
            'publish' => $this->publishPass($camera, $user),
        ], 201);
    }

    /**
     * The camera's page checks in about every 30 s (status live / paused) and may change its
     * name, pin, guests or detection; "reconnect" asks for a fresh publish pass.
     */
    public function update(Request $request, MapCamera $mapCamera): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $map = $mapCamera->loadMissing('map')->map;
        abort_unless($mapCamera->user_id === $user->id, 403);
        $data = $this->validated($request, $map, false);
        $status = $request->input('status');
        if (in_array($status, ['live', 'paused', 'standby'], true)) {
            $data['status'] = $status;
        }
        $mapCamera->fill($data);
        $changed = $mapCamera->isDirty();
        $mapCamera->fill(['last_seen_at' => now()])->save();
        $this->watchHole($mapCamera);
        if ($changed) {
            CamerasChangedEvent::dispatch($map->id);
        }

        return response()->json([
            'camera' => $this->present($mapCamera, $user),
            'publish' => $request->boolean('reconnect') ? $this->publishPass($mapCamera, $user) : null,
        ]);
    }

    /** Stop a camera (its owner, or a map manager). */
    public function destroy(Request $request, MapCamera $mapCamera): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($mapCamera->user_id === $user->id || $user->can('manageAccess', $mapCamera->loadMissing('map')->map), 403);
        $mapCamera->fill(['status' => 'ended', 'ended_at' => now()])->save();
        CamerasChangedEvent::dispatch($mapCamera->map_id);

        return response()->json(['ended' => true]);
    }

    /**
     * A pass to watch one camera: members, or a guest whose link this camera allows.
     */
    public function watch(Request $request, MapCamera $mapCamera): JsonResponse
    {
        abort_unless(CameraPass::enabled(), 404);
        $map = $mapCamera->loadMissing('map')->map;
        $user = $request->user();
        $member = $user instanceof User && $user->can('update', $map);
        $link = $member ? null : $this->guestLink($map);
        $allowed = $member || ($link instanceof MapShareLink && in_array($link->id, $mapCamera->guest_share_link_ids ?? [], true));
        abort_unless($allowed && $mapCamera->isOn(), 403);
        $who = $member ? 'user:'.$user->id : 'link:'.$link?->id;
        // Patch 62m: a camera on standby starts streaming for its viewers (its share window
        // stops again a couple of minutes after the last one leaves). Viewers ask again every minute.
        if ($mapCamera->user_id !== null && Cache::add('camera-wanted:'.$mapCamera->id, true, 20)) {
            CameraWantedEvent::dispatch($mapCamera->user_id, $mapCamera->map_id, $mapCamera->id);
        }

        return response()->json([
            'url' => rtrim((string) config('cameras.rtc_url'), '/').'/'.$mapCamera->path.'/whep',
            'pass' => CameraPass::make($mapCamera->path, 'read', $who),
        ]);
    }

    /**
     * The relay (MediaMTX) asks whether a publish / watch may go ahead. Answers 200 or 401.
     * MediaMTX sends {user, password, token, ip, action, path, protocol, id, query}.
     */
    public function relayAuth(Request $request): HttpResponse|JsonResponse
    {
        $action = (string) $request->input('action');
        $path = (string) $request->input('path');
        $pass = (string) ($request->input('token') ?: $request->input('password') ?: '');
        if ($pass === '') {
            parse_str((string) $request->input('query'), $query);
            $pass = is_string($query['pass'] ?? null) ? $query['pass'] : '';
        }
        $refuse = function (string $why) use ($request, $action, $path): JsonResponse {
            // Patch 62c: why, for cameras-check (no passes or addresses in the log).
            Log::info('Cameras relay refused: '.$why, [
                'action' => $action,
                'protocol' => (string) $request->input('protocol'),
                'path_ok' => preg_match('/^cam-[a-z0-9]{20,40}$/', $path) === 1,
                'sent' => array_values(array_filter(['user', 'password', 'token', 'query'], fn (string $field): bool => (string) $request->input($field) !== '')),
            ]);

            return response()->json(['error' => $why], 401);
        };
        if (! in_array($action, CameraPass::ACTIONS, true)) {
            return $refuse('action '.$action);
        }
        if ($pass === '') {
            return $refuse('no pass');
        }
        if (CameraPass::check($pass, $path, $action) === null) {
            return $refuse('pass not good for this path / action (or expired)');
        }
        $camera = MapCamera::query()->where('path', $path)->first();
        if (! $camera instanceof MapCamera || $camera->status === 'ended') {
            return $refuse('camera gone or ended');
        }
        if ($action === 'publish') {
            $wasLive = $camera->status === 'live';
            $camera->fill(['status' => 'live', 'last_seen_at' => now()])->save();
            if (! $wasLive) {
                CamerasChangedEvent::dispatch($camera->map_id);
            }
        }

        // Patch 62c: an empty 200. The relay reads a body as its own reply (expireAt), and an
        // unknown field ({"ok": true}) made it count the yes as a no.
        return response('', 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Map $map, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$required, 'string', 'max:80'],
            'kind' => [$required, Rule::in(MapCamera::KINDS)],
            'quality' => ['sometimes', Rule::in(MapCamera::QUALITIES)],
            'detect' => ['sometimes', 'boolean'],
            'map_solarsystem_id' => ['sometimes', 'nullable', 'integer', Rule::exists(MapSolarsystem::class, 'id')->where('map_id', $map->id)],
            'map_connection_id' => ['sometimes', 'nullable', 'integer', Rule::exists(MapConnection::class, 'id')->where('map_id', $map->id)],
            'signature_id' => ['sometimes', 'nullable', 'integer', Rule::exists(Signature::class, 'id')->whereIn('map_solarsystem_id', $map->mapSolarsystems()->pluck('id')->all())],
            'guest_share_link_ids' => ['sometimes', 'nullable', 'array', 'max:20'],
            'guest_share_link_ids.*' => ['integer', Rule::exists(MapShareLink::class, 'id')->where('map_id', $map->id)],
            'character_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        unset($data['character_id']);
        if (array_key_exists('guest_share_link_ids', $data)) {
            $data['guest_share_link_ids'] = array_values(array_unique(array_map('intval', $data['guest_share_link_ids'] ?? [])));
        }

        return $data;
    }

    /**
     * @return array{url: string, pass: string}
     */
    private function publishPass(MapCamera $camera, User $user): array
    {
        return [
            'url' => rtrim((string) config('cameras.rtc_url'), '/').'/'.$camera->path.'/whip',
            'pass' => CameraPass::make($camera->path, 'publish', 'user:'.$user->id),
        ];
    }

    /**
     * Patch 62g: a camera or watch area that watches a hole puts that hole on Watching (the 👁
     * on its pill and the watcher on everyone's map), when nobody is watching it yet; while it's
     * on, it answers the watcher's "still on it?" for you.
     */
    private function watchHole(MapCamera $camera): void
    {
        if (! $camera->detect || ! $camera->isOn()) {
            return;
        }
        $broadcaster = app(MapBroadcaster::class);
        $who = $camera->owner_name ?? $camera->character_name;
        if ($camera->map_connection_id !== null) {
            $connection = MapConnection::query()->find($camera->map_connection_id);
            if (! $connection instanceof MapConnection) {
                return;
            }
            if ($connection->mass_watched_at === null) {
                $connection->forceFill(['mass_watched_at' => now(), 'mass_watched_by_name' => $who, 'mass_watch_open' => true, 'mass_watch_touched_at' => now()])->save();
            } elseif ($connection->mass_watch_touched_at === null || $connection->mass_watch_touched_at->lt(now()->subMinutes(10))) {
                $connection->forceFill(['mass_watch_touched_at' => now()])->save();
            } else {
                return;
            }
            $broadcaster->connectionsUpserted($connection->map_id, MapConnection::query()->whereKey($connection->id)->with('signatures.signatureType', 'signatures.wormhole')->withJumpSummary()->get());

            return;
        }
        if ($camera->signature_id !== null) {
            $signature = Signature::query()->with('mapSolarsystem')->find($camera->signature_id);
            if ($signature instanceof Signature && $signature->mass_watched_at === null) {
                $signature->forceFill(['mass_watched_at' => now(), 'mass_watched_by_name' => $who])->save();
                $broadcaster->signaturesChanged($signature->mapSolarsystem);
            }
        }
    }

    /**
     * Systems and pipes a camera can be pinned to.
     *
     * @return array{systems: list<array{id: int, name: string}>, connections: list<array{id: int, name: string}>, holes: list<array{id: int, name: string}>}
     */
    public static function placesFor(Map $map): array
    {
        $systems = $map->mapSolarsystems()->with('solarsystem:id,name')->get();
        $names = $systems->mapWithKeys(fn (MapSolarsystem $system): array => [$system->id => $system->alias ?: $system->solarsystem->name]);

        return [
            'systems' => $systems->map(fn (MapSolarsystem $system): array => ['id' => $system->id, 'name' => (string) $names[$system->id]])->sortBy('name')->values()->all(),
            'connections' => $map->mapConnections()->get(['id', 'from_map_solarsystem_id', 'to_map_solarsystem_id'])
                ->map(fn (MapConnection $connection): array => ['id' => $connection->id, 'name' => ($names[$connection->from_map_solarsystem_id] ?? '?').' → '.($names[$connection->to_map_solarsystem_id] ?? '?')])
                ->sortBy('name')->values()->all(),
            // Patch 62b: holes nobody has jumped yet (the dotted pipes): "Daisy → Alpha · ZDV (V753)".
            'holes' => Signature::query()
                ->whereIn('map_solarsystem_id', $systems->pluck('id'))
                ->whereNull('map_connection_id')
                ->whereRelation('signatureCategory', 'code', \App\Enums\SignatureCategory::Wormhole)
                ->with('wormhole:id,name')
                ->get()
                ->map(fn (Signature $signature): array => [
                    'id' => $signature->id,
                    'name' => trim(($names[$signature->map_solarsystem_id] ?? '?').' → '.($signature->alias !== null && $signature->alias !== '' ? $signature->alias.' · ' : '').mb_strtoupper(mb_substr((string) $signature->signature_id, 0, 3)).($signature->wormhole !== null ? ' ('.$signature->wormhole->name.')' : '')),
                ])
                ->sortBy('name')->values()->all(),
        ];
    }

    /** The live share link this guest opened the map with, if any. */
    private function guestLink(Map $map): ?MapShareLink
    {
        $token = session("map_share_token_{$map->id}");
        if (! is_string($token) || $token === '') {
            return null;
        }

        return $map->shareLinks()->where('kind', 'map')->where('token', $token)->live()->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MapCamera $camera, ?User $viewer): array
    {
        return [
            'id' => $camera->id,
            'name' => $camera->name,
            'kind' => $camera->kind,
            'status' => $camera->status,
            'quality' => $camera->quality,
            'detect' => $camera->detect,
            'map_solarsystem_id' => $camera->map_solarsystem_id,
            'map_connection_id' => $camera->map_connection_id,
            'signature_id' => $camera->signature_id,
            'owner' => $camera->owner_name,
            'character' => $camera->character_name,
            'mine' => $viewer instanceof User && $camera->user_id === $viewer->id,
            'guest_share_link_ids' => $viewer instanceof User && $camera->user_id === $viewer->id ? ($camera->guest_share_link_ids ?? []) : null,
            'since' => $camera->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentEvent(MapCameraEvent $event): array
    {
        return [
            'id' => $event->id,
            'map_camera_id' => $event->map_camera_id,
            'map_connection_id' => $event->map_connection_id,
            'signature_id' => $event->signature_id,
            'kind' => $event->kind,
            'strength' => $event->strength,
            'while_playing' => $event->while_playing,
            'state' => $event->state,
            'ship_class' => $event->ship_class,
            'side' => $event->side,
            'map_connection_jump_id' => $event->map_connection_jump_id,
            'clip_url' => $event->clip_path !== null ? route('map-camera-events.file', [$event, 'clip']) : null,
            'still_url' => $event->still_path !== null ? route('map-camera-events.file', [$event, 'still']) : null,
            'happened_at' => $event->happened_at->toIso8601String(),
        ];
    }
}
