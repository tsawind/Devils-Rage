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
