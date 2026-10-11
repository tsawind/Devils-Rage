<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\User;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

/** Patch 37: Add note… appends a stamped note, never overwriting. */
it('patch 37: adds a note at the end of the system notes, stamped with EVE time and the main', function () {
    $map = Map::factory()->create();
    $user = User::factory()->has(Character::factory(['name' => 'WickedWrench', 'seat_main_name' => 'Tsawind'])->has(MapAccess::factory(['permission' => Permission::Member])->for($map)))->create();
    $user->update(['preferred_character_id' => $user->characters()->value('id')]);
    actingAs($user->fresh());
    $system = placeMapSolarsystem($map, 31000401);
    $system->loadMissing('details');
    $system->details->update(['notes' => 'Old note stays']);
    $this->travelTo(CarbonImmutable::parse('2026-10-11 15:18:00', 'UTC'));

    post(route('map-solarsystems.notes.store', $system), ['text' => 'Tengu + 2 Lokis on scan'])->assertRedirect();

    expect($system->fresh()->details->notes)->toBe("Old note stays\n\n**2026-10-11 15:18 EVE · Tsawind:** Tengu + 2 Lokis on scan");
});
