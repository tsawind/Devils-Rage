<?php

declare(strict_types=1);

use App\Actions\MapSolarsystem\StoreMapSolarsystemAction;
use App\Enums\Permission;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapConnection;
use App\Models\Signature;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Patch 53: double-click a system (or a dotted hole) → the real J-code.
 */
function realSystemMember(Map $map): User
{
    return User::factory()
        ->has(Character::factory()->has(MapAccess::factory(['permission' => Permission::Member])->for($map)))
        ->create();
}

it('changes a system on the map to the right one, keeping its name and pipes', function () {
    $map = Map::factory()->create();
    $home = placeMapSolarsystem($map, 31000301);
    $wrong = placeMapSolarsystem($map, 31000302);
    $wrong->forceFill(['alias' => 'A'])->save();
    $pipe = MapConnection::query()->create(['map_id' => $map->id, 'from_map_solarsystem_id' => $home->id, 'to_map_solarsystem_id' => $wrong->id]);
    $right = makeSolarsystem(31000303);

    actingAs(realSystemMember($map))->put(route('map-solarsystems.system.update', $wrong), ['solarsystem_id' => $right])
        ->assertRedirect()->assertSessionHasNoErrors();

    $wrong->refresh();
    expect($wrong->solarsystem_id)->toBe($right)
        ->and($wrong->alias)->toBe('A')
        ->and($pipe->refresh()->to_map_solarsystem_id)->toBe($wrong->id)
        ->and($wrong->details->solarsystem_id)->toBe($right);
});

it('refuses a system that is on the map already', function () {
    $map = Map::factory()->create();
    $one = placeMapSolarsystem($map, 31000301);
    $other = placeMapSolarsystem($map, 31000302);

    actingAs(realSystemMember($map))->put(route('map-solarsystems.system.update', $one), ['solarsystem_id' => $other->solarsystem_id])
        ->assertSessionHasErrors('solarsystem_id');

    expect($one->refresh()->solarsystem_id)->toBe(31000301);
});

it('puts a dotted hole\'s far side on the map, connected through that hole and named like it', function () {
    $map = Map::factory()->create();
    $home = placeMapSolarsystem($map, 31000301);
    $hole = Signature::factory()->create(['map_solarsystem_id' => $home->id, 'signature_id' => 'ABC-123', 'map_connection_id' => null]);
    $far = makeSolarsystem(31000304);

    $target = app(StoreMapSolarsystemAction::class)->handle($map, [
        'solarsystem_id' => $far,
        'position_x' => 200,
        'position_y' => 200,
        'connect_to_map_solarsystem_id' => $home->id,
        'link_signature_id' => $hole->id,
        'alias' => 'A2',
    ]);

    $hole->refresh();
    expect($hole->map_connection_id)->not->toBeNull()
        ->and(MapConnection::query()->find($hole->map_connection_id)?->to_map_solarsystem_id)->toBe($target->id)
        ->and($target->refresh()->alias)->toBe('A2');
});

it('a second dotted hole into a system already connected makes a second pipe (a loop)', function () {
    $map = Map::factory()->create();
    $home = placeMapSolarsystem($map, 31000301);
    $alpha = placeMapSolarsystem($map, 31000302);
    MapConnection::query()->create(['map_id' => $map->id, 'from_map_solarsystem_id' => $home->id, 'to_map_solarsystem_id' => $alpha->id]);
    $hole = Signature::factory()->create(['map_solarsystem_id' => $home->id, 'signature_id' => 'BBB-222', 'map_connection_id' => null]);

    app(StoreMapSolarsystemAction::class)->handle($map, [
        'solarsystem_id' => $alpha->solarsystem_id,
        'position_x' => 200,
        'position_y' => 200,
        'connect_to_map_solarsystem_id' => $home->id,
        'link_signature_id' => $hole->id,
    ]);

    expect(MapConnection::query()->where('map_id', $map->id)->count())->toBe(2)
        ->and($hole->refresh()->map_connection_id)->not->toBeNull();
});
