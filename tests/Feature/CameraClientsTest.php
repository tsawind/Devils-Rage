<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Events\Cameras\CameraClientsChangedEvent;
use App\Events\Cameras\CameraControlEvent;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\User;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

beforeEach(function (): void {
    config(['cameras.secret' => str_repeat('s', 64), 'cameras.rtc_url' => 'https://mapper.test/rtc']);
});

function clientPilot(Map $map, Permission $permission = Permission::Member): User
{
    $user = User::factory()
        ->has(Character::factory()->has(MapAccess::factory(['permission' => $permission])->for($map)))
        ->create();
    $user->forceFill(['preferred_character_id' => $user->characters()->value('id')])->save();

    return $user->refresh();
}

function oneClient(array $extra = []): array
{
    return ['clients' => [['name' => 'LuigiSquirrel', 'on' => false, 'pin' => '', 'boxes' => 3, 'live' => 0] + $extra]];
}

it('keeps what the share window reports and tells your maps once it changes', function () {
    Event::fake([CameraClientsChangedEvent::class]);
    $map = Map::factory()->create();
    $user = clientPilot($map);
    actingAs($user);

    putJson(route('maps.camera-clients.report', $map), oneClient())->assertSuccessful();
    putJson(route('maps.camera-clients.report', $map), oneClient())->assertSuccessful();

    Event::assertDispatchedTimes(CameraClientsChangedEvent::class, 1);
    getJson(route('maps.camera-clients.index', $map))
        ->assertSuccessful()
        ->assertJsonPath('clients.0.name', 'LuigiSquirrel')
        ->assertJsonPath('clients.0.boxes', 3)
        ->assertJsonStructure(['places' => ['systems', 'connections', 'holes']]);
});

it('keeps each pilot\'s clients apart', function () {
    $map = Map::factory()->create();
    actingAs(clientPilot($map));
    putJson(route('maps.camera-clients.report', $map), oneClient())->assertSuccessful();

    actingAs(clientPilot($map));
    getJson(route('maps.camera-clients.index', $map))->assertSuccessful()->assertJsonPath('clients', []);
});

it('sends the map\'s ask to your share window', function () {
    Event::fake([CameraControlEvent::class, CameraClientsChangedEvent::class]);
    $map = Map::factory()->create();
    $user = clientPilot($map);
    actingAs($user);
    putJson(route('maps.camera-clients.report', $map), oneClient())->assertSuccessful();

    postJson(route('maps.camera-clients.control', $map), ['client' => 'LuigiSquirrel', 'on' => true, 'pin' => 'c:77'])
        ->assertSuccessful()
        ->assertJsonPath('sent', true);

    Event::assertDispatched(CameraControlEvent::class, fn (CameraControlEvent $event): bool => $event->user_id === $user->id
        && $event->client === 'LuigiSquirrel' && $event->on === true && $event->pin === 'c:77'
        && $event->broadcastOn()[0]->name === "private-User.{$user->id}");
});

it('leaves on / off alone when the map only moves the camera', function () {
    Event::fake([CameraControlEvent::class, CameraClientsChangedEvent::class]);
    $map = Map::factory()->create();
    actingAs(clientPilot($map));
    putJson(route('maps.camera-clients.report', $map), oneClient())->assertSuccessful();

    postJson(route('maps.camera-clients.control', $map), ['client' => 'LuigiSquirrel', 'pin' => 's:4'])->assertSuccessful();

    Event::assertDispatched(CameraControlEvent::class, fn (CameraControlEvent $event): bool => $event->on === null && $event->pin === 's:4');
});

it('refuses a client the share window does not have', function () {
    Event::fake([CameraControlEvent::class]);
    $map = Map::factory()->create();
    actingAs(clientPilot($map));

    postJson(route('maps.camera-clients.control', $map), ['client' => 'Nobody', 'on' => true])->assertUnprocessable();
    Event::assertNotDispatched(CameraControlEvent::class);
});

it('refuses bad pins and reports', function (array $body) {
    $map = Map::factory()->create();
    actingAs(clientPilot($map));

    putJson(route('maps.camera-clients.report', $map), $body)->assertUnprocessable();
})->with([
    'pin that is not a place' => [oneClient(['pin' => 'x:1'])],
    'no name' => [['clients' => [['on' => true, 'boxes' => 1, 'live' => 0]]]],
    'no list' => [[]],
]);

it('keeps viewers and the relay-less mapper out', function () {
    $map = Map::factory()->create();
    actingAs(clientPilot($map, Permission::Viewer));
    getJson(route('maps.camera-clients.index', $map))->assertForbidden();

    config(['cameras.secret' => null]);
    actingAs(clientPilot($map));
    getJson(route('maps.camera-clients.index', $map))->assertNotFound();
});
