<?php

declare(strict_types=1);

use App\Enums\MassStatus;
use App\Enums\Permission;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapConnection;
use App\Models\Signature;
use App\Models\User;
use App\Services\Seat\SeatMains;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/**
 * Patch 37: the pipe details box: who added a hole, who set its type and status,
 * who last looked at it in game; and each character's main from SeAT.
 */
function holeMember(Map $map, string $name = 'WickedWrench'): User
{
    $user = User::factory()
        ->has(Character::factory(['name' => $name])->has(MapAccess::factory(['permission' => Permission::Member])->for($map)))
        ->create();
    $user->update(['preferred_character_id' => $user->characters()->value('id')]);

    return $user->fresh();
}

function holeConnection(Map $map): MapConnection
{
    $from = placeMapSolarsystem($map, 31000201);
    $to = placeMapSolarsystem($map, 31000202, 300, 300);

    return MapConnection::factory()->create([
        'map_id' => $map->id,
        'from_map_solarsystem_id' => $from->id,
        'to_map_solarsystem_id' => $to->id,
        'mass_status' => MassStatus::Fresh,
    ]);
}

it('patch 37: records who added a hole and who set its type', function () {
    $map = Map::factory()->create();
    actingAs(holeMember($map));
    $system = placeMapSolarsystem($map, 31000203);
    $wormhole = makeWormhole('H296');

    $signature = Signature::factory()->create(['map_solarsystem_id' => $system->id, 'wormhole_id' => null, 'signature_type_id' => null]);
    expect($signature->fresh()->created_by_name)->toBe('WickedWrench')
        ->and($signature->fresh()->type_set_by_name)->toBeNull();

    $signature->update(['wormhole_id' => $wormhole->id]);
    expect($signature->fresh()->type_set_by_name)->toBe('WickedWrench')
        ->and($signature->fresh()->type_set_at)->not->toBeNull();
});

it('patch 37: marking a mass status records who, and marking it again restarts the log', function () {
    $map = Map::factory()->create();
    $connection = holeConnection($map);
    actingAs(holeMember($map, 'Kyle'));

    put(route('map-connections.update', $connection), ['mass_status' => 'reduced'])->assertRedirect();
    $first = $connection->fresh();
    expect($first->mass_status_set_by_name)->toBe('Kyle')
        ->and($first->checked_by_name)->toBe('Kyle')
        ->and($first->checked_note)->toBe('marked Reduced');

    $this->travel(10)->minutes();
    put(route('map-connections.update', $connection), ['mass_status' => 'reduced'])->assertRedirect();
    expect($connection->fresh()->mass_status_updated_at->greaterThan($first->mass_status_updated_at))->toBeTrue();
});

it('patch 37: ✓ Checked in game stamps a jumped and an unjumped hole', function () {
    $map = Map::factory()->create();
    $connection = holeConnection($map);
    $signature = Signature::factory()->create(['map_solarsystem_id' => $connection->from_map_solarsystem_id, 'map_connection_id' => null]);
    actingAs(holeMember($map));

    post(route('map-connections.checked', $connection))->assertRedirect();
    post(route('signatures.checked', $signature))->assertRedirect();

    expect($connection->fresh()->checked_by_name)->toBe('WickedWrench')
        ->and($connection->fresh()->checked_note)->toBe('checked')
        ->and($signature->fresh()->checked_at)->not->toBeNull()
        ->and($signature->fresh()->checked_by_name)->toBe('WickedWrench');
});

it('patch 37: stores each character\'s main from SeAT', function () {
    config(['services.seat.url' => 'https://seat.test', 'services.seat.token' => 'secret']);
    Cache::flush();
    $main = Character::factory()->create(['name' => 'Tsawind']);
    $alt = Character::factory()->create(['name' => 'WickedWrench']);
    $stranger = Character::factory()->create(['name' => 'Girl Shady', 'seat_main_name' => 'Old Main']);
    Http::fake(['seat.test/api/v2/users*' => Http::response([
        'data' => [['id' => 1, 'name' => 'Tsawind', 'main_character_id' => $main->id, 'associated_character_ids' => [$main->id, $alt->id]]],
        'links' => ['next' => null],
    ])]);

    artisan('app:sync-seat-mains')->assertSuccessful();

    expect($alt->fresh()->seat_main_name)->toBe('Tsawind')
        ->and($main->fresh()->seat_main_name)->toBe('Tsawind')
        ->and($stranger->fresh()->seat_main_name)->toBeNull()
        ->and(SeatMains::enabled())->toBeTrue()
        ->and(SeatMains::byName())->toMatchArray(['WickedWrench' => 'Tsawind', 'Tsawind' => 'Tsawind']);
    Http::assertSent(fn ($request) => $request->hasHeader('X-Token', 'secret'));
});

it('patch 37: SeAT names stay off until SeAT is set up', function () {
    config(['services.seat.url' => null, 'services.seat.token' => null]);

    artisan('app:sync-seat-mains')->assertSuccessful();

    expect(SeatMains::enabled())->toBeFalse();
});
