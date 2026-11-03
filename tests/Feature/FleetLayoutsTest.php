<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\EsiScope;
use App\Models\EsiToken;
use App\Models\FleetLayout;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use NicolasKion\Esi\DTO\EsiError;
use NicolasKion\Esi\DTO\EsiResult;
use NicolasKion\Esi\DTO\FleetInfo;
use NicolasKion\Esi\DTO\FleetMember;
use NicolasKion\Esi\DTO\FleetSquad;
use NicolasKion\Esi\DTO\FleetWing;
use NicolasKion\Esi\DTO\UniverseId;
use NicolasKion\Esi\DTO\UniverseIds;
use NicolasKion\Esi\Enums\EsiScope as EsiScopeName;
use NicolasKion\Esi\Esi;

use function Pest\Laravel\actingAs;

/**
 * Patch 67: fleet layouts, and building one in the fleet you're boss of.
 */
function fleetBoss(): array
{
    $user = User::factory()->create();
    $boss = Character::factory()->create(['user_id' => $user->id, 'name' => 'Tsawind']);
    $token = EsiToken::query()->create([
        'character_id' => $boss->id,
        'character_owner_hash' => 'hash',
        'access_token' => 'access',
        'refresh_token' => 'refresh',
        'expires_at' => now()->addMinutes(20),
    ]);
    foreach ([EsiScopeName::ReadFleet, EsiScopeName::WriteFleet] as $scope) {
        $token->esiScopes()->attach(EsiScope::query()->firstOrCreate(['name' => $scope->value])->id);
    }
    $user->forceFill(['preferred_character_id' => $boss->id])->save();

    return [$user->refresh(), $boss];
}

/** @return array<string, mixed> */
function fleetBody(array $members = [['id' => 9001, 'name' => 'Girl Shady']], ?array $commander = ['id' => 9002, 'name' => 'Darkness Holder']): array
{
    return [
        'name' => 'Roll fleet',
        'shared' => true,
        'layout' => ['wings' => [[
            'name' => 'Rollers',
            'commander' => $commander,
            'squads' => [['name' => 'Squad A', 'commander' => null, 'members' => $members]],
        ]]],
    ];
}

it('shows your layouts and the corp\'s shared ones, not other people\'s private ones', function () {
    [$user] = fleetBoss();
    FleetLayout::factory()->create(['user_id' => $user->id, 'name' => 'Mine']);
    FleetLayout::factory()->shared()->create(['name' => 'Corp roll']);
    FleetLayout::factory()->create(['name' => 'Someone else private']);

    actingAs($user)
        ->get(route('fleets.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fleets/Layouts')
            ->has('layouts', 2)
            ->where('layouts.0.name', 'Corp roll')
            ->where('characters.0.can_boss', true)
            ->etc());
});

it('saves a layout and lets only its maker change or delete it', function () {
    [$user] = fleetBoss();
    $id = actingAs($user)->postJson(route('fleet-layouts.store'), fleetBody())->assertSuccessful()->json('id');
    $layout = FleetLayout::query()->findOrFail($id);
    expect($layout->shared)->toBeTrue()->and($layout->characterIds())->toBe([9002, 9001]);

    $other = User::factory()->create();
    actingAs($other)->putJson(route('fleet-layouts.update', $layout), fleetBody())->assertForbidden();
    actingAs($other)->deleteJson(route('fleet-layouts.destroy', $layout))->assertForbidden();
    actingAs($user)->deleteJson(route('fleet-layouts.destroy', $layout))->assertSuccessful();
    expect(FleetLayout::query()->count())->toBe(0);
});

it('rejects layouts EVE would not take', function (array $change, string $field) {
    [$user] = fleetBoss();
    $body = array_replace_recursive(fleetBody(), $change);
    actingAs($user)->postJson(route('fleet-layouts.store'), $body)->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'wing name too long' => [['layout' => ['wings' => [['name' => 'Far too long a name']]]], 'layout.wings.0.name'],
    'no name' => [['name' => ''], 'name'],
    'member without id' => [['layout' => ['wings' => [['squads' => [['members' => [['id' => null, 'name' => 'X']]]]]]]], 'layout.wings.0.squads.0.members.0.id'],
]);

