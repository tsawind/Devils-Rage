<?php

declare(strict_types=1);

use App\Enums\MassStatus;
use App\Enums\Permission;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapConnection;
use App\Models\MapConnectionJump;
use App\Models\MapShareLink;
use App\Models\User;
use App\Services\Seat\SeatMains;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/**
 * Patch 39 part 2: fresh + watching, hostile / friendly jumps, people's names and
 * Full-access share links.
 */
function p39Pilot(Map $map, string $name = 'WickedWrench', Permission $permission = Permission::Member): User
{
    $user = User::factory()
        ->has(Character::factory(['name' => $name])->has(MapAccess::factory(['permission' => $permission])->for($map)))
        ->create();
    $user->update(['preferred_character_id' => $user->characters()->value('id')]);

    return $user->fresh();
}

function p39Guest(string $name = 'Guest Pilot'): User
{
    $user = User::factory()->has(Character::factory(['name' => $name]))->create();
    $user->update(['preferred_character_id' => $user->characters()->value('id')]);

    return $user->fresh();
}

function p39Hole(Map $map): MapConnection
{
    $from = placeMapSolarsystem($map, 31000301);
    $to = placeMapSolarsystem($map, 31000302, 300, 300);

    return MapConnection::factory()->create([
        'map_id' => $map->id,
        'from_map_solarsystem_id' => $from->id,
        'to_map_solarsystem_id' => $to->id,
        'mass_status' => MassStatus::Reduced,
    ]);
}

it('marks a hole fresh and starts watching it, open or folded', function () {
    $map = Map::factory()->create();
    $hole = p39Hole($map);
    actingAs(p39Pilot($map));

    post(route('map-connections.fresh', $hole), ['watch' => true])->assertRedirect();

    $hole->refresh();
    expect($hole->mass_status)->toBe(MassStatus::Fresh)
        ->and($hole->mass_watched_at)->not->toBeNull()
        ->and($hole->mass_watch_open)->toBeTrue()
        ->and($hole->checked_note)->toBe('marked Fresh');

    post(route('map-connections.watched', $hole), ['open' => false])->assertRedirect();
    expect($hole->fresh()->mass_watch_open)->toBeFalse();
});

it('logs a hostile jump by class with an unknown direction, and its fit', function () {
    $map = Map::factory()->create();
    $hole = p39Hole($map);
    actingAs(p39Pilot($map));

    post(route('map-connection-jumps.store'), [
        'map_connection_id' => $hole->id,
        'direction' => 'unknown',
        'side' => 'hostile',
        'ship_class' => 'battleship',
        'fit' => ['higgs' => true, 'prop' => true, 'plate' => false],
        'mass' => 300_000_000,
    ])->assertRedirect();

    $jump = MapConnectionJump::query()->where('map_connection_id', $hole->id)->sole();
    expect($jump->side)->toBe('hostile')
        ->and($jump->ship_class)->toBe('battleship')
        ->and($jump->direction_known)->toBeFalse()
        ->and($jump->fit)->toEqual(['higgs' => true, 'prop' => true, 'plate' => false]);
});

it('refuses a ship class it does not know', function () {
    $map = Map::factory()->create();
    $hole = p39Hole($map);
    actingAs(p39Pilot($map));

    post(route('map-connection-jumps.store'), [
        'map_connection_id' => $hole->id,
        'direction' => 'unknown',
        'ship_class' => 'titan-ish',
        'mass' => 1,
    ])->assertSessionHasErrors('ship_class');
});

