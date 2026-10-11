<?php

declare(strict_types=1);

use App\Enums\LifetimeStatus;
use App\Models\Map;
use App\Models\MapConnection;

/**
 * Patch 37: the first End of life and first Critical marks are kept, so the latest
 * collapse time doesn't move when someone checks or re-marks the hole later.
 */
function lifeConnection(): MapConnection
{
    $map = Map::factory()->create();
    $from = placeMapSolarsystem($map, 31000301);
    $to = placeMapSolarsystem($map, 31000302, 300, 300);

    return MapConnection::factory()->create([
        'map_id' => $map->id,
        'from_map_solarsystem_id' => $from->id,
        'to_map_solarsystem_id' => $to->id,
        'lifetime' => LifetimeStatus::Healthy,
    ]);
}

it('patch 37: keeps the first EOL mark through later checks, then adds the first Critical mark', function () {
    $connection = lifeConnection();

    $connection->update(['lifetime' => LifetimeStatus::EndOfLife]);
    $eolSince = $connection->fresh()->eol_since;
    expect($eolSince)->not->toBeNull()->and($connection->fresh()->critical_since)->toBeNull();

    $this->travel(30)->minutes();
    $connection->fresh()->update(['lifetime' => LifetimeStatus::EndOfLife, 'checked_at' => now()]);
    expect($connection->fresh()->eol_since->equalTo($eolSince))->toBeTrue();

    $this->travel(30)->minutes();
    $connection->fresh()->update(['lifetime' => LifetimeStatus::Critical]);
    expect($connection->fresh()->eol_since->equalTo($eolSince))->toBeTrue()
        ->and($connection->fresh()->critical_since)->not->toBeNull();
});

it('patch 37: back to healthy clears the marks', function () {
    $connection = lifeConnection();
    $connection->update(['lifetime' => LifetimeStatus::Critical]);
    expect($connection->fresh()->eol_since)->not->toBeNull()->and($connection->fresh()->critical_since)->not->toBeNull();

    $connection->fresh()->update(['lifetime' => LifetimeStatus::Healthy]);
    expect($connection->fresh()->eol_since)->toBeNull()->and($connection->fresh()->critical_since)->toBeNull();
});

it('patch 37: marking imminent keeps the first EOL and critical marks and adds imminent_since', function () {
    $connection = lifeConnection();
    $connection->update(['lifetime' => LifetimeStatus::EndOfLife]);
    $eolSince = $connection->fresh()->eol_since;

    $this->travel(20)->minutes();
    $connection->fresh()->update(['lifetime' => LifetimeStatus::Imminent]);

    $fresh = $connection->fresh();
    expect($fresh->eol_since->equalTo($eolSince))->toBeTrue()
        ->and($fresh->critical_since)->not->toBeNull()
        ->and($fresh->imminent_since)->not->toBeNull();
});

it('patch 37: an EOL! hole turns imminent by itself when its hour runs out', function () {
    $connection = lifeConnection();
    $connection->update(['lifetime' => LifetimeStatus::Critical]);

    $this->travel(30)->minutes();
    $this->artisan('app:check-connection-age')->assertSuccessful();
    expect($connection->fresh()->lifetime)->toBe(LifetimeStatus::Critical);

    $this->travel(35)->minutes();
    $this->artisan('app:check-connection-age')->assertSuccessful();
    expect($connection->fresh()->lifetime)->toBe(LifetimeStatus::Imminent)
        ->and($connection->fresh()->imminent_since)->not->toBeNull()
        ->and($connection->fresh()->checked_at)->toBeNull();
});
