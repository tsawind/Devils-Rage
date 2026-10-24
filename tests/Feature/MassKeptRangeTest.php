<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapConnection;
use App\Models\MapConnectionJump;
use App\Models\MassSession;
use App\Models\User;
use App\Support\HoleWindow;

use function Pest\Laravel\actingAs;

/**
 * Patch 52: the range a mass session works out ("only our jumps") is kept on the pipe, and
 * stays after "Done massing" (only the top) or "Done and watching" (the whole range).
 */
const KEPT_M = 1_000_000;

/**
 * @return array{map: Map, connection: MapConnection, user: User, session: MassSession}
 */
function keptRangeHole(): array
{
    $map = Map::factory()->create();
    $home = placeMapSolarsystem($map, 31000301);
    $far = placeMapSolarsystem($map, 31000302, 300, 100);
    $connection = MapConnection::factory()->create(['map_id' => $map->id, 'from_map_solarsystem_id' => $home->id, 'to_map_solarsystem_id' => $far->id]);
    $user = User::factory()
        ->has(Character::factory()->has(MapAccess::factory(['permission' => Permission::Member])->for($map)))
        ->create();
    $session = MassSession::query()->create([
        'map_id' => $map->id,
        'map_connection_id' => $connection->id,
        'goal' => 'roll',
        'home_map_solarsystem_id' => $home->id,
        'only_our_jumps' => true,
    ]);

    return compact('map', 'connection', 'user', 'session');
}

function keptJump(Map $map, MapConnection $connection, int $mass): void
{
    MapConnectionJump::query()->create(['map_id' => $map->id, 'map_connection_id' => $connection->id, 'from_solarsystem_id' => 31000301, 'to_solarsystem_id' => 31000302, 'mass' => $mass]);
}

it('patch 52: works out the hole size like the map does', function () {
    $window = HoleWindow::size(2_000 * KEPT_M, [
        ['status' => 'reduced', 'logged' => 927 * KEPT_M, 'flip' => null],
        ['status' => 'critical', 'logged' => 1_620 * KEPT_M, 'flip' => null],
    ]);

    expect($window['consistent'])->toBeTrue()
        ->and($window['high'])->toEqualWithDelta(1_800 * KEPT_M, KEPT_M)
        ->and($window['low'])->toEqualWithDelta(1_800 * KEPT_M, 1_000 * KEPT_M);

    expect(HoleWindow::size(2_000 * KEPT_M, [
        ['status' => 'critical', 'logged' => 100 * KEPT_M, 'flip' => null],
    ])['consistent'])->toBeFalse();
});

it('patch 52: a mark in a session with only our jumps keeps the size on the pipe', function () {
    ['map' => $map, 'connection' => $connection, 'user' => $user, 'session' => $session] = keptRangeHole();
    keptJump($map, $connection, 1_620 * KEPT_M);

    actingAs($user)->postJson(route('maps.mass-sessions.observe', [$map, $session]), ['status' => 'critical', 'nominal' => 2_000 * KEPT_M])->assertOk();

    $connection->refresh();
    expect($connection->kept_size_from)->toBe('session')
        ->and($connection->kept_size_high)->toBeGreaterThanOrEqual(1_800 * KEPT_M)
        ->and($connection->kept_size_high)->toBeLessThan(1_801 * KEPT_M)
        ->and($connection->kept_size_low)->toBeGreaterThanOrEqual(1_799 * KEPT_M);
});

it('patch 52: without only our jumps nothing is kept', function () {
    ['map' => $map, 'connection' => $connection, 'user' => $user, 'session' => $session] = keptRangeHole();
    $session->forceFill(['only_our_jumps' => false])->save();
    keptJump($map, $connection, 1_620 * KEPT_M);

    actingAs($user)->postJson(route('maps.mass-sessions.observe', [$map, $session]), ['status' => 'critical', 'nominal' => 2_000 * KEPT_M])->assertOk();

    expect($connection->refresh()->kept_size_high)->toBeNull();
});

it('patch 52: ending the session keeps the top, the whole range with watching, or nothing', function (string $keep) {
    ['map' => $map, 'connection' => $connection, 'user' => $user, 'session' => $session] = keptRangeHole();
    keptJump($map, $connection, 1_620 * KEPT_M);
    actingAs($user)->postJson(route('maps.mass-sessions.observe', [$map, $session]), ['status' => 'critical', 'nominal' => 2_000 * KEPT_M])->assertOk();

    actingAs($user)->deleteJson(route('maps.mass-sessions.destroy', [$map, $session]), ['keep' => $keep])->assertOk();

    $connection->refresh();
    match ($keep) {
        'top' => expect($connection->kept_size_from)->toBe('kept')
            ->and($connection->kept_size_low)->toBeNull()
            ->and($connection->kept_size_high)->not->toBeNull()
            ->and($connection->mass_counting_since)->toBeNull(),
        'watch' => expect($connection->kept_size_from)->toBe('kept')
            ->and($connection->kept_size_low)->toBeGreaterThanOrEqual(1_799 * KEPT_M)
            ->and($connection->mass_watched_at)->not->toBeNull()
            ->and($connection->mass_counting_since)->not->toBeNull(),
        default => expect($connection->kept_size_high)->toBeNull()->and($connection->kept_size_from)->toBeNull(),
    };
})->with(['top', 'watch', 'forget']);

it('patch 52: turning watching off after "done and watching" keeps only the top', function () {
    ['map' => $map, 'connection' => $connection, 'user' => $user] = keptRangeHole();
    $connection->forceFill(['kept_size_low' => 1_800 * KEPT_M, 'kept_size_high' => 1_801 * KEPT_M, 'kept_size_from' => 'kept', 'mass_watched_at' => now()])->save();

    actingAs($user)->post(route('map-connections.watched', $connection), ['on' => false])->assertRedirect();

    $connection->refresh();
    expect($connection->kept_size_low)->toBeNull()
        ->and($connection->kept_size_high)->toBe(1_801 * KEPT_M);
});

it('patch 52: "Keep the range" remembers the answer, "Trust the mark" drops the range', function () {
    ['connection' => $connection, 'user' => $user] = keptRangeHole();
    $connection->forceFill(['kept_size_high' => 1_801 * KEPT_M, 'kept_size_from' => 'kept'])->save();

    actingAs($user)->post(route('map-connections.kept-range', $connection), ['choice' => 'keep'])->assertRedirect();
    expect($connection->refresh()->kept_ack_at)->not->toBeNull()
        ->and($connection->kept_size_high)->toBe(1_801 * KEPT_M);

    actingAs($user)->post(route('map-connections.kept-range', $connection), ['choice' => 'trust'])->assertRedirect();
    expect($connection->refresh()->kept_size_high)->toBeNull();
});

it('patch 52: a later mark never clears the kept range by itself', function () {
    ['map' => $map, 'connection' => $connection, 'user' => $user] = keptRangeHole();
    $connection->forceFill(['kept_size_high' => 1_801 * KEPT_M, 'kept_size_from' => 'kept'])->save();

    actingAs($user)->post(route('map-connections.checked', $connection))->assertRedirect();

    expect($connection->refresh()->kept_size_high)->toBe(1_801 * KEPT_M);
});