it('stores each character\'s person from the SeAT plugin', function () {
    config([
        'services.seat.url' => 'https://seat.test', 'services.seat.token' => 'secret',
        'services.seat_bot.url' => 'https://seat.test', 'services.seat_bot.key' => str_repeat('k', 32),
    ]);
    Cache::flush();
    $main = Character::factory()->create(['name' => 'Tsawind']);
    $alt = Character::factory()->create(['name' => 'LuigiSquirrel']);
    $gone = Character::factory()->create(['name' => 'Old Pilot', 'seat_person_name' => 'Old']);
    Http::fake([
        'seat.test/api/v2/users*' => Http::response(['data' => [['id' => 1, 'name' => 'Tsawind', 'main_character_id' => $main->id, 'associated_character_ids' => [$main->id, $alt->id]]], 'links' => ['next' => null]]),
        'seat.test/devilsrage/api/people' => Http::response(['people' => [['user_id' => 1, 'name' => 'Tsa', 'main' => 'Tsawind', 'character_ids' => [$main->id, $alt->id]]]]),
    ]);

    artisan('app:sync-seat-mains')->assertSuccessful();

    expect($alt->fresh()->seat_person_name)->toBe('Tsa')
        ->and($gone->fresh()->seat_person_name)->toBeNull()
        ->and(SeatMains::people())->toMatchArray(['Tsawind' => 'Tsa', 'LuigiSquirrel' => 'Tsa']);
});

it('keeps person names when the SeAT plugin does not answer', function () {
    config([
        'services.seat.url' => 'https://seat.test', 'services.seat.token' => 'secret',
        'services.seat_bot.url' => 'https://seat.test', 'services.seat_bot.key' => str_repeat('k', 32),
    ]);
    $alt = Character::factory()->create(['name' => 'LuigiSquirrel', 'seat_person_name' => 'Tsa']);
    Http::fake([
        'seat.test/api/v2/users*' => Http::response(['data' => [], 'links' => ['next' => null]]),
        'seat.test/devilsrage/api/people' => Http::response('down', 503),
    ]);

    artisan('app:sync-seat-mains')->assertSuccessful();

    expect($alt->fresh()->seat_person_name)->toBe('Tsa');
});

it('lets a guest pick a name nobody else goes by', function () {
    Character::factory()->create(['name' => 'Tsawind', 'seat_person_name' => 'Tsa']);
    $guest = p39Guest();
    actingAs($guest);

    put(route('guest-name.update'), ['name' => 'tsa'])->assertSessionHasErrors('name');
    put(route('guest-name.update'), ['name' => 'Bob'])->assertRedirect()->assertSessionHasNoErrors();

    expect($guest->fresh()->display_name)->toBe('Bob')
        ->and(SeatMains::people())->toMatchArray(['Guest Pilot' => 'Bob']);
});

it('only managers make Full-access links', function () {
    $map = Map::factory()->create();
    actingAs(p39Pilot($map));
    post(route('maps.share-links.store', $map), ['hours' => 3, 'access' => 'member'])->assertForbidden();

    actingAs(p39Pilot($map, 'Boss', Permission::Manager));
    post(route('maps.share-links.store', $map), ['hours' => 3, 'access' => 'member'])->assertRedirect();

    expect($map->shareLinks()->sole()->access)->toBe(MapShareLink::ACCESS_MEMBER);
});

it('a live Full-access link gives a logged-in guest member rights; a viewer link does not', function () {
    $map = Map::factory()->create();
    $member = $map->shareLinks()->create(['token' => 'full-token', 'kind' => 'map', 'access' => 'member', 'expires_at' => now()->addHours(3)]);
    $map->shareLinks()->create(['token' => 'view-token', 'kind' => 'map', 'access' => 'viewer', 'expires_at' => now()->addHours(3)]);
    $guest = p39Guest();

    session()->put("map_share_token_{$map->id}", 'view-token');
    expect($guest->can('update', $map))->toBeFalse();

    Illuminate\Support\Facades\Context::flush();
    session()->put("map_share_token_{$map->id}", 'full-token');
    expect($guest->can('update', $map))->toBeTrue()
        ->and($guest->can('updateSettings', $map))->toBeFalse();

    Illuminate\Support\Facades\Context::flush();
    $member->update(['expires_at' => now()->subMinute()]);
    expect($guest->can('update', $map))->toBeFalse();
});

it('sends someone opening a Full-access link to log in first', function () {
    $map = Map::factory()->create();
    $map->shareLinks()->create(['token' => 'full-token', 'kind' => 'map', 'access' => 'member', 'expires_at' => now()->addHours(3)]);

    $response = get(route('maps.share', ['token' => 'full-token']));

    expect($response->headers->get('Location'))->toEndWith('/login');
});
