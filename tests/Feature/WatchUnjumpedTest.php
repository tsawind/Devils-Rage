<?php

declare(strict_types=1);

use App\Enums\MassStatus;
use App\Enums\Permission;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\User;
use App\Models\MapConnection;
use App\Models\Signature;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

/**
 * Patch 45: a hole watched before anyone jumped it: its pipe is watched once it's jumped.
 */
function p45Member(Map $map): User
{
    $user = User::factory()
        ->has(Character::factory()->has(MapAccess::factory(['permission' => Permission::Member, 'is_owner' => true])->for($map)))
        ->create();
    $user->update(['preferred_character_id' => $user->characters()->value('id')]);

    return $user->fresh();
}

it('watches the pipe a watched hole becomes', function () {
    $map = Map::factory()->create();
    actingAs(p45Member($map));
    $from = placeMapSolarsystem($map, 31000601);
    $to = placeMapSolarsystem($map, 31000602, 300, 300);
    $signature = Signature::factory()->create(['map_solarsystem_id' => $from->id, 'map_connection_id' => null]);

    post(route('signatures.watched', $signature), ['on' => true])->assertRedirect();
    expect($signature->fresh()->mass_watched_at)->not->toBeNull();

    $connection = MapConnection::factory()->create([
        'map_id' => $map->id,
        'from_map_solarsystem_id' => $from->id,
        'to_map_solarsystem_id' => $to->id,
        'mass_status' => MassStatus::Fresh,
    ]);
    $signature->fresh()->update(['map_connection_id' => $connection->id]);

    expect($connection->fresh()->mass_watched_at)->not->toBeNull();
});

it('stops watching a hole', function () {
    $map = Map::factory()->create();
    actingAs(p45Member($map));
    $from = placeMapSolarsystem($map, 31000611);
    $signature = Signature::factory()->create(['map_solarsystem_id' => $from->id, 'map_connection_id' => null, 'mass_watched_at' => now()]);

    post(route('signatures.watched', $signature), ['on' => false])->assertRedirect();

    expect($signature->fresh()->mass_watched_at)->toBeNull();
});
