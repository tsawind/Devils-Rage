<?php

declare(strict_types=1);

use App\Actions\MapSelection\DeleteMapSelectionAction;
use App\Actions\MapSolarsystem\DeleteMapSolarsystemAction;
use App\Actions\MapSolarsystem\HideMapSolarsystemsAction;
use App\Actions\MapSolarsystem\HideOldChainsAction;
use App\Actions\MapSolarsystem\StoreMapSolarsystemAction;
use App\Console\Commands\Signatures\DeleteOldSignaturesCommand;
use App\Models\Map;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Support\ChainMemory;
use Carbon\CarbonImmutable;

use function Pest\Laravel\artisan;

/**
 * Patch 36: chain memory. Clears hide systems; names get a "~hhmm" stamp; a
 * reconnect within 27 h brings a system back as it was.
 */
function chainMap(): array
{
    $daisy_id = makeSolarsystem(31000005, -1.0, 'wormhole');
    $map = Map::factory()->create(['home_solarsystem_id' => $daisy_id]);
    $daisy = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => $daisy_id, 'alias' => 'Daisy', 'pinned' => false]);
    $alpha = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(31000006, -1.0, 'wormhole'), 'alias' => 'A', 'pinned' => false, 'created_at' => '2026-10-07 19:58:12']);
    $a1 = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(31000007, -1.0, 'wormhole'), 'alias' => 'A1', 'pinned' => false, 'created_at' => '2026-10-07 20:30:00']);
    $daisy_alpha = MapConnection::factory()->create(['map_id' => $map->id, 'from_map_solarsystem_id' => $daisy->id, 'to_map_solarsystem_id' => $alpha->id]);
    $alpha_a1 = MapConnection::factory()->create(['map_id' => $map->id, 'from_map_solarsystem_id' => $alpha->id, 'to_map_solarsystem_id' => $a1->id]);

    return compact('map', 'daisy', 'alpha', 'a1', 'daisy_alpha', 'alpha_a1');
}

it('hides a cleared chain instead of deleting it, stamping its names', function () {
    ['map' => $map, 'daisy' => $daisy, 'alpha' => $alpha, 'a1' => $a1] = chainMap();
    $site = Signature::factory()->create(['map_solarsystem_id' => $a1->id, 'signature_id' => 'GAS-111', 'alias' => null, 'map_connection_id' => null]);
    $locked = Signature::factory()->create(['map_solarsystem_id' => $a1->id, 'signature_id' => 'QRS-222', 'alias' => 'A12', 'map_connection_id' => null]);

    app(DeleteMapSelectionAction::class)->handle([$alpha->id, $a1->id, $daisy->id]);

    expect(MapSolarsystem::query()->whereKey([$alpha->id, $a1->id])->count())->toBe(0)
        ->and(MapSolarsystem::withHidden()->find($alpha->id)->alias)->toBe('A~1958')
        ->and(MapSolarsystem::withHidden()->find($a1->id)->alias)->toBe('A1~1958')
        ->and(MapSolarsystem::withHidden()->find($a1->id)->hidden_at)->not->toBeNull()
        ->and($site->fresh())->not->toBeNull()
        ->and($locked->fresh()->alias)->toBe('A12~1958')
        ->and(MapConnection::query()->where('map_id', $map->id)->count())->toBe(0)
        // The home is never hidden; selected by hand it is still deleted as before.
        ->and(MapSolarsystem::withHidden()->find($daisy->id))->toBeNull();
});

it('never hides the home or pinned systems on an automatic hide', function () {
    ['map' => $map, 'daisy' => $daisy, 'alpha' => $alpha] = chainMap();
    $alpha->update(['pinned' => true]);

    $hidden = app(HideMapSolarsystemsAction::class)->handle($map, [$daisy->id, $alpha->id], automatic: true);

    expect($hidden)->toBe([])
        ->and(MapSolarsystem::query()->whereKey([$daisy->id, $alpha->id])->count())->toBe(2);
});

