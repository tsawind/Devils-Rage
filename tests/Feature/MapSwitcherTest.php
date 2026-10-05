<?php

declare(strict_types=1);

use App\Models\Map;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

it('patch 26: the map switcher lists only the maps you can open, loaded when asked for', function (): void {
    $daisy = Map::factory()->create(['name' => 'Daisy']);
    $public = Map::factory()->create(['name' => "Devil's Public"]);
    Map::factory()->create(['name' => 'Someone else']);
    $user = User::factory()->ownsMap($daisy)->ownsMap($public)->create();

    actingAs($user)
        ->get(route('maps.show', $daisy))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('maps/ShowMap')
            ->missing('available_maps')
            ->reloadOnly('available_maps', fn (Assert $reload) => $reload
                ->has('available_maps', 2)
                ->where('available_maps.0.name', 'Daisy')
                ->where('available_maps.1.name', "Devil's Public")
            )
        );
});
