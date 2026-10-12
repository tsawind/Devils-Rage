<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapConnection;
use App\Models\MapConnectionJump;
use App\Models\MassObservation;
use App\Models\MassSession;
use App\Models\RollingCrew;
use App\Models\User;
use App\Support\MassSessions;

use function Pest\Laravel\actingAs;

function massUser(Map $map, Permission $permission = Permission::Member): User
{
    return User::factory()
        ->has(Character::factory()->has(MapAccess::factory(['permission' => $permission])->for($map)))
        ->create();
}

/**
 * @return array{map: Map, home: App\Models\MapSolarsystem, far: App\Models\MapSolarsystem, connection: MapConnection}
 */
function massHole(): array
{
    $map = Map::factory()->create();
    $home = placeMapSolarsystem($map, 31000301);
    $far = placeMapSolarsystem($map, 31000302, 300, 100);
    $connection = MapConnection::factory()->create(['map_id' => $map->id, 'from_map_solarsystem_id' => $home->id, 'to_map_solarsystem_id' => $far->id]);

    return compact('map', 'home', 'far', 'connection');
}

it('patch 37: a jump by a pilot in a running plan logs the plan step mass and ticks it off', function () {
    ['map' => $map, 'home' => $home, 'connection' => $connection] = massHole();
    $session = MassSession::query()->create([
        'map_id' => $map->id,
        'map_connection_id' => $connection->id,
        'goal' => 'roll',
        'home_map_solarsystem_id' => $home->id,
        'plan' => ['steps' => [
            ['character_id' => 77, 'direction' => 'out', 'mass' => 132_400_000, 'done' => false],
            ['character_id' => 77, 'direction' => 'home', 'mass' => 1_070_000, 'done' => false],
        ]],
    ]);

    expect(MassSessions::followPlan($connection, 77, 31000301))->toBe(132_400_000)
        ->and(MassSessions::followPlan($connection, 77, 31000302))->toBe(1_070_000)
        ->and(MassSessions::followPlan($connection, 77, 31000301))->toBeNull()
        ->and(collect($session->fresh()->plan['steps'])->every(fn (array $step): bool => $step['done']))->toBeTrue()
        ->and(MassSessions::followPlan($connection, 99, 31000301))->toBeNull();
});

it('patch 37: members start a session, everyone on the map sees it, and "made by the last jump" ties the mark to it', function () {
    ['map' => $map, 'home' => $home, 'connection' => $connection] = massHole();
    $user = massUser($map);
    MapConnectionJump::query()->create(['map_id' => $map->id, 'map_connection_id' => $connection->id, 'from_solarsystem_id' => 31000301, 'to_solarsystem_id' => 31000302, 'mass' => 32_400_000]);

    actingAs($user)->postJson(route('maps.mass-sessions.store', $map), ['map_connection_id' => $connection->id, 'home_map_solarsystem_id' => $home->id])->assertOk();
    $session = MassSession::query()->firstOrFail();

    actingAs($user)->postJson(route('maps.mass-sessions.observe', [$map, $session]), ['status' => 'critical', 'made_by_last_jump' => true])->assertOk();

    $observation = MassObservation::query()->where('map_connection_id', $connection->id)->latest('id')->firstOrFail();
    expect($observation->status)->toBe('critical')
        ->and($observation->logged_mass)->toBe(32_400_000)
        ->and($observation->flip_mass)->toBe(32_400_000);

    actingAs(massUser($map))->getJson(route('maps.mass-sessions.index', $map))
        ->assertOk()
        ->assertJsonPath('sessions.0.map_connection_id', $connection->id);
});

it('patch 37: only its owner changes a rolling crew; viewers cannot start sessions', function () {
    ['map' => $map, 'connection' => $connection] = massHole();
    $owner = massUser($map);
    $other = massUser($map);

    actingAs($owner)->postJson(route('maps.rolling-crews.store', $map), [
        'name' => 'Devoter rollers',
        'can_swap' => true,
        'members' => [['character_id' => 1, 'name' => 'Darkness Holder', 'expendable' => true]],
    ])->assertOk();
    $crew = RollingCrew::query()->firstOrFail();

    actingAs($other)->putJson(route('maps.rolling-crews.update', [$map, $crew]), ['name' => 'Mine now', 'members' => []])->assertForbidden();
    actingAs(massUser($map, Permission::Viewer))->postJson(route('maps.mass-sessions.store', $map), ['map_connection_id' => $connection->id])->assertForbidden();

    expect($crew->fresh()->name)->toBe('Devoter rollers')
        ->and($crew->fresh()->members[0]['expendable'])->toBeTrue();
});