it('keeps rage homes and the rally point on an automatic hide', function () {
    ['map' => $map, 'alpha' => $alpha, 'a1' => $a1] = chainMap();
    $alpha->update(['combat_home' => true]);
    $map->update(['rally_solarsystem_id' => $a1->solarsystem_id]);

    expect(app(HideMapSolarsystemsAction::class)->handle($map->fresh(), [$alpha->id, $a1->id], automatic: true))->toBe([]);
});

it('a single delete hides the system', function () {
    ['alpha' => $alpha] = chainMap();

    expect(app(DeleteMapSolarsystemAction::class)->handle($alpha))->toBeTrue()
        ->and(MapSolarsystem::query()->find($alpha->id))->toBeNull()
        ->and(MapSolarsystem::withHidden()->find($alpha->id))->not->toBeNull();
});

it('brings a hidden system back as it was within 27 h, dropping the stamp when the name is free', function () {
    ['map' => $map, 'alpha' => $alpha, 'a1' => $a1] = chainMap();
    $note = Signature::factory()->create(['map_solarsystem_id' => $a1->id, 'signature_id' => 'REL-123', 'alias' => 'A11', 'map_connection_id' => null]);
    app(HideMapSolarsystemsAction::class)->handle($map, [$alpha->id, $a1->id]);

    $back = app(StoreMapSolarsystemAction::class)->handle($map, [
        'solarsystem_id' => $a1->solarsystem_id, 'position_x' => 100, 'position_y' => 100,
    ]);

    expect($back->id)->toBe($a1->id)
        ->and($back->fresh()->alias)->toBe('A1')
        ->and($back->fresh()->hidden_at)->toBeNull()
        ->and($note->fresh()->alias)->toBe('A11')
        // Patch 37: the rest of its old chain comes back with it.
        ->and(MapSolarsystem::query()->find($alpha->id))->not->toBeNull();
});

it('keeps the stamp when the plain name is taken again', function () {
    ['map' => $map, 'alpha' => $alpha] = chainMap();
    app(HideMapSolarsystemsAction::class)->handle($map, [$alpha->id]);
    MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(31000009, -1.0, 'wormhole'), 'alias' => 'A', 'pinned' => false]);

    $back = app(StoreMapSolarsystemAction::class)->handle($map, [
        'solarsystem_id' => $alpha->solarsystem_id, 'position_x' => 100, 'position_y' => 100,
    ]);

    expect($back->fresh()->alias)->toBe('A~1958');
});

it('comes back with no name after 27 h hidden', function () {
    ['map' => $map, 'alpha' => $alpha] = chainMap();
    app(HideMapSolarsystemsAction::class)->handle($map, [$alpha->id]);
    MapSolarsystem::withHidden()->whereKey($alpha->id)->update(['hidden_at' => CarbonImmutable::now()->subHours(28)]);

    $back = app(StoreMapSolarsystemAction::class)->handle($map, [
        'solarsystem_id' => $alpha->solarsystem_id, 'position_x' => 100, 'position_y' => 100,
    ]);

    expect($back->fresh()->alias)->toBeNull();
});

