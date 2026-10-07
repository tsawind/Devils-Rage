<?php

declare(strict_types=1);

use App\Enums\MassStatus;
use App\Models\Map;
use App\Models\MapConnection;
use App\Models\MapConnectionJump;

use function Pest\Laravel\travel;

function massStatusConnection(): MapConnection
{
    $map = Map::factory()->create();
    $from = placeMapSolarsystem($map, 31000101);
    $to = placeMapSolarsystem($map, 31000102, 300, 300);

    return MapConnection::factory()->create([
        'map_id' => $map->id,
        'from_map_solarsystem_id' => $from->id,
        'to_map_solarsystem_id' => $to->id,
        'mass_status' => MassStatus::Fresh,
    ]);
}

function logJump(MapConnection $connection, int $mass): void
{
    MapConnectionJump::factory()->create([
        'map_id' => $connection->map_id,
        'map_connection_id' => $connection->id,
        'from_solarsystem_id' => 31000101,
        'to_solarsystem_id' => 31000102,
        'mass' => $mass,
    ]);
}

it('patch 32: a fresh hole counts every logged jump', function () {
    $connection = massStatusConnection();
    logJump($connection, 100_000_000);
    logJump($connection, 50_000_000);

    $loaded = MapConnection::query()->withJumpSummary()->findOrFail($connection->id);

    expect((int) $loaded->jumps_mass_sum)->toBe(150_000_000)
        ->and((int) $loaded->jumps_mass_since_status)->toBe(150_000_000)
        ->and($loaded->mass_status_updated_at)->toBeNull();
});

it('patch 32: marking a hole reduced starts a new jump log', function () {
    $connection = massStatusConnection();
    logJump($connection, 100_000_000);

    travel(1)->minutes();
    $connection->update(['mass_status' => MassStatus::Reduced]);
    travel(1)->minutes();
    logJump($connection, 50_000_000);

    $loaded = MapConnection::query()->withJumpSummary()->findOrFail($connection->id);

    expect($loaded->mass_status_updated_at)->not->toBeNull()
        ->and((int) $loaded->jumps_mass_sum)->toBe(150_000_000)
        ->and((int) $loaded->jumps_mass_since_status)->toBe(50_000_000);
});

it('patch 32: saving without a status change keeps the old timestamp', function () {
    $connection = massStatusConnection();
    $connection->update(['mass_status' => MassStatus::Critical]);
    $stamp = $connection->fresh()->mass_status_updated_at;

    travel(5)->minutes();
    $connection->fresh()->update(['preserve_mass' => true]);

    expect($connection->fresh()->mass_status_updated_at?->toIso8601String())->toBe($stamp?->toIso8601String());
});

function v753Connection(): MapConnection
{
    $connection = massStatusConnection();
    $wormhole = App\Models\Wormhole::create([
        'name' => 'V753',
        'total_mass' => 3_300_000_000,
        'maximum_jump_mass' => 1_350_000_000,
        'maximum_lifetime' => 86_400,
        'leads_to' => 'c6',
    ]);
    $connection->fromMapSolarsystem->signatures()->create([
        'signature_id' => 'ABC-123',
        'wormhole_id' => $wormhole->id,
        'map_connection_id' => $connection->id,
    ]);

    return $connection->fresh();
}

it('patch 33: the log proves reduced at 55% of the listed mass (V753: 1,815 M)', function () {
    $connection = v753Connection();
    logJump($connection, 1_800_000_000);
    app(App\Actions\MapConnections\InferMassStatusFromJumpsAction::class)->handle($connection);
    expect($connection->fresh()->mass_status)->toBe(MassStatus::Fresh);

    logJump($connection, 20_000_000);
    app(App\Actions\MapConnections\InferMassStatusFromJumpsAction::class)->handle($connection->fresh());

    $after = $connection->fresh();
    expect($after->mass_status)->toBe(MassStatus::Reduced)
        ->and($after->mass_status_from_log)->toBeTrue()
        ->and($after->signatures()->first()->mass_status)->toBe(MassStatus::Reduced)
        ->and($after->should_have_rolled)->toBeFalse();
});

it('patch 33: critical at 99% and "should have rolled" past 110% (V753: 3,267 M / 3,630 M)', function () {
    $connection = v753Connection();
    logJump($connection, 3_270_000_000);
    app(App\Actions\MapConnections\InferMassStatusFromJumpsAction::class)->handle($connection);
    expect($connection->fresh()->mass_status)->toBe(MassStatus::Critical)
        ->and($connection->fresh()->should_have_rolled)->toBeFalse();

    logJump($connection, 400_000_000);
    app(App\Actions\MapConnections\InferMassStatusFromJumpsAction::class)->handle($connection->fresh());
    expect($connection->fresh()->should_have_rolled)->toBeTrue();
});

it('patch 33: never makes a status better, and a hand-set status is not from the log', function () {
    $connection = v753Connection();
    $connection->update(['mass_status' => MassStatus::Critical]);
    logJump($connection, 2_000_000_000);
    app(App\Actions\MapConnections\InferMassStatusFromJumpsAction::class)->handle($connection->fresh());

    expect($connection->fresh()->mass_status)->toBe(MassStatus::Critical)
        ->and($connection->fresh()->mass_status_from_log)->toBeFalse();
});

it('patch 33: does nothing without a known hole type', function () {
    $connection = massStatusConnection();
    logJump($connection, 9_000_000_000);

    expect(app(App\Actions\MapConnections\InferMassStatusFromJumpsAction::class)->handle($connection))->toBeFalse()
        ->and($connection->fresh()->mass_status)->toBe(MassStatus::Fresh);
});
