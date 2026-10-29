<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Events\Cameras\CameraActivityEvent;
use App\Events\Cameras\CamerasChangedEvent;
use App\Events\Cameras\CameraWantedEvent;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapCamera;
use App\Models\MapCameraEvent;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Models\User;
use App\Support\CameraPass;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withSession;

beforeEach(function (): void {
    config(['cameras.secret' => str_repeat('s', 64), 'cameras.rtc_url' => 'https://mapper.test/rtc']);
});

function cameraPilot(Map $map, Permission $permission = Permission::Member): User
{
    $user = User::factory()
        ->has(Character::factory()->has(MapAccess::factory(['permission' => $permission])->for($map)))
        ->create();
    $user->forceFill(['preferred_character_id' => $user->characters()->value('id')])->save();

    return $user->refresh();
}

function startCamera(Map $map, User $user, array $extra = []): array
{
    actingAs($user);

    return postJson(route('maps.cameras.store', $map), ['name' => 'Daisy hole', 'kind' => 'hole'] + $extra)
        ->assertCreated()
        ->json();
}

it('hides cameras when the relay is not set up', function () {
    config(['cameras.secret' => null]);
    $map = Map::factory()->create();
    actingAs(cameraPilot($map));

    postJson(route('maps.cameras.store', $map), ['name' => 'x', 'kind' => 'hole'])->assertNotFound();
});

it('lets a member start a camera and publish with the pass it gets', function () {
    Event::fake([CamerasChangedEvent::class]);
    $map = Map::factory()->create();
    $result = startCamera($map, cameraPilot($map));
    $camera = MapCamera::query()->sole();

    expect($result['publish']['url'])->toBe('https://mapper.test/rtc/'.$camera->path.'/whip')
        ->and($camera->status)->toBe('starting')
        ->and($camera->owner_name)->not->toBeNull();
    Event::assertDispatched(CamerasChangedEvent::class);

    postJson(route('api.cameras.relay-auth'), ['action' => 'publish', 'path' => $camera->path, 'token' => $result['publish']['pass']])->assertOk();
    expect($camera->refresh()->status)->toBe('live');
});

it('refuses the relay a wrong path, a wrong action or a forged pass', function () {
    $map = Map::factory()->create();
    $result = startCamera($map, cameraPilot($map));
    $camera = MapCamera::query()->sole();
    $pass = $result['publish']['pass'];

    postJson(route('api.cameras.relay-auth'), ['action' => 'publish', 'path' => 'cam-other', 'token' => $pass])->assertUnauthorized();
    postJson(route('api.cameras.relay-auth'), ['action' => 'read', 'path' => $camera->path, 'token' => $pass])->assertUnauthorized();
    postJson(route('api.cameras.relay-auth'), ['action' => 'publish', 'path' => $camera->path, 'token' => $pass.'x'])->assertUnauthorized();
    postJson(route('api.cameras.relay-auth'), ['action' => 'publish', 'path' => $camera->path])->assertUnauthorized();
});

it('expires passes', function () {
    $pass = CameraPass::make('cam-abc', 'read', 'user:1', time() - 1000);

    expect(CameraPass::check($pass, 'cam-abc', 'read'))->toBeNull()
        ->and(CameraPass::check(CameraPass::make('cam-abc', 'read', 'user:1'), 'cam-abc', 'read'))->toBe('user:1');
});

it('gives members a watch pass, and guests only when the camera allows their link', function () {
    $map = Map::factory()->create();
    $owner = cameraPilot($map);
    $viewerLink = $map->shareLinks()->create(['token' => 'view-token', 'kind' => 'map', 'access' => 'viewer', 'expires_at' => now()->addHours(3)]);
    startCamera($map, $owner);
    $camera = MapCamera::query()->sole();

    actingAs(cameraPilot($map));
    $watch = postJson(route('map-cameras.watch', $camera))->assertOk()->json();
    postJson(route('api.cameras.relay-auth'), ['action' => 'read', 'path' => $camera->path, 'token' => $watch['pass']])->assertOk();

    auth()->logout();
    $guest = ["map_share_token_{$map->id}" => 'view-token'];
    withSession($guest)->postJson(route('map-cameras.watch', $camera))->assertForbidden();
    withSession($guest)->getJson(route('maps.cameras.index', $map))->assertOk()->assertJsonCount(0, 'cameras');

    $camera->update(['guest_share_link_ids' => [$viewerLink->id]]);
    withSession($guest)->postJson(route('map-cameras.watch', $camera))->assertOk();
    withSession($guest)->getJson(route('maps.cameras.index', $map))->assertOk()->assertJsonCount(1, 'cameras')->assertJsonPath('member', false);
});