it('builds the wings and squads and invites everyone to their place', function () {
    [$user, $boss] = fleetBoss();
    $layout = FleetLayout::factory()->create(['user_id' => $user->id] + fleetBody([['id' => 9001, 'name' => 'Girl Shady'], ['id' => (int) $boss->id, 'name' => 'Tsawind']]));

    $esi = $this->mock(Esi::class);
    $esi->shouldReceive('getCharacterFleet')->andReturn(new EsiResult(data: new FleetInfo(fleet_id: 77, fleet_boss_id: (int) $boss->id, role: 'fleet_commander', squad_id: -1, wing_id: -1)));
    $esi->shouldReceive('getFleetWings')->andReturn(
        new EsiResult(data: []),
        new EsiResult(data: [new FleetWing(id: 501, name: 'Wing 1', squads: [new FleetSquad(id: 601, name: 'Squad 1')])]),
    );
    $esi->shouldReceive('createFleetWing')->once()->withArgs(fn ($character, $fleet) => $fleet === 77)->andReturn(new EsiResult(data: 501));
    $esi->shouldReceive('renameFleetWing')->once()->withArgs(fn ($c, $fleet, $wing, $name) => $wing === 501 && $name === 'Rollers')->andReturn(new EsiResult(data: null));
    $esi->shouldReceive('renameFleetSquad')->once()->withArgs(fn ($c, $fleet, $squad, $name) => $squad === 601 && $name === 'Squad A')->andReturn(new EsiResult(data: null));
    $esi->shouldNotReceive('createFleetSquad');
    $esi->shouldReceive('getFleetMembers')->andReturn(new EsiResult(data: [
        new FleetMember(character_id: 9002, ship_type_id: 1, solar_system_id: 1, squad_id: 601, wing_id: 501, role: 'squad_member', role_name: 'Squad Member', join_time: '2026-11-03T00:00:00Z', station_id: null, takes_fleet_warp: true),
    ]));
    $esi->shouldReceive('moveFleetMember')->once()->withArgs(fn ($c, $fleet, $member, $role, $squad, $wing) => $member === 9002 && $role === 'wing_commander' && $wing === 501 && $squad === null)->andReturn(new EsiResult(data: null));
    $esi->shouldReceive('inviteFleetMember')->once()->withArgs(fn ($c, $fleet, $member, $role, $squad, $wing) => $member === 9001 && $role === 'squad_member' && $squad === 601 && $wing === 501)->andReturn(new EsiResult(data: null));

    $result = actingAs($user)->postJson(route('fleet-layouts.build', $layout))->assertSuccessful()->json();

    expect($result['ok'])->toBeTrue()
        ->and(collect($result['results'])->pluck('outcome', 'name')->all())->toBe(['Darkness Holder' => 'moved', 'Girl Shady' => 'invited', 'Tsawind' => 'boss']);
});

it('says why an invite failed', function () {
    [$user, $boss] = fleetBoss();
    $layout = FleetLayout::factory()->create(['user_id' => $user->id] + fleetBody(commander: null));

    $esi = $this->mock(Esi::class);
    $esi->shouldReceive('getCharacterFleet')->andReturn(new EsiResult(data: new FleetInfo(fleet_id: 77, fleet_boss_id: (int) $boss->id, role: 'fleet_commander', squad_id: -1, wing_id: -1)));
    $esi->shouldReceive('getFleetWings')->andReturn(new EsiResult(data: [new FleetWing(id: 501, name: 'Rollers', squads: [new FleetSquad(id: 601, name: 'Squad A')])]));
    $esi->shouldNotReceive('renameFleetWing');
    $esi->shouldNotReceive('renameFleetSquad');
    $esi->shouldReceive('getFleetMembers')->andReturn(new EsiResult(data: []));
    $esi->shouldReceive('inviteFleetMember')->andReturn(EsiResult::fromError(new EsiError(422, '{"error":"Character is not online"}')));

    $result = actingAs($user)->postJson(route('fleet-layouts.build', $layout))->assertSuccessful()->json();

    expect($result['results'][0]['outcome'])->toBe('failed')->and($result['results'][0]['detail'])->toBe('Not logged in right now.');
});

it('asks for a fleet first when none of your characters is boss', function () {
    [$user] = fleetBoss();
    $layout = FleetLayout::factory()->create(['user_id' => $user->id]);
    $esi = $this->mock(Esi::class);
    $esi->shouldReceive('getCharacterFleet')->andReturn(EsiResult::fromError(new EsiError(404, '{"error":"Character is not in a fleet"}')));
    $esi->shouldNotReceive('inviteFleetMember');

    actingAs($user)->postJson(route('fleet-layouts.build', $layout))->assertSuccessful()->assertJson(['ok' => false]);
});

it('lets anyone build a shared layout but not someone else\'s private one', function () {
    [$user] = fleetBoss();
    $private = FleetLayout::factory()->create();
    actingAs($user)->postJson(route('fleet-layouts.build', $private))->assertForbidden();
});

it('finds pasted names in the mapper first, then in EVE', function () {
    [$user] = fleetBoss();
    Character::factory()->create(['name' => 'Girl Shady']);
    $esi = $this->mock(Esi::class);
    $esi->shouldReceive('getIds')->once()->with(['Somebody New', 'Nobody'])->andReturn(new EsiResult(data: new UniverseIds(
        agents: [], alliances: [], characters: [new UniverseId(id: 4242, name: 'Somebody New')], constellations: [], corporations: [], factions: [], inventory_types: [], regions: [], stations: [], systems: [],
    )));

    $result = actingAs($user)->postJson(route('fleet-layouts.resolve'), ['names' => ['Girl Shady', 'Somebody New', 'Nobody']])->assertSuccessful()->json();

    expect(collect($result['characters'])->pluck('name')->all())->toBe(['Girl Shady', 'Somebody New'])
        ->and($result['not_found'])->toBe(['Nobody']);
});
