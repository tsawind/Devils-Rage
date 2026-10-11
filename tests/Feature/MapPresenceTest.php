<?php

declare(strict_types=1);

use App\Enums\LifetimeStatus;
use App\Enums\Permission;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapConnection;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\actingAs;

/**
 * Patch 37: who has the map open. Everyone checks in; only managers read the list.
 */
function presenceUser(Map $map, Permission $permission, string $name): User
{
    $user = User::factory()
        ->has(Character::factory(['name' => $name])->has(MapAccess::factory(['permission' => $permission])->for($map)))
        ->create();
    $user->update(['preferred_character_id' => $user->characters()->value('id')]);

    return $user->fresh();
}

beforeEach(fn () => Cache::flush());

it('patch 37: a member checks in and a manager sees them', function () {
    $map = Map::factory()->create();
    $member = presenceUser($map, Permission::Member, 'WickedWrench');
    $manager = presenceUser($map, Permission::Manager, 'Tsawind');

    actingAs($member)->post(route('maps.presence.store', $map))->assertOk();

    actingAs($manager)->getJson(route('maps.presence.index', $map))
        ->assertOk()
        ->assertJsonPath('present.0.name', 'WickedWrench')
        ->assertJsonPath('present.0.permission', 'member')
        ->assertJsonPath('present.0.kind', 'pilot');
});

it('patch 37: a member cannot read who is in the mapper', function () {
    $map = Map::factory()->create();
    $member = presenceUser($map, Permission::Member, 'Kyle');

    actingAs($member)->getJson(route('maps.presence.index', $map))->assertForbidden();
});

it('patch 37: someone without access cannot check in', function () {
    $map = Map::factory()->create();
    $stranger = User::factory()->has(Character::factory())->create();

    actingAs($stranger)->post(route('maps.presence.store', $map))->assertForbidden();
});

it('patch 37: the age check does not un-fade a pipe that came back from memory', function () {
    $map = Map::factory()->create();
    $from = placeMapSolarsystem($map, 31000401);
    $to = placeMapSolarsystem($map, 31000402, 300, 300);
    $connection = MapConnection::factory()->create([
        'map_id' => $map->id,
        'from_map_solarsystem_id' => $from->id,
        'to_map_solarsystem_id' => $to->id,
        'from_memory' => true,
        'lifetime' => LifetimeStatus::Healthy,
    ]);

    $connection->update(['lifetime' => LifetimeStatus::EndOfLife, 'lifetime_updated_at' => now()]);
    expect($connection->fresh()->from_memory)->toBeTrue();

    $connection->fresh()->update(['checked_at' => now()]);
    expect($connection->fresh()->from_memory)->toBeFalse();
});