it('keeps viewers from starting cameras and others from changing one', function () {
    $map = Map::factory()->create();
    actingAs(cameraPilot($map, Permission::Viewer));
    postJson(route('maps.cameras.store', $map), ['name' => 'x', 'kind' => 'hole'])->assertForbidden();

    startCamera($map, cameraPilot($map));
    $camera = MapCamera::query()->sole();
    actingAs(cameraPilot($map));
    patchJson(route('map-cameras.update', $camera), ['status' => 'paused'])->assertForbidden();
});

it('lets the camera report activity on its hole, broadcast to the map, with a clip', function () {
    Storage::fake('local');
    Event::fake([CameraActivityEvent::class, CamerasChangedEvent::class]);
    $map = Map::factory()->create();
    $owner = cameraPilot($map);
    startCamera($map, $owner);
    $camera = MapCamera::query()->sole();

    actingAs($owner);
    postJson(route('map-cameras.events.store', $camera), [
        'kind' => 'activation',
        'strength' => 80,
        'heard_by' => 'both',
        'clip' => UploadedFile::fake()->create('clip.webm', 300, 'video/webm'),
        'still' => UploadedFile::fake()->image('still.jpg'),
    ])->assertCreated();

    $event = MapCameraEvent::query()->sole();
    expect($event->clip_path)->not->toBeNull()
        ->and($event->expires_at->gt(now()->addHours(23)))->toBeTrue();
    Storage::disk('local')->assertExists($event->clip_path);
    Event::assertDispatched(CameraActivityEvent::class, fn (CameraActivityEvent $sent): bool => $sent->kind === 'activation' && $sent->heard_by === 'both' && $sent->camera_id === $camera->id);

    actingAs(cameraPilot($map));
    postJson(route('map-cameras.events.store', $camera), ['kind' => 'activation'])->assertForbidden();
    patchJson(route('map-camera-events.update', $event), ['state' => 'confirmed', 'ship_class' => 'battleship', 'side' => 'hostile'])->assertOk();
    expect($event->refresh()->state)->toBe('confirmed');
});

it('takes the clip a few seconds after the event, from the camera owner only', function () {
    Storage::fake('local');
    Event::fake([CameraActivityEvent::class, CamerasChangedEvent::class]);
    $map = Map::factory()->create();
    $owner = cameraPilot($map);
    startCamera($map, $owner);
    $camera = MapCamera::query()->sole();

    actingAs($owner);
    $id = postJson(route('map-cameras.events.store', $camera), ['kind' => 'activation'])->assertCreated()->json('id');
    $event = MapCameraEvent::query()->findOrFail($id);
    expect($event->clip_path)->toBeNull();

    actingAs(cameraPilot($map));
    postJson(route('map-camera-events.attach', $event), ['clip' => UploadedFile::fake()->create('clip.webm', 100, 'video/webm')])->assertForbidden();

    actingAs($owner);
    postJson(route('map-camera-events.attach', $event), ['clip' => UploadedFile::fake()->create('clip.webm', 100, 'video/webm')])->assertOk();
    $event->refresh();
    Storage::disk('local')->assertExists($event->clip_path);
    Event::assertDispatchedTimes(CameraActivityEvent::class, 1);
});

