<?php

declare(strict_types=1);

use App\Jobs\Characters\UpdateCharacterLocation;
use App\Models\Category;
use App\Models\Character;
use App\Models\CharacterStatus;
use App\Models\Group;
use App\Models\Type;
use App\Models\User;
use NicolasKion\Esi\DTO\EsiResult;
use NicolasKion\Esi\DTO\Location;
use NicolasKion\Esi\DTO\Ship;
use NicolasKion\Esi\Esi;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * Patch 46: the desktop app reports a jump it read in EVE's Local chat log.
 *
 * @return array{user: User, character: Character, status: CharacterStatus}
 */
function appLocationSetup(int $from = 31000401): array
{
    makeSolarsystem($from);
    Category::query()->firstOrCreate(['id' => 6], ['name' => 'Ship']);
    Group::query()->firstOrCreate(['id' => 25], ['name' => 'Frigate', 'category_id' => 6]);
    Type::query()->firstOrCreate(['id' => 587], ['name' => 'Rifter', 'group_id' => 25, 'mass' => 1_067_000]);
    $user = User::factory()->create();
    $character = Character::factory()->create(['user_id' => $user->id]);
    $status = CharacterStatus::query()->create([
        'character_id' => $character->id,
        'solarsystem_id' => $from,
        'is_online' => true,
    ]);

    return ['user' => $user, 'character' => $character, 'status' => $status];
}

it('moves your character to the system the app read in the chat log', function () {
    ['user' => $user, 'character' => $character, 'status' => $status] = appLocationSetup();
    makeSolarsystem(31000402);
    actingAs($user);

    postJson(route('app.location.store'), ['character_id' => $character->id, 'solarsystem' => 'Test System 31000402'])
        ->assertSuccessful()
        ->assertJson(['moved' => true]);

    $status->refresh();
    expect($status->solarsystem_id)->toBe(31000402)
        ->and($status->app_location_at)->not->toBeNull()
        ->and($status->event_queued_at)->not->toBeNull();
});

it('leaves a character where it is when the app reports the same system', function () {
    ['user' => $user, 'character' => $character, 'status' => $status] = appLocationSetup();
    actingAs($user);

    postJson(route('app.location.store'), ['character_id' => $character->id, 'solarsystem' => 'Test System 31000401'])
        ->assertSuccessful()
        ->assertJson(['moved' => false]);

    expect($status->refresh()->solarsystem_id)->toBe(31000401);
});

it('does not move someone else\'s character', function () {
    ['status' => $status] = appLocationSetup();
    makeSolarsystem(31000402);
    actingAs(User::factory()->create());

    postJson(route('app.location.store'), ['character_id' => $status->character_id, 'solarsystem' => 'Test System 31000402'])
        ->assertJsonValidationErrors('character_id');

    expect($status->refresh()->solarsystem_id)->toBe(31000401);
});

it('refuses a system name that does not exist', function () {
    ['user' => $user, 'character' => $character] = appLocationSetup();
    actingAs($user);

    postJson(route('app.location.store'), ['character_id' => $character->id, 'solarsystem' => 'Nowhere'])
        ->assertJsonValidationErrors('solarsystem');
});

it('does not let a slower ESI answer move the character back right after an app report', function () {
    ['user' => $user, 'character' => $character, 'status' => $status] = appLocationSetup();
    makeSolarsystem(31000402);
    actingAs($user);
    postJson(route('app.location.store'), ['character_id' => $character->id, 'solarsystem' => 'Test System 31000402'])->assertSuccessful();

    $esi = test()->mock(Esi::class);
    $esi->shouldReceive('getLocation')->andReturn(new EsiResult(data: new Location(solar_system_id: 31000401)));
    $esi->shouldReceive('getShip')->andReturn(new EsiResult(data: new Ship(ship_type_id: 587, ship_item_id: 9001, ship_name: 'Rifter')));

    UpdateCharacterLocation::dispatchSync($status->id);

    expect($status->refresh()->solarsystem_id)->toBe(31000402);
});

it('lets ESI correct the system once the app report is old', function () {
    ['user' => $user, 'character' => $character, 'status' => $status] = appLocationSetup();
    makeSolarsystem(31000402);
    actingAs($user);
    postJson(route('app.location.store'), ['character_id' => $character->id, 'solarsystem' => 'Test System 31000402'])->assertSuccessful();
    $status->refresh()->update(['app_location_at' => now()->subMinute()]);

    $esi = test()->mock(Esi::class);
    $esi->shouldReceive('getLocation')->andReturn(new EsiResult(data: new Location(solar_system_id: 31000401)));
    $esi->shouldReceive('getShip')->andReturn(new EsiResult(data: new Ship(ship_type_id: 587, ship_item_id: 9001, ship_name: 'Rifter')));

    UpdateCharacterLocation::dispatchSync($status->id);

    expect($status->refresh()->solarsystem_id)->toBe(31000401);
});
