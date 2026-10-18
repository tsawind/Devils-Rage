<?php

declare(strict_types=1);

use App\Enums\MassStatus;
use App\Enums\Permission;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapConnection;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * Patch 40c: watching (the 👁) doesn't change the mass math; "every jump logged" is its own
 * switch; a watched hole never breaks the map page.
 */
function p40Member(Map $map): User
{
    $user = User::factory()
        ->has(Character::factory(['name' => 'WatchPilot'])->has(MapAccess::factory(['permission' => Permission::Member])->for($map)))
        ->create();
    $user->update(['preferred_character_id' => $user->characters()->value('id')]);

    return $user->fresh();
}

function p40Hole(Map $map): MapConnection
{
    $from = placeMapSolarsystem($map, 31000501);
    $to = placeMapSolarsystem($map, 31000502, 300, 300);

    return MapConnection::factory()->create([
        'map_id' => $map->id,
        'from_map_solarsystem_id' => $from->id,
        'to_map_solarsystem_id' => $to->id,
        'mass_status' => MassStatus::Fresh,
    ]);
}

it('watches without touching the mass math, then counts every jump only when switched on', function () {
    $map = Map::factory()->create();
    $hole = p40Hole($map);
    actingAs(p40Member($map));

    post(route('map-connections.watched', $hole), ['on' => true])->assertRedirect();
    expect($hole->fresh()->mass_watched_at)->not->toBeNull()
        ->and($hole->fresh()->mass_counting_since)->toBeNull();

    post(route('map-connections.watched', $hole), ['counting' => true])->assertRedirect();
    expect($hole->fresh()->mass_counting_since)->not->toBeNull();

    post(route('map-connections.watched', $hole), ['counting' => false])->assertRedirect();
    expect($hole->fresh()->mass_counting_since)->toBeNull()
        ->and($hole->fresh()->mass_watched_at)->not->toBeNull();

    post(route('map-connections.watched', $hole), ['counting' => true])->assertRedirect();
    post(route('map-connections.watched', $hole), ['on' => false])->assertRedirect();
    expect($hole->fresh()->mass_watched_at)->toBeNull()
        ->and($hole->fresh()->mass_counting_since)->toBeNull();
});

it('marks a hole brand new without watching it', function () {
    $map = Map::factory()->create();
    $hole = p40Hole($map);
    $hole->forceFill(['mass_status' => MassStatus::Reduced])->save();
    actingAs(p40Member($map));

    post(route('map-connections.fresh', $hole))->assertRedirect();

    expect($hole->fresh()->mass_status)->toBe(MassStatus::Fresh)
        ->and($hole->fresh()->mass_watched_at)->toBeNull()
        ->and($hole->fresh()->checked_note)->toBe('marked brand new');
});

it('shows the map with a watched hole (was a 500)', function () {
    $map = Map::factory()->create();
    $hole = p40Hole($map);
    $hole->forceFill(['mass_watched_at' => now(), 'mass_watched_by_name' => 'WatchPilot', 'mass_counting_since' => now()])->save();
    actingAs(p40Member($map));

    get(route('maps.show', $map))->assertOk();
});