it('ends cameras that stopped checking in and removes expired clips', function () {
    Storage::fake('local');
    $map = Map::factory()->create();
    $owner = cameraPilot($map);
    startCamera($map, $owner);
    $camera = MapCamera::query()->sole();
    Storage::disk('local')->put('camera-events/old.webm', 'x');
    MapCameraEvent::query()->create([
        'map_camera_id' => $camera->id, 'map_id' => $map->id, 'kind' => 'activation',
        'clip_path' => 'camera-events/old.webm', 'happened_at' => now()->subDays(2), 'expires_at' => now()->subHour(),
    ]);
    $camera->update(['last_seen_at' => now()->subMinutes(10)]);

    artisan('app:prune-cameras')->assertSuccessful();

    expect($camera->refresh()->status)->toBe('ended')
        ->and(MapCameraEvent::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing('camera-events/old.webm');
});

it('watches a hole nobody has jumped yet, then follows it onto its new pipe', function () {
    Event::fake([CameraActivityEvent::class, CamerasChangedEvent::class]);
    $map = Map::factory()->create();
    $daisy = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(31000005), 'alias' => 'Daisy']);
    $hole = Signature::factory()->create(['map_solarsystem_id' => $daisy->id, 'signature_id' => 'ZDV-123']);
    $owner = cameraPilot($map);
    startCamera($map, $owner, ['signature_id' => $hole->id]);
    $camera = MapCamera::query()->sole();
    expect($camera->signature_id)->toBe($hole->id);

    actingAs($owner);
    postJson(route('map-cameras.events.store', $camera), ['kind' => 'activation'])->assertCreated();
    Event::assertDispatched(CameraActivityEvent::class, fn (CameraActivityEvent $sent): bool => $sent->signature_id === $hole->id);

    $alpha = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(31000006), 'alias' => 'A']);
    $pipe = MapConnection::factory()->create(['map_id' => $map->id, 'from_map_solarsystem_id' => $daisy->id, 'to_map_solarsystem_id' => $alpha->id]);
    $hole->update(['map_connection_id' => $pipe->id]);

    expect($camera->refresh()->map_connection_id)->toBe($pipe->id)
        ->and(MapCameraEvent::query()->sole()->map_connection_id)->toBe($pipe->id);
});

it('refuses a hole from another map', function () {
    $map = Map::factory()->create();
    $other = MapSolarsystem::factory()->for(Map::factory())->create(['solarsystem_id' => makeSolarsystem(31000009)]);
    $hole = Signature::factory()->create(['map_solarsystem_id' => $other->id]);
    actingAs(cameraPilot($map));

    postJson(route('maps.cameras.store', $map), ['name' => 'x', 'kind' => 'hole', 'signature_id' => $hole->id])->assertUnprocessable();
});

it('answers the relay yes with an empty body, whichever way the browser sent the pass', function () {
    $map = Map::factory()->create();
    $result = startCamera($map, cameraPilot($map));
    $camera = MapCamera::query()->sole();
    $pass = $result['publish']['pass'];
    $ask = ['action' => 'publish', 'path' => $camera->path, 'protocol' => 'webrtc', 'user' => '', 'password' => '', 'token' => '', 'query' => ''];

    $yes = postJson(route('api.cameras.relay-auth'), ['token' => $pass] + $ask)->assertOk();
    expect($yes->getContent())->toBe('');
    postJson(route('api.cameras.relay-auth'), ['password' => $pass, 'user' => 'x'] + $ask)->assertOk();
    postJson(route('api.cameras.relay-auth'), ['query' => 'pass='.urlencode($pass)] + $ask)->assertOk();
    postJson(route('api.cameras.relay-auth'), $ask)->assertUnauthorized()->assertJsonPath('error', 'no pass');
});

it('keeps the still but not the clip once clips fill their share of the disk', function () {
    Storage::fake('local');
    Event::fake([CameraActivityEvent::class, CamerasChangedEvent::class]);
    config(['cameras.max_storage_mb' => 1]);
    Storage::disk('local')->put('camera-events/1/old.webm', str_repeat('x', 1024 * 1024 + 1));
    $map = Map::factory()->create();
    $owner = cameraPilot($map);
    startCamera($map, $owner);
    $camera = MapCamera::query()->sole();

    actingAs($owner);
    $id = postJson(route('map-cameras.events.store', $camera), ['kind' => 'activation'])->assertCreated()->json('id');
    postJson(route('map-camera-events.attach', $id), [
        'clip' => UploadedFile::fake()->create('clip.webm', 100, 'video/webm'),
        'still' => UploadedFile::fake()->image('still.jpg'),
    ])->assertOk();

    $event = MapCameraEvent::query()->findOrFail($id);
    expect($event->clip_path)->toBeNull()
        ->and($event->still_path)->not->toBeNull();
});

