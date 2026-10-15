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

it('patch 37: ✓ Checked in game restarts the mass math like marking the status again', function () {
    $map = Map::factory()->create();
    $connection = holeConnection($map);
    $connection->forceFill(['mass_status_updated_at' => now()->subHour(), 'mass_status_from_log' => true])->save();
    actingAs(holeMember($map));

    post(route('map-connections.checked', $connection))->assertRedirect();

    $fresh = $connection->fresh();
    expect($fresh->mass_status_updated_at->greaterThan(now()->subMinute()))->toBeTrue()
        ->and($fresh->mass_status_from_log)->toBeFalse()
        ->and($fresh->mass_status_set_by_name)->toBe('WickedWrench');
});

it('patch 37: Add mass takes a negative amount off the log', function () {
    $map = Map::factory()->create();
    $connection = holeConnection($map);
    actingAs(holeMember($map));

    post(route('map-connection-jumps.store'), ['map_connection_id' => $connection->id, 'direction' => 'outbound', 'mass' => 300_000_000])->assertRedirect();
    post(route('map-connection-jumps.store'), ['map_connection_id' => $connection->id, 'direction' => 'outbound', 'mass' => -132_400_000])->assertRedirect();

    expect((int) App\Models\MapConnectionJump::query()->where('map_connection_id', $connection->id)->sum('mass'))->toBe(167_600_000);
});

it('patch 37: the jump log lists every jump with its HOT / SEMI / COLD / thread masses and keeps who changed one', function () {
    $map = Map::factory()->create();
    $connection = holeConnection($map);
    $character = App\Models\Character::factory()->create();
    App\Models\Category::query()->firstOrCreate(['id' => 6], ['name' => 'Ship']);
    App\Models\Group::query()->firstOrCreate(['id' => 547], ['name' => 'Carrier', 'category_id' => 6]);
    $ship = App\Models\Type::query()->create(['id' => 23757, 'name' => 'Archon', 'group_id' => 547, 'mass' => 1_340_000_000])->id;
    App\Models\ShipProfile::query()->create([
        'character_id' => $character->id, 'ship_type_id' => $ship, 'cold_mass' => 1_340_000_000, 'hot_mass' => 1_840_000_000,
        'higgs' => false, 'plates' => 0, 'entanglers' => 0, 'prop_mass' => 500_000_000, 'source' => 'manual',
        'options' => [
            ['key' => 'hot0', 'label' => 'HOT 50000MN MWD', 'mode' => 'hot', 'mass' => 1_840_000_000],
            ['key' => 'hot1', 'label' => 'HOT 10000MN AB', 'mode' => 'hot', 'mass' => 1_840_000_000],
            ['key' => 'hot2', 'label' => 'HOT 500MN MWD', 'mode' => 'hot', 'mass' => 1_390_000_000],
            ['key' => 'cold', 'label' => 'COLD', 'mode' => 'cold', 'mass' => 1_340_000_000],
        ],
    ]);
    $jump = App\Models\MapConnectionJump::query()->create([
        'map_id' => $map->id, 'map_connection_id' => $connection->id, 'character_id' => $character->id, 'ship_type_id' => $ship,
        'from_solarsystem_id' => $connection->fromMapSolarsystem->solarsystem_id, 'to_solarsystem_id' => $connection->toMapSolarsystem->solarsystem_id,
        'mass' => 1_340_000_000,
    ]);
    actingAs(holeMember($map));

    Pest\Laravel\getJson(route('map-connections.jumps.index', $connection))
        ->assertOk()
        ->assertJsonPath('jumps.0.outbound', true)
        ->assertJsonCount(3, 'jumps.0.options')
        ->assertJsonPath('jumps.0.options.0.label', 'HOT 50000/10000MN')
        ->assertJsonPath('jumps.0.options.1.kind', 'semi')
        ->assertJsonPath('jumps.0.options.1.label', 'SEMI 500MN')
        ->assertJsonPath('jumps.0.options.2.kind', 'cold');

    Pest\Laravel\put(route('map-connection-jumps.update', $jump), ['mass' => 1_390_000_000, 'kind' => 'semi'])->assertRedirect();

    $fresh = $jump->fresh();
    expect($fresh->mass)->toBe(1_390_000_000)
        ->and((bool) $fresh->getAttribute('is_hot'))->toBeTrue()
        ->and($fresh->getAttribute('edited_by_name'))->toBe('WickedWrench');
});

it('patch 37: anyone turns 👁 Watching on and off, with who turned it on', function () {
    $map = Map::factory()->create();
    $connection = holeConnection($map);
    actingAs(holeMember($map));

    post(route('map-connections.watched', $connection), ['on' => true])->assertRedirect();
    expect($connection->fresh()->mass_watched_at)->not->toBeNull()
        ->and($connection->fresh()->mass_watched_by_name)->toBe('WickedWrench');

    actingAs(holeMember($map, 'Someone Else'));
    post(route('map-connections.watched', $connection), ['on' => false])->assertRedirect();
    expect($connection->fresh()->mass_watched_at)->toBeNull()
        ->and($connection->fresh()->mass_watched_by_name)->toBeNull();
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