it('cleanup: wormhole signatures go at 27 h and a system left without pipes hides', function () {
    ['map' => $map, 'alpha' => $alpha, 'a1' => $a1, 'alpha_a1' => $alpha_a1] = chainMap();
    $hole = Signature::factory()->create([
        'map_solarsystem_id' => $alpha->id, 'signature_id' => 'ABC-123', 'map_connection_id' => $alpha_a1->id,
        'signature_category_id' => wormholeCategoryId(), 'created_at' => CarbonImmutable::now()->subHours(28),
    ]);
    $young = Signature::factory()->create([
        'map_solarsystem_id' => $alpha->id, 'signature_id' => 'DEF-456', 'map_connection_id' => null,
        'signature_category_id' => wormholeCategoryId(), 'created_at' => CarbonImmutable::now()->subHours(26),
    ]);
    $site = Signature::factory()->create([
        'map_solarsystem_id' => $a1->id, 'signature_id' => 'GHI-789', 'map_connection_id' => null,
        'signature_category_id' => null, 'created_at' => CarbonImmutable::now()->subDays(10),
    ]);

    artisan(DeleteOldSignaturesCommand::class)->assertSuccessful();

    expect($hole->fresh())->toBeNull()
        ->and($young->fresh())->not->toBeNull()
        ->and($site->fresh())->not->toBeNull()
        ->and(MapSolarsystem::query()->find($a1->id))->toBeNull()
        ->and(MapSolarsystem::withHidden()->find($a1->id)->alias)->toBe('A1~1958')
        // Alpha still hangs off Daisy.
        ->and(MapSolarsystem::query()->find($alpha->id))->not->toBeNull();
});

it('cleanup: other signatures are kept 30 days, names go after 27 h, empty hidden systems are deleted', function () {
    ['map' => $map, 'alpha' => $alpha, 'a1' => $a1] = chainMap();
    app(HideMapSolarsystemsAction::class)->handle($map, [$alpha->id, $a1->id]);
    MapSolarsystem::withHidden()->whereKey([$alpha->id, $a1->id])->update(['hidden_at' => CarbonImmutable::now()->subHours(30)]);
    $site = Signature::factory()->create([
        'map_solarsystem_id' => $a1->id, 'signature_id' => 'GHI-789', 'map_connection_id' => null,
        'signature_category_id' => null, 'created_at' => CarbonImmutable::now()->subDays(29),
    ]);

    artisan(DeleteOldSignaturesCommand::class)->assertSuccessful();

    expect($site->fresh())->not->toBeNull()
        ->and(MapSolarsystem::withHidden()->find($a1->id)->alias)->toBeNull()
        ->and(MapSolarsystem::withHidden()->find($alpha->id))->toBeNull();

    Signature::query()->whereKey($site->id)->update(['created_at' => CarbonImmutable::now()->subDays(31)]);
    artisan(DeleteOldSignaturesCommand::class)->assertSuccessful();

    expect($site->fresh())->toBeNull()
        ->and(MapSolarsystem::withHidden()->find($a1->id))->toBeNull();
});

it('rage roll: a detached old chain hides unless something keeps it', function () {
    ['map' => $map, 'alpha' => $alpha, 'a1' => $a1, 'daisy_alpha' => $daisy_alpha] = chainMap();
    $daisy_alpha->delete();
    MapSolarsystem::query()->whereKey($alpha->id)->update(['alias' => 'A~1958']);
    MapSolarsystem::query()->whereKey($a1->id)->update(['alias' => 'A1~1958', 'pinned' => true]);

    expect(app(HideOldChainsAction::class)->handle($map, [$alpha->id]))->toBe([]);

    MapSolarsystem::query()->whereKey($a1->id)->update(['pinned' => false]);

    expect(app(HideOldChainsAction::class)->handle($map, [$alpha->id]))->toEqualCanonicalizing([$alpha->id, $a1->id]);
});

it('rage roll: an old chain with a k-space exit stays shown', function () {
    ['map' => $map, 'alpha' => $alpha, 'daisy_alpha' => $daisy_alpha] = chainMap();
    $daisy_alpha->delete();
    MapSolarsystem::query()->whereKey($alpha->id)->update(['alias' => 'A~1958']);
    $tama = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(30002813, 0.3, 'eve'), 'alias' => null, 'pinned' => false]);
    MapConnection::factory()->create(['map_id' => $map->id, 'from_map_solarsystem_id' => $alpha->id, 'to_map_solarsystem_id' => $tama->id]);

    expect(app(HideOldChainsAction::class)->handle($map))->toBe([]);
});

