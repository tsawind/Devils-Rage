<?php

declare(strict_types=1);

use App\Actions\MapSolarsystem\MapNamesAction;
use App\Actions\Tracking\StoreTrackingAction;
use App\Data\TrackingData;
use App\Models\Map;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Providers\AppServiceProvider;
use App\Services\Discord\ChainWayDescriber;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

/**
 * Patch 39: floating names (loops, old chains), names that only count up, the loop
 * jump keeping a system's name, and the way from home in Discord posts.
 *
 * Daisy → Alpha (A) → A0 → X (A01), and Alpha → X through a second hole (A2): a loop.
 *
 * @return array<string, mixed>
 */
function p39Map(): array
{
    $daisy_id = makeSolarsystem(31003901, -1.0, 'wormhole');
    $map = Map::factory()->create(['home_solarsystem_id' => $daisy_id, 'bookmark_ignored_alias' => 'Daisy']);
    $daisy = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => $daisy_id, 'alias' => 'Daisy', 'pinned' => false]);
    $alpha = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(31003902, -1.0, 'wormhole'), 'alias' => 'A', 'pinned' => false]);
    $a0 = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(31003903, -1.0, 'wormhole'), 'alias' => 'A0', 'pinned' => false]);
    $x = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(31003904, -1.0, 'wormhole'), 'alias' => 'A01', 'pinned' => false]);
    $daisy_alpha = MapConnection::factory()->create(['map_id' => $map->id, 'from_map_solarsystem_id' => $daisy->id, 'to_map_solarsystem_id' => $alpha->id]);
    $alpha_a0 = MapConnection::factory()->create(['map_id' => $map->id, 'from_map_solarsystem_id' => $alpha->id, 'to_map_solarsystem_id' => $a0->id]);
    $a0_x = MapConnection::factory()->create(['map_id' => $map->id, 'from_map_solarsystem_id' => $a0->id, 'to_map_solarsystem_id' => $x->id]);
    $alpha_x = MapConnection::factory()->create(['map_id' => $map->id, 'from_map_solarsystem_id' => $alpha->id, 'to_map_solarsystem_id' => $x->id]);
    $via_a0 = Signature::factory()->create(['map_solarsystem_id' => $a0->id, 'signature_id' => 'WKZ-101', 'alias' => 'A01', 'map_connection_id' => $a0_x->id]);
    $loop = Signature::factory()->create(['map_solarsystem_id' => $alpha->id, 'signature_id' => 'QRS-102', 'alias' => 'A2', 'map_connection_id' => $alpha_x->id]);

    return compact('map', 'daisy', 'alpha', 'a0', 'x', 'daisy_alpha', 'alpha_a0', 'a0_x', 'alpha_x', 'via_a0', 'loop');
}

it('switches a loop to the hole\'s number; the old name keeps floating through its own hole', function () {
    ['x' => $x, 'loop' => $loop] = p39Map();

    app(MapNamesAction::class)->switchTo($x, $loop);

    expect($x->fresh()->alias)->toBe('A2')
        // A01 is still the name of A0's hole into it: it floats from there, nothing stored.
        ->and($x->fresh()->old_aliases)->toBe([]);
});

it('keeps the old name as "was" when no hole carries it any more', function () {
    ['x' => $x, 'loop' => $loop, 'via_a0' => $via_a0] = p39Map();
    $via_a0->update(['alias' => null]);

    app(MapNamesAction::class)->switchTo($x, $loop);

    expect($x->fresh()->alias)->toBe('A2')
        ->and($x->fresh()->old_aliases)->toBe(['A01']);
});

it('refuses to switch to a name another system already has', function () {
    ['map' => $map, 'x' => $x, 'loop' => $loop] = p39Map();
    MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(31003905, -1.0, 'wormhole'), 'alias' => 'A2', 'pinned' => false]);

    app(MapNamesAction::class)->switchTo($x, $loop);
})->throws(Illuminate\Validation\ValidationException::class);

it('drops a floating name: a loop\'s hole goes back to the main name, a "was" name is forgotten', function () {
    ['x' => $x, 'loop' => $loop] = p39Map();
    $x->update(['old_aliases' => ['A1~1917']]);

    app(MapNamesAction::class)->drop($x, $loop, null);
    app(MapNamesAction::class)->drop($x->fresh(), null, 'A1~1917');

    expect($loop->fresh()->alias)->toBeNull()
        ->and($x->fresh()->old_aliases)->toBe([]);
});

it('an old chain\'s rows: adopt the new number, then rename the hole beyond', function () {
    ['map' => $map, 'x' => $x, 'loop' => $loop] = p39Map();
    $x->update(['alias' => 'A1~1917']);
    $y = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(31003906, -1.0, 'wormhole'), 'alias' => 'A11~1917', 'pinned' => false]);
    $x_y = MapConnection::factory()->create(['map_id' => $map->id, 'from_map_solarsystem_id' => $x->id, 'to_map_solarsystem_id' => $y->id]);
    $beyond = Signature::factory()->create(['map_solarsystem_id' => $x->id, 'signature_id' => 'FFT-103', 'alias' => 'A11~1917', 'map_connection_id' => $x_y->id]);

    app(MapNamesAction::class)->adopt($x->fresh(), $loop);
    expect($x->fresh()->alias)->toBe('A2')
        ->and($x->fresh()->old_aliases)->toBe(['A1~1917']);

    app(MapNamesAction::class)->renameHole($x->fresh(), $beyond, 'A21');
    expect($beyond->fresh()->alias)->toBe('A21')
        ->and($y->fresh()->alias)->toBe('A21')
        ->and($y->fresh()->old_aliases)->toBe(['A11~1917'])
        ->and($y->fresh()->cleanup_return_pending)->toBeTrue();

    app(MapNamesAction::class)->keep($x->fresh());
    expect($x->fresh()->name_kept)->toBeTrue();
});

