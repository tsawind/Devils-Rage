<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\put;

/**
 * Patch 46: the desktop app's settings for everyone, set by app admins.
 */
function appAdmin(): User
{
    $user = User::factory()->create();
    $character = Character::factory()->create(['user_id' => $user->id]);
    config(['access.app_admin_character_ids' => [$character->id]]);

    return $user;
}

it('serves the app settings without a login, everything on by default', function () {
    getJson(route('app.config.show'))
        ->assertSuccessful()
        ->assertJson([
            'motd' => null,
            'min_version' => null,
            'features' => ['chat_log_location' => true, 'scanner_pop_out' => true],
        ]);
});

it('lets an app admin set the message, minimum version and features', function () {
    actingAs(appAdmin());

    put(route('app.admin.update'), [
        'motd' => 'Ops at 20:00',
        'min_version' => '0.1.4',
        'features' => ['chat_log_location' => false, 'scanner_pop_out' => true, 'made_up' => false],
    ])->assertSessionHasNoErrors()->assertRedirect();

    getJson(route('app.config.show'))->assertJson([
        'motd' => 'Ops at 20:00',
        'min_version' => '0.1.4',
        'features' => ['chat_log_location' => false, 'scanner_pop_out' => true],
    ])->assertJsonMissingPath('features.made_up');
});

it('opens the admin page for app admins only', function () {
    actingAs(appAdmin());
    get(route('app.admin.edit'))->assertSuccessful();

    actingAs(User::factory()->create());
    get(route('app.admin.edit'))->assertForbidden();
});

it('does not let other pilots change the app settings', function () {
    appAdmin();
    actingAs(User::factory()->create());

    put(route('app.admin.update'), ['motd' => 'Free ISK'])->assertForbidden();

    getJson(route('app.config.show'))->assertJson(['motd' => null]);
});

it('nobody is an app admin when none are configured', function () {
    config(['access.app_admin_character_ids' => []]);
    $user = User::factory()->create();
    Character::factory()->create(['user_id' => $user->id]);

    expect($user->isAppAdmin())->toBeFalse();
});

it('wants a plain version number', function () {
    actingAs(appAdmin());

    put(route('app.admin.update'), ['min_version' => 'latest'])->assertSessionHasErrors('min_version');
});