it('a side chain started elsewhere (no stamp) never hides by itself', function () {
    ['map' => $map, 'alpha' => $alpha, 'daisy_alpha' => $daisy_alpha] = chainMap();
    $daisy_alpha->delete();

    expect(app(HideOldChainsAction::class)->handle($map, [$alpha->id]))->toBe([]);
});

it('stamps by when the chain start was mapped', function () {
    ['alpha' => $alpha, 'a1' => $a1, 'daisy' => $daisy] = chainMap();

    $stamps = ChainMemory::stampsFor(collect([$a1]), collect([$alpha, $daisy]));

    expect($stamps)->toBe([$a1->id => '1958'])
        ->and(ChainMemory::plainAlias('A111~1958'))->toBe('A111');
});

function wormholeCategoryId(): int
{
    return (int) (App\Models\SignatureCategory::query()->where('code', App\Enums\SignatureCategory::Wormhole)->value('id')
        ?? App\Models\SignatureCategory::query()->insertGetId(['name' => 'Wormhole', 'code' => App\Enums\SignatureCategory::Wormhole->value]));
}

it('patch 37: a reconnect brings the whole old chain back, moved along, its pipes faded', function () {
    ['map' => $map, 'daisy' => $daisy, 'alpha' => $alpha, 'a1' => $a1, 'alpha_a1' => $alpha_a1] = chainMap();
    $alpha->update(['position_x' => 100, 'position_y' => 100]);
    $a1->update(['position_x' => 300, 'position_y' => 120]);
    $sig = Signature::factory()->create(['map_solarsystem_id' => $alpha->id, 'signature_id' => 'ABC-123', 'map_connection_id' => $alpha_a1->id]);
    $alpha_a1->forceFill(['lifetime' => 'eol'])->save();

    app(HideMapSolarsystemsAction::class)->handle($map, [$alpha->id, $a1->id], broadcast: false);

    expect(MapConnection::query()->where('map_id', $map->id)->count())->toBe(0)
        ->and(MapSolarsystem::withHidden()->find($alpha->id)->memory_links)->toHaveCount(1);

    app(StoreMapSolarsystemAction::class)->handle($map, [
        'solarsystem_id' => $alpha->solarsystem_id,
        'position_x' => 150,
        'position_y' => 200,
        'connect_to_map_solarsystem_id' => $daisy->id,
    ]);

    $back = MapSolarsystem::query()->find($a1->id);
    $pipe = MapConnection::query()->where('from_map_solarsystem_id', $alpha->id)->where('to_map_solarsystem_id', $a1->id)->first();

    expect($back)->not->toBeNull()
        ->and($back->alias)->toBe('A1')
        ->and([$back->position_x, $back->position_y])->toBe([350, 220])
        ->and($pipe)->not->toBeNull()
        ->and($pipe->from_memory)->toBeTrue()
        ->and($pipe->lifetime->value)->toBe('eol')
        ->and($sig->fresh()->map_connection_id)->toBe($pipe->id)
        ->and(MapSolarsystem::query()->find($alpha->id)->memory_links)->toBeNull();

    // A real mark (a different status than the factory's random one) un-fades it.
    $current = $pipe->mass_status instanceof BackedEnum ? $pipe->mass_status->value : (string) $pipe->mass_status;
    $pipe->update(['mass_status' => $current === 'reduced' ? 'critical' : 'reduced']);

    expect($pipe->fresh()->from_memory)->toBeFalse();
});

it('patch 37: a pipe to a system that stayed on the map is not remembered', function () {
    ['map' => $map, 'alpha' => $alpha, 'a1' => $a1] = chainMap();

    app(HideMapSolarsystemsAction::class)->handle($map, [$a1->id], broadcast: false);

    expect(MapSolarsystem::withHidden()->find($a1->id)->memory_links)->toBeNull()
        ->and(MapSolarsystem::query()->find($alpha->id)->memory_links)->toBeNull();
});

