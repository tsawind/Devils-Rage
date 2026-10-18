<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\put;

/**
 * Patch 41: a character's colour, set by its owner, seen by everyone.
 */
it('lets the owner pick a colour and clear it', function () {
    $user = User::factory()->create();
    $character = Character::factory()->create(['user_id' => $user->id]);
    actingAs($user);

    put(route('characters.color.update', $character), ['color' => '#22C55E'])->assertRedirect();
    expect($character->fresh()->map_color)->toBe('#22c55e');

    put(route('characters.color.update', $character), ['color' => null])->assertRedirect();
    expect($character->fresh()->map_color)->toBeNull();
});

it('refuses someone else\'s character and bad colours', function () {
    $user = User::factory()->create();
    $mine = Character::factory()->create(['user_id' => $user->id]);
    $theirs = Character::factory()->create();
    actingAs($user);

    put(route('characters.color.update', $theirs), ['color' => '#22c55e'])->assertForbidden();
    put(route('characters.color.update', $mine), ['color' => 'green'])->assertSessionHasErrors('color');
});
