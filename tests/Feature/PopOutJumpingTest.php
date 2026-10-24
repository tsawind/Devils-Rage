<?php

declare(strict_types=1);

use App\Models\Map;
use App\Models\MapUserSetting;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\put;

/**
 * Patch 53: each pop-out has its own JUMPING HOT / COLD / 🧵 for its character; the main switch
 * resets them all.
 */
it('keeps a pop-out\'s switch for its character only, and the main switch resets it', function () {
    $map = Map::factory()->create();
    $user = User::factory()->ownsMap($map)->create();
    $user->update(['preferred_character_id' => $user->characters->first()->id]);
    actingAs($user);

    put(route('maps.user-settings.update', $map), ['jumping_hot' => false])->assertRedirect();
    put(route('maps.user-settings.update', $map), ['jumping_for' => ['character_id' => 77, 'hot' => true]])->assertRedirect();
    put(route('maps.user-settings.update', $map), ['jumping_for' => ['character_id' => 77, 'thread' => true]])->assertRedirect();

    $settings = MapUserSetting::query()->where('user_id', $user->id)->where('map_id', $map->id)->sole();
    expect($settings->jumpingFor(77))->toBe(['hot' => true, 'thread' => true])
        ->and($settings->jumpingFor(88))->toBe(['hot' => false, 'thread' => false]);

    put(route('maps.user-settings.update', $map), ['jumping_hot' => false])->assertRedirect();

    expect($settings->refresh()->jumpingFor(77))->toBe(['hot' => false, 'thread' => false])
        ->and($settings->jumping_by_character)->toBeNull();
});