it('takes watch areas, which only report (probe scanner, local)', function () {
    Event::fake([CameraActivityEvent::class, CamerasChangedEvent::class]);
    $map = Map::factory()->create();
    $owner = cameraPilot($map);
    startCamera($map, $owner, ['kind' => 'watch', 'name' => 'Probe scanner']);
    $watch = MapCamera::query()->sole();

    actingAs($owner);
    postJson(route('map-cameras.events.store', $watch), ['kind' => 'scan_new', 'strength' => 25])->assertCreated();
    postJson(route('map-cameras.events.store', $watch), ['kind' => 'local_in'])->assertCreated();
    postJson(route('map-cameras.events.store', $watch), ['kind' => 'teleported'])->assertUnprocessable();
    expect(MapCameraEvent::query()->pluck('kind')->all())->toBe(['scan_new', 'local_in']);
});

it('puts the hole a watching camera is pinned to on Watching, and keeps it there', function () {
    Event::fake([CameraActivityEvent::class, CamerasChangedEvent::class]);
    $map = Map::factory()->create();
    $daisy = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(31000005), 'alias' => 'Daisy']);
    $bravo = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(31000008), 'alias' => 'B']);
    $pipe = MapConnection::factory()->create(['map_id' => $map->id, 'from_map_solarsystem_id' => $daisy->id, 'to_map_solarsystem_id' => $bravo->id]);
    $owner = cameraPilot($map);

    // A camera that only streams doesn't start a watch.
    startCamera($map, $owner, ['map_connection_id' => $pipe->id, 'detect' => false]);
    expect($pipe->refresh()->mass_watched_at)->toBeNull();

    startCamera($map, $owner, ['kind' => 'watch', 'name' => 'Hole', 'map_connection_id' => $pipe->id, 'detect' => true]);
    $pipe->refresh();
    expect($pipe->mass_watched_at)->not->toBeNull()
        ->and($pipe->mass_watched_by_name)->not->toBeNull();
});

it('remembers that a hit came while the pilot was playing that client', function () {
    Event::fake([CameraActivityEvent::class, CamerasChangedEvent::class]);
    $map = Map::factory()->create();
    $owner = cameraPilot($map);
    startCamera($map, $owner, ['kind' => 'watch', 'name' => 'Overview']);
    $watch = MapCamera::query()->sole();

    actingAs($owner);
    postJson(route('map-cameras.events.store', $watch), ['kind' => 'appeared', 'while_playing' => true])->assertCreated();
    getJson(route('maps.cameras.index', $map))->assertOk()->assertJsonPath('events.0.while_playing', true);
});

it('wakes a camera on standby when someone wants to watch it', function () {
    Event::fake([CameraWantedEvent::class, CamerasChangedEvent::class]);
    $map = Map::factory()->create();
    $owner = cameraPilot($map);
    startCamera($map, $owner);
    $camera = MapCamera::query()->sole();
    patchJson(route('map-cameras.update', $camera), ['status' => 'standby'])->assertOk();
    expect($camera->refresh()->status)->toBe('standby');

    actingAs(cameraPilot($map));
    getJson(route('maps.cameras.index', $map))->assertOk()->assertJsonCount(1, 'cameras');
    postJson(route('map-cameras.watch', $camera))->assertOk();
    postJson(route('map-cameras.watch', $camera))->assertOk();

    // Once, however many ask in the same 20 s; to the owner's own channel.
    Event::assertDispatchedTimes(CameraWantedEvent::class, 1);
    Event::assertDispatched(CameraWantedEvent::class, fn (CameraWantedEvent $sent): bool => $sent->camera_id === $camera->id && $sent->broadcastOn()[0]->name === "private-User.{$owner->id}");
});

