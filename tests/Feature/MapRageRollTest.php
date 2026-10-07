<?php

declare(strict_types=1);

use App\Actions\MapRageRoll\NewStaticAction;
use App\Enums\Permission;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapConnection;
use App\Models\MapRallyPing;
use App\Models\MapSolarsystem;
use App\Models\MapUserSetting;
use App\Models\MapWebhook;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

function rageRoller(Map $map, Permission $permission): User
{
    return User::factory()
        ->has(Character::factory()->has(MapAccess::factory(['permission' => $permission])->for($map)))
        ->create();
}

beforeEach(function () {
    Cache::flush();
    Http::fake(['discord.com/*' => Http::response(null, 204)]);
});

it('patch 35: a member starts a rage roll with an @here ping, targets and a rage scanning session', function () {
    $map = Map::factory()->create();
    $daisy = makeSolarsystem(31000005);
    $target = makeSolarsystem(31000006);
    $webhook = MapWebhook::factory()->for($map)->create();
    actingAs(rageRoller($map, Permission::Member));

    $this->post(route('maps.rage-roll.store', $map), [
        'solarsystem_id' => $daisy,
        'map_webhook_id' => $webhook->id,
        'mention' => 'here',
        'static_text' => 'V753 → C6 (Alpha, ZGB)',
        'kspace_text' => 'Bravo → Tama (lowsec), 2 jumps',
        'note' => 'need a HIC',
        'targets' => ['Test System 31000006'],
        'scanning' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    Http::assertSent(function (Request $request): bool {
        $embed = $request['embeds'][0];
        $names = array_column($embed['fields'], 'name');

        return $request['content'] === '@here'
            && str_starts_with($embed['title'], '⚡ RAGE ROLL')
            && str_ends_with($embed['title'], '+ RAGE SCANNING')
            && in_array('⚔ Rage Scanning session', $names, true)
            && in_array('Static', $names, true)
            && in_array('Nearest k-space', $names, true)
            && in_array('Rolling for', $names, true)
            && in_array('Note', $names, true);
    });

    $map->refresh();
    expect($map->rage_roll_solarsystem_id)->toBe($daisy)
        ->and($map->rage_roll_targets)->toBe([$target])
        ->and($map->rage_roll_scanning)->toBeTrue()
        ->and($map->rageRollState()['targets'][0]['name'])->toBe('Test System 31000006')
        ->and(MapRallyPing::query()->where('kind', 'rage_roll')->count())->toBe(1);
});

it('patch 35: starting without a channel sends no ping', function () {
    $map = Map::factory()->create();
    actingAs(rageRoller($map, Permission::Member));

    $this->post(route('maps.rage-roll.store', $map), ['solarsystem_id' => makeSolarsystem(31000005)])
        ->assertRedirect()->assertSessionHasNoErrors();

    Http::assertNothingSent();
    expect($map->refresh()->rage_roll_solarsystem_id)->toBe(31000005);
});

it('patch 35: one rage roll ping every two minutes', function () {
    $map = Map::factory()->create();
    $webhook = MapWebhook::factory()->for($map)->create();
    actingAs(rageRoller($map, Permission::Member));
    $data = ['solarsystem_id' => makeSolarsystem(31000005), 'map_webhook_id' => $webhook->id, 'mention' => 'none'];

    $this->post(route('maps.rage-roll.store', $map), $data)->assertSessionHasNoErrors();
    $this->post(route('maps.rage-roll.store', $map), $data)->assertSessionHasErrors('map_webhook_id');
});

it('patch 35: unknown targets are refused', function () {
    $map = Map::factory()->create();
    actingAs(rageRoller($map, Permission::Member));

    $this->post(route('maps.rage-roll.store', $map), [
        'solarsystem_id' => makeSolarsystem(31000005),
        'targets' => ['J999999'],
    ])->assertSessionHasErrors('targets');

    expect($map->refresh()->rage_roll_solarsystem_id)->toBeNull();
});

it('patch 35: viewers cannot start a rage roll', function () {
    $map = Map::factory()->create();
    actingAs(rageRoller($map, Permission::Viewer));

    $this->post(route('maps.rage-roll.store', $map), ['solarsystem_id' => makeSolarsystem(31000005)])->assertForbidden();
});

it('patch 35: stopping ends the roll and turns speed-only rage scanning off for everyone', function () {
    $map = Map::factory()->create();
    $map->update(['rage_roll_solarsystem_id' => makeSolarsystem(31000005), 'rage_roll_started_at' => now(), 'rage_roll_scanning' => true]);
    $roller = rageRoller($map, Permission::Member);
    $speedOnly = MapUserSetting::query()->create(['map_id' => $map->id, 'user_id' => User::factory()->create()->id, 'combat_mode' => true, 'combat_color' => null]);
    $chainWorker = MapUserSetting::query()->create(['map_id' => $map->id, 'user_id' => User::factory()->create()->id, 'combat_mode' => true, 'combat_color' => 'red']);
    actingAs($roller);

    $this->delete(route('maps.rage-roll.destroy', $map))->assertRedirect();

    $map->refresh();
    expect($map->rage_roll_solarsystem_id)->toBeNull()
        ->and($map->rage_roll_scanning)->toBeFalse()
        ->and($speedOnly->refresh()->combat_mode)->toBeFalse()
        ->and($chainWorker->refresh()->combat_mode)->toBeTrue();
});

it('patch 35: targets can be changed while rolling', function () {
    $map = Map::factory()->create();
    $map->update(['rage_roll_solarsystem_id' => makeSolarsystem(31000005), 'rage_roll_started_at' => now()]);
    $target = makeSolarsystem(31000007);
    actingAs(rageRoller($map, Permission::Member));

    $this->put(route('maps.rage-roll.targets', $map), ['targets' => ['Test System 31000007']])->assertSessionHasNoErrors();

    expect($map->refresh()->rage_roll_targets)->toBe([$target]);
});

it('patch 35: New Alpha stamps the old chain, frees the name and makes the new signature the static', function () {
    $map = Map::factory()->create();
    $daisy = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(31000005), 'alias' => 'Daisy']);
    $alpha = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(31000006), 'alias' => 'A', 'created_at' => '2026-10-07 19:58:12']);
    $a1 = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(31000007), 'alias' => 'A1']);
    $bravo = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(31000008), 'alias' => 'B']);
    $connection = MapConnection::factory()->create(['map_id' => $map->id, 'from_map_solarsystem_id' => $daisy->id, 'to_map_solarsystem_id' => $alpha->id]);
    $old = Signature::factory()->create(['map_solarsystem_id' => $daisy->id, 'signature_id' => 'ZGB-111', 'alias' => 'A', 'is_static' => true, 'map_connection_id' => $connection->id]);
    $locked = Signature::factory()->create(['map_solarsystem_id' => $a1->id, 'signature_id' => 'QRS-222', 'alias' => 'A12']);
    $new = Signature::factory()->create(['map_solarsystem_id' => $daisy->id, 'signature_id' => 'XYZ-333']);
    actingAs(rageRoller($map, Permission::Member));

    $this->post(route('maps.rage-roll.new-static', $map), ['old_signature_id' => $old->id, 'signature_id' => $new->id])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($alpha->refresh()->alias)->toBe('A@1958')
        ->and($a1->refresh()->alias)->toBe('A1@1958')
        ->and($bravo->refresh()->alias)->toBe('B')
        ->and($daisy->refresh()->alias)->toBe('Daisy')
        ->and($locked->refresh()->alias)->toBe('A12@1958')
        ->and(Signature::query()->find($old->id))->toBeNull()
        ->and(MapConnection::query()->find($connection->id))->toBeNull()
        ->and($new->refresh()->is_static)->toBeTrue()
        ->and($new->alias)->toBe('A');
});

it('patch 35: chain membership by name', function (string $alias, string $base, bool $expected) {
    expect(NewStaticAction::isInChain($alias, $base))->toBe($expected);
})->with([
    'the static itself' => ['A', 'A', true],
    'down the chain' => ['A101', 'A', true],
    'another of home\'s holes' => ['B', 'A', false],
    'home\'s own name' => ['Daisy', 'D', false],
    'already stamped' => ['A1@1958', 'A', false],
    'a side chain static' => ['Z01', 'Z0', true],
    'a sibling of the static' => ['Z1', 'Z0', false],
]);
