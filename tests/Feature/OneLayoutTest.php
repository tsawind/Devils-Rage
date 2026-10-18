<?php

declare(strict_types=1);

use App\Models\Map;
use App\Models\MapUserSetting;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\put;

/**
 * Patch 41c: one card layout per person, the same on every map tab.
 */
it('uses the layout you saved last on every map', function () {
    $daisy = Map::factory()->create();
    $public = Map::factory()->create();
    $user = User::factory()->ownsMap($daisy)->ownsMap($public)->create();
    $user->update(['preferred_character_id' => $user->characters->first()->id]);
    actingAs($user);

    $layout = ['lg' => ['cols' => 12, 'items' => [['i' => 'map', 'x' => 0, 'y' => 0, 'w' => 8, 'h' => 10]]]];
    put(route('maps.user-settings.update', $daisy), ['layout_breakpoints' => $layout, 'hidden_cards' => ['audits']])->assertRedirect();

    get(route('maps.show', $public))->assertOk();

    $other = MapUserSetting::query()->where('user_id', $user->id)->where('map_id', $public->id)->sole();
    expect($other->layout_breakpoints)->toEqual($layout)
        ->and($other->hidden_cards)->toBe(['audits']);

    $changed = ['lg' => ['cols' => 12, 'items' => [['i' => 'map', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 10]]]];
    put(route('maps.user-settings.update', $public), ['layout_breakpoints' => $changed])->assertRedirect();
    expect(MapUserSetting::query()->where('user_id', $user->id)->where('map_id', $daisy->id)->sole()->layout_breakpoints)->toEqual($changed);
});
