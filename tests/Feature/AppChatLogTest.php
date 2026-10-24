<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * Patch 54: the desktop app reports which of your EVE clients write chat logs ("Log Chat to File").
 */
it('records which of your characters have Log Chat to File on or off', function () {
    $user = User::factory()->create();
    $on = Character::factory()->create(['user_id' => $user->id, 'name' => 'Tsawind']);
    $off = Character::factory()->create(['user_id' => $user->id, 'name' => 'LuigiSquirrel']);
    $notSeen = Character::factory()->create(['user_id' => $user->id, 'name' => 'Darkness Holder']);
    actingAs($user);

    postJson(route('app.chat-logs.store'), ['characters' => [
        ['name' => 'Tsawind', 'logging' => true],
        ['name' => 'luigisquirrel', 'logging' => false],
    ]])->assertSuccessful()->assertJson(['updated' => 2]);

    expect($on->refresh()->chat_log_to_file)->toBeTrue()
        ->and($off->refresh()->chat_log_to_file)->toBeFalse()
        ->and($off->chat_log_checked_at)->not->toBeNull()
        ->and($notSeen->refresh()->chat_log_to_file)->toBeNull();
});

it('turns the warning off once the character logs chat again', function () {
    $user = User::factory()->create();
    $character = Character::factory()->create(['user_id' => $user->id, 'name' => 'LuigiSquirrel', 'chat_log_to_file' => false]);
    actingAs($user);

    postJson(route('app.chat-logs.store'), ['characters' => [['name' => 'LuigiSquirrel', 'logging' => true]]])->assertSuccessful();

    expect($character->refresh()->chat_log_to_file)->toBeTrue();
});

it('never touches someone else\'s character', function () {
    $theirs = Character::factory()->create(['name' => 'Someone Else']);
    actingAs(User::factory()->create());

    postJson(route('app.chat-logs.store'), ['characters' => [['name' => 'Someone Else', 'logging' => false]]])
        ->assertSuccessful()
        ->assertJson(['updated' => 0]);

    expect($theirs->refresh()->chat_log_to_file)->toBeNull();
});

it('needs a login and a well-formed list', function () {
    postJson(route('app.chat-logs.store'), ['characters' => []])->assertUnauthorized();

    actingAs(User::factory()->create());
    postJson(route('app.chat-logs.store'), ['characters' => [['name' => 'X']]])->assertJsonValidationErrors('characters.0.logging');
    postJson(route('app.chat-logs.store'), [])->assertJsonValidationErrors('characters');
});