it('dismisses many camera events at once, only waiting ones on this map', function () {
    $map = Map::factory()->create();
    $owner = cameraPilot($map);
    startCamera($map, $owner);
    $camera = MapCamera::query()->sole();
    foreach (['appeared', 'vanished', 'activation'] as $kind) {
        postJson(route('map-cameras.events.store', $camera), ['kind' => $kind])->assertCreated();
    }
    [$first, $second, $third] = MapCameraEvent::query()->orderBy('id')->get()->all();
    $third->update(['state' => 'confirmed']);
    $other = Map::factory()->create();
    $elsewhere = MapCameraEvent::query()->create(['map_camera_id' => $camera->id, 'map_id' => $other->id, 'kind' => 'appeared', 'happened_at' => now(), 'expires_at' => now()->addDay()]);

    postJson(route('maps.camera-events.dismiss', $map), ['ids' => [$first->id, $second->id, $third->id, $elsewhere->id]])
        ->assertOk()
        ->assertJsonPath('dismissed', 2);

    expect($first->refresh()->state)->toBe('dismissed')
        ->and($third->refresh()->state)->toBe('confirmed')
        ->and($elsewhere->refresh()->state)->toBe('new');

    actingAs(cameraPilot($map, Permission::Viewer));
    postJson(route('maps.camera-events.dismiss', $map), ['ids' => [$first->id]])->assertForbidden();
});

it('brings the same camera back when the same box is shared again', function () {
    $map = Map::factory()->create();
    $owner = cameraPilot($map);
    $first = startCamera($map, $owner, ['box_key' => 'abc123'])['camera']['id'];
    deleteJson(route('map-cameras.destroy', $first))->assertOk();

    $again = startCamera($map, $owner, ['box_key' => 'abc123'])['camera']['id'];
    $other = startCamera($map, $owner, ['box_key' => 'zzz999'])['camera']['id'];

    expect($again)->toBe($first)
        ->and($other)->not->toBe($first)
        ->and(MapCamera::query()->find($first)->status)->toBe('starting')
        ->and(MapCamera::query()->find($first)->ended_at)->toBeNull();

    // Someone else's box with the same key is their own camera.
    $mate = cameraPilot($map);
    expect(startCamera($map, $mate, ['box_key' => 'abc123'])['camera']['id'])->not->toBe($first);
});

it('keeps a pipe camera on its hole when the pipe is deleted and mapped again', function () {
    Event::fake([CameraActivityEvent::class, CamerasChangedEvent::class]);
    $map = Map::factory()->create();
    $daisy = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(31000005), 'alias' => 'Daisy']);
    $delta = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(31000007), 'alias' => 'D']);
    $pipe = MapConnection::factory()->create(['map_id' => $map->id, 'from_map_solarsystem_id' => $daisy->id, 'to_map_solarsystem_id' => $delta->id]);
    $hole = Signature::factory()->create(['map_solarsystem_id' => $daisy->id, 'signature_id' => 'CFL-960', 'map_connection_id' => $pipe->id]);
    $owner = cameraPilot($map);
    startCamera($map, $owner, ['map_connection_id' => $pipe->id]);
    $camera = MapCamera::query()->sole();
    expect($camera->signature_id)->toBe($hole->id);

    // Delta hidden: the hole stays as an unjumped signature, the pipe goes.
    $hole->update(['map_connection_id' => null]);
    $pipe->delete();
    expect($camera->refresh()->map_connection_id)->toBeNull()
        ->and($camera->signature_id)->toBe($hole->id);

    // Jumped again: a new pipe, and the camera is on it.
    $again = MapConnection::factory()->create(['map_id' => $map->id, 'from_map_solarsystem_id' => $daisy->id, 'to_map_solarsystem_id' => $delta->id]);
    $hole->update(['map_connection_id' => $again->id]);
    expect($camera->refresh()->map_connection_id)->toBe($again->id);
});
