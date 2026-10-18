<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\User;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;

/**
 * Patch 39: each browser window has its own main, sent as X-Map-Driver (or ?as=).
 */
beforeEach(function () {
    Route::middleware('web')->get('/_test/driver', fn () => response()->json([
        'active' => auth()->user()?->active_character?->id,
        'session' => session('active_character_id'),
    ]));
});

function driverUser(): array
{
    $user = User::factory()->create();
    $main = Character::factory()->create(['user_id' => $user->id]);
    $alt = Character::factory()->create(['user_id' => $user->id]);
    $user->update(['preferred_character_id' => $main->id]);

    return [$user->fresh(), $main, $alt];
}

it('acts as the window\'s main without changing the login\'s main', function () {
    [$user, $main, $alt] = driverUser();
    actingAs($user);

    get('/_test/driver')->assertJson(['active' => $main->id]);

    withHeaders(['X-Map-Driver' => (string) $alt->id])->getJson('/_test/driver')
        ->assertJson(['active' => $alt->id, 'session' => null]);
});

it('takes ?as= on a page load', function () {
    [$user, , $alt] = driverUser();
    actingAs($user);

    get('/_test/driver?as='.$alt->id)->assertJson(['active' => $alt->id]);
});

it('ignores a character that is not the user\'s', function () {
    [$user, $main] = driverUser();
    $stranger = Character::factory()->create();
    actingAs($user);

    withHeaders(['X-Map-Driver' => (string) $stranger->id])->getJson('/_test/driver')
        ->assertJson(['active' => $main->id]);
});

it('gives the pop-out its system and signatures, members only', function () {
    $map = App\Models\Map::factory()->create();
    $system = placeMapSolarsystem($map, 31000401);
    App\Models\Signature::factory()->create(['map_solarsystem_id' => $system->id, 'signature_id' => 'POP-401']);
    $member = User::factory()
        ->has(Character::factory()->has(App\Models\MapAccess::factory(['permission' => App\Enums\Permission::Member])->for($map)))
        ->create();

    actingAs($member)->getJson(route('map-solarsystems.pop-out', $system))
        ->assertOk()
        ->assertJsonPath('data.id', $system->id)
        ->assertJsonPath('data.signatures.0.signature_id', 'POP-401');

    actingAs(User::factory()->has(Character::factory())->create())
        ->getJson(route('map-solarsystems.pop-out', $system))
        ->assertForbidden();
});