it('renames to a taken number by swapping, or giving the other the next number', function () {
    ['alpha' => $alpha] = p39Map();
    $three = Signature::factory()->create(['map_solarsystem_id' => $alpha->id, 'signature_id' => 'LIH-104', 'alias' => 'A3', 'map_connection_id' => null]);
    $four = Signature::factory()->create(['map_solarsystem_id' => $alpha->id, 'signature_id' => 'MVD-105', 'alias' => 'A4', 'map_connection_id' => null]);

    app(MapNamesAction::class)->renameTo($alpha, $four, 'A3', 'swap', null);
    expect($four->fresh()->alias)->toBe('A3')
        ->and($three->fresh()->alias)->toBe('A4');

    app(MapNamesAction::class)->renameTo($alpha, $three->fresh(), 'A3', 'bump', 'A5');
    expect($three->fresh()->alias)->toBe('A3')
        ->and($four->fresh()->alias)->toBe('A5');
});

it('a plain rename refuses a taken name', function () {
    ['alpha' => $alpha] = p39Map();
    Signature::factory()->create(['map_solarsystem_id' => $alpha->id, 'signature_id' => 'LIH-104', 'alias' => 'A3', 'map_connection_id' => null]);
    $four = Signature::factory()->create(['map_solarsystem_id' => $alpha->id, 'signature_id' => 'MVD-105', 'alias' => 'A4', 'map_connection_id' => null]);

    app(MapNamesAction::class)->renameTo($alpha, $four, 'A3', 'plain', null);
})->throws(Illuminate\Validation\ValidationException::class);

it('remembers the highest hole number a system handed out', function () {
    ['alpha' => $alpha] = p39Map();

    Signature::factory()->create(['map_solarsystem_id' => $alpha->id, 'signature_id' => 'AAA-111', 'alias' => 'A5', 'map_connection_id' => null]);
    expect($alpha->fresh()->hole_slot_high)->toBe('5');

    // A lower number never lowers it, and names that aren't its numbers don't count.
    Signature::factory()->create(['map_solarsystem_id' => $alpha->id, 'signature_id' => 'BBB-222', 'alias' => 'A3', 'map_connection_id' => null]);
    Signature::factory()->create(['map_solarsystem_id' => $alpha->id, 'signature_id' => 'CCC-333', 'alias' => 'B111', 'map_connection_id' => null]);
    expect($alpha->fresh()->hole_slot_high)->toBe('5');
});

it('a jump into a system already on the map keeps its name; the hole keeps its number', function () {
    ['alpha' => $alpha, 'x' => $x] = p39Map();
    $hole = Signature::factory()->create(['map_solarsystem_id' => $alpha->id, 'signature_id' => 'NEW-106', 'alias' => null, 'map_connection_id' => null]);
    MapConnection::query()->where('from_map_solarsystem_id', $alpha->id)->where('to_map_solarsystem_id', $x->id)->delete();

    app(StoreTrackingAction::class)->handle(TrackingData::from([
        'from_map_solarsystem_id' => $alpha->id,
        'to_solarsystem_id' => $x->solarsystem_id,
        'signature_id' => $hole->id,
        'alias' => 'A3',
    ]));

    expect($x->fresh()->alias)->toBe('A01')
        ->and($hole->fresh()->alias)->toBe('A3')
        ->and($hole->fresh()->map_connection_id)->not->toBeNull();
});

it('stands a share-link viewer in for live map updates', function () {
    $request = Request::create('/broadcasting/auth', 'POST');
    $session = new Store('test', new ArraySessionHandler(10));
    $request->setLaravelSession($session);

    expect(AppServiceProvider::shareViewer($request))->toBeNull();

    $session->put('map_share_token_5', 'abc');
    expect(AppServiceProvider::shareViewer($request))->toBeInstanceOf(GenericUser::class);
});

it('describes the way from home with what fits through it', function () {
    ['map' => $map, 'a0' => $a0, 'alpha_a0' => $alpha_a0, 'daisy_alpha' => $daisy_alpha] = p39Map();
    $daisy_alpha->forceFill(['ship_size' => null])->save();
    $type = makeWormhole('N432', 1_000_000_000, 'c5');
    Signature::factory()->create(['map_solarsystem_id' => $a0->id, 'signature_id' => 'TOP-107', 'wormhole_id' => $type->id, 'map_connection_id' => $alpha_a0->id]);
    $alpha_a0->forceFill(['mass_status' => 'fresh', 'lifetime' => 'healthy'])->save();

    $way = app(ChainWayDescriber::class)->fromHome($map->fresh(), $a0->solarsystem_id);

    expect($way)->not->toBeNull()
        ->and($way['jumps'])->toBe(2)
        ->and($way['home'])->toBe('Daisy')
        ->and($way['line'])->toContain('Daisy → Alpha')
        ->and($way['line'])->toContain('A0 (N432 XL ←)')
        ->and($way['fits'])->toStartWith('Fits: capitals');
});
