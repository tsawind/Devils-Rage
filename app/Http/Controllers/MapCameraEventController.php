<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\Cameras\CameraActivityEvent;
use App\Events\Cameras\CamerasChangedEvent;
use App\Models\MapCamera;
use App\Models\MapCameraEvent;
use App\Models\MapConnectionJump;
use App\Models\MapShareLink;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Patch 62: what a hole camera saw. The camera's page sends a possible jump (activation, a
 * ship appearing / vanishing at the hole) with a ~10 s clip and a still; members confirm it
 * (after logging the jump the usual way, which links it here) or dismiss it. Files are
 * private (local disk) and deleted when the event expires.
 */
final class MapCameraEventController extends Controller
{
    private const string DISK = 'local';

    public function store(Request $request, MapCamera $mapCamera): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($mapCamera->user_id === $user->id && $mapCamera->status !== 'ended', 403);
        $maxKb = (int) config('cameras.max_clip_kb');
        $data = $request->validate([
            'kind' => ['required', Rule::in(MapCameraEvent::KINDS)],
            'strength' => ['sometimes', 'integer', 'between:0,100'],
            'happened_at' => ['sometimes', 'date'],
            'heard_by' => ['sometimes', Rule::in(['picture', 'sound', 'both'])],
            'clip' => ['sometimes', 'file', 'max:'.$maxKb, 'mimetypes:video/webm,video/mp4,video/x-matroska,application/octet-stream'],
            'still' => ['sometimes', 'file', 'max:'.$maxKb, 'mimes:jpg,jpeg,png,webp'],
        ]);
        $folder = 'camera-events/'.$mapCamera->map_id;
        $clip = $request->file('clip');
        $still = $request->file('still');
        $happened = isset($data['happened_at']) ? now()->parse((string) $data['happened_at']) : now();
        if ($happened->gt(now()) || $happened->lt(now()->subMinutes(10))) {
            $happened = now();
        }

        $event = MapCameraEvent::query()->create([
            'map_camera_id' => $mapCamera->id,
            'map_id' => $mapCamera->map_id,
            'map_connection_id' => $mapCamera->map_connection_id,
            'kind' => $data['kind'],
            'strength' => (int) ($data['strength'] ?? 0),
            'clip_path' => $clip !== null ? $clip->storeAs($folder, Str::uuid()->toString().'.webm', self::DISK) : null,
            'still_path' => $still !== null ? $still->storeAs($folder, Str::uuid()->toString().'.jpg', self::DISK) : null,
            'state' => 'new',
            'happened_at' => $happened,
            'expires_at' => now()->addHours((int) config('cameras.clip_hours')),
        ]);
        $mapCamera->fill(['last_seen_at' => now()])->save();
        CameraActivityEvent::dispatch(
            $mapCamera->map_id,
            $event->id,
            $mapCamera->id,
            $mapCamera->name,
            $mapCamera->map_connection_id,
            $mapCamera->map_solarsystem_id,
            $event->kind,
            $event->happened_at->toIso8601String(),
            (string) ($data['heard_by'] ?? 'picture'),
        );

        return response()->json(['id' => $event->id], 201);
    }

    /**
     * The clip (and still) come a few seconds after the event itself, so the map's popup
     * doesn't wait for the recording: the camera's page sends them here once it has them.
     */
    public function attach(Request $request, MapCameraEvent $mapCameraEvent): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $camera = $mapCameraEvent->loadMissing('camera')->camera;
        abort_unless($camera->user_id === $user->id, 403);
        $maxKb = (int) config('cameras.max_clip_kb');
        $request->validate([
            'clip' => ['sometimes', 'file', 'max:'.$maxKb, 'mimetypes:video/webm,video/mp4,video/x-matroska,application/octet-stream'],
            'still' => ['sometimes', 'file', 'max:'.$maxKb, 'mimes:jpg,jpeg,png,webp'],
        ]);
        $folder = 'camera-events/'.$camera->map_id;
        foreach (['clip' => 'webm', 'still' => 'jpg'] as $which => $extension) {
            $file = $request->file($which);
            if ($file === null) {
                continue;
            }
            $column = $which.'_path';
            $old = $mapCameraEvent->{$column};
            $mapCameraEvent->{$column} = $file->storeAs($folder, Str::uuid()->toString().'.'.$extension, self::DISK);
            if (is_string($old)) {
                Storage::disk(self::DISK)->delete($old);
            }
        }
        $mapCameraEvent->save();
        CamerasChangedEvent::dispatch($camera->map_id);

        return response()->json(['id' => $mapCameraEvent->id]);
    }

    /**
     * A member confirms (optionally with the jump they just logged) or dismisses an event.
     */
    public function update(Request $request, MapCameraEvent $mapCameraEvent): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $map = $mapCameraEvent->loadMissing('camera.map')->camera->map;
        abort_unless($user->can('update', $map), 403);
        $data = $request->validate([
            'state' => ['required', Rule::in(['confirmed', 'dismissed', 'new'])],
            'ship_class' => ['sometimes', 'nullable', Rule::in(['frigate', 'destroyer', 'cruiser', 'battlecruiser', 'battleship', 'industrial', 'capital', 'typed'])],
            'side' => ['sometimes', 'nullable', Rule::in(['hostile', 'friendly'])],
            'map_connection_jump_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        if (isset($data['map_connection_jump_id'])) {
            $jump = MapConnectionJump::query()->where('map_id', $map->id)->find($data['map_connection_jump_id']);
            abort_unless($jump instanceof MapConnectionJump, 422);
        }
        $mapCameraEvent->fill($data + ['handled_by_user_id' => $user->id])->save();

        return response()->json(['state' => $mapCameraEvent->state]);
    }

    /** The clip or still, for members and the guests whose link the camera allows. */
    public function file(Request $request, MapCameraEvent $mapCameraEvent, string $which): StreamedResponse
    {
        abort_unless(in_array($which, ['clip', 'still'], true), 404);
        $camera = $mapCameraEvent->loadMissing('camera.map')->camera;
        $map = $camera->map;
        $user = $request->user();
        $member = $user instanceof User && $user->can('update', $map);
        if (! $member) {
            $token = session("map_share_token_{$map->id}");
            $link = is_string($token) && $token !== '' ? $map->shareLinks()->where('kind', 'map')->where('token', $token)->live()->first() : null;
            abort_unless($link instanceof MapShareLink && in_array($link->id, $camera->guest_share_link_ids ?? [], true), 403);
        }
        $path = $which === 'clip' ? $mapCameraEvent->clip_path : $mapCameraEvent->still_path;
        abort_unless(is_string($path) && Storage::disk(self::DISK)->exists($path), 404);

        return Storage::disk(self::DISK)->response($path, null, [
            'Content-Type' => $which === 'clip' ? 'video/webm' : 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