it('patch 37 cleanup: a hole marked EOL goes 5½ h after the first mark, a pipe without signature too', function () {
    ['map' => $map, 'alpha' => $alpha, 'a1' => $a1, 'alpha_a1' => $alpha_a1, 'daisy_alpha' => $daisy_alpha] = chainMap();
    $hole = Signature::factory()->create([
        'map_solarsystem_id' => $alpha->id, 'signature_id' => 'ABC-123', 'map_connection_id' => $alpha_a1->id,
        'signature_category_id' => wormholeCategoryId(), 'created_at' => CarbonImmutable::now()->subHours(8),
    ]);
    Signature::query()->whereKey($hole->id)->update(['lifetime' => 'eol', 'eol_since' => CarbonImmutable::now()->subHours(6)]);
    MapConnection::query()->whereKey($daisy_alpha->id)->update([
        'type' => 'wormhole', 'created_at' => CarbonImmutable::now()->subHours(3), 'connected_at' => null,
        'critical_since' => CarbonImmutable::now()->subMinutes(130),
    ]);

    artisan(DeleteOldSignaturesCommand::class)->assertSuccessful();

    expect($hole->fresh())->toBeNull()
        ->and(MapConnection::query()->find($alpha_a1->id))->toBeNull()
        ->and(MapConnection::query()->find($daisy_alpha->id))->toBeNull();
});

it('patch 37: deleting a rolled pipe takes the chain beyond it off the map (not into Side chains)', function () {
    ['map' => $map, 'daisy' => $daisy, 'alpha' => $alpha, 'a1' => $a1, 'daisy_alpha' => $daisy_alpha] = chainMap();

    app(App\Actions\MapConnections\DeleteMapConnectionAction::class)->handle($daisy_alpha);

    expect(MapSolarsystem::query()->whereKey([$alpha->id, $a1->id])->count())->toBe(0)
        ->and(MapSolarsystem::withHidden()->find($alpha->id)?->isHidden())->toBeTrue()
        ->and(MapSolarsystem::query()->find($daisy->id))->not->toBeNull();
});

it('patch 37: Disconnect (keep as side chain) leaves the chain beyond on the map', function () {
    ['daisy' => $daisy, 'alpha' => $alpha, 'a1' => $a1, 'daisy_alpha' => $daisy_alpha] = chainMap();

    app(App\Actions\MapConnections\DeleteMapConnectionAction::class)->handle($daisy_alpha, keepChain: true);

    expect(MapConnection::query()->find($daisy_alpha->id))->toBeNull()
        ->and(MapSolarsystem::query()->whereKey([$daisy->id, $alpha->id, $a1->id])->count())->toBe(3);
});

it('patch 37: deleting a pipe that still leaves another way home takes nothing off', function () {
    ['map' => $map, 'daisy' => $daisy, 'alpha' => $alpha, 'a1' => $a1, 'alpha_a1' => $alpha_a1] = chainMap();
    MapConnection::factory()->create(['map_id' => $map->id, 'from_map_solarsystem_id' => $daisy->id, 'to_map_solarsystem_id' => $a1->id]);

    app(App\Actions\MapConnections\DeleteMapConnectionAction::class)->handle($alpha_a1);

    expect(MapSolarsystem::query()->whereKey([$alpha->id, $a1->id])->count())->toBe(2);
});

it('patch 37: a pinned system cut off from home stays (Side chains)', function () {
    ['map' => $map, 'alpha' => $alpha, 'a1' => $a1, 'daisy_alpha' => $daisy_alpha] = chainMap();
    $a1->update(['pinned' => true]);

    app(App\Actions\MapConnections\DeleteMapConnectionAction::class)->handle($daisy_alpha);

    expect(MapSolarsystem::query()->find($a1->id))->not->toBeNull()
        ->and(MapSolarsystem::query()->find($alpha->id))->toBeNull();
});
