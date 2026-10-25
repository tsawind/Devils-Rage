<?php

declare(strict_types=1);

use App\Actions\Tracking\ProbeLocationsAction;
use App\Events\Characters\CharacterStatusUpdatedEvent;
use App\Models\Character;
use App\Models\CharacterStatus;
use App\Models\EsiScope;
use App\Models\EsiToken;
use App\Models\Map;
use App\Models\MapConnection;
use App\Models\MapConnectionJump;
use App\Models\MapUserSetting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use NicolasKion\Esi\Enums\EsiScope as EsiScopeName;

/**
 * Patch 54: every online character's ESI location is asked at once; a move reaches the maps and
 * the jump log straight away, without waiting for the queue.
 *
 * @return array{status: CharacterStatus, connection: MapConnection}
 */
function probeSetup(int $from, int $to, bool $expired = false): array
{
    $character = Character::factory()->create();
    $map = Map::factory()->create();
    MapUserSetting::query()->create(['map_id' => $map->id, 'user_id' => $character->user_id, 'tracking_allowed' => true, 'is_tracking' => true]);
    $origin = placeMapSolarsystem($map, $from);
    $target = placeMapSolarsystem($map, $to, 300, 300);
    $connection = MapConnection::factory()->create(['map_id' => $map->id, 'from_map_solarsystem_id' => $origin->id, 'to_map_solarsystem_id' => $target->id]);

    $token = EsiToken::query()->create([
        'character_id' => $character->id,
        'character_owner_hash' => 'hash',
        'access_token' => 'access',
        'refresh_token' => 'refresh',
        'expires_at' => $expired ? now()->subHour() : now()->addMinutes(20),
    ]);
    $scope = EsiScope::query()->firstOrCreate(['name' => EsiScopeName::ReadLocations->value]);
    $token->esiScopes()->attach($scope->id);

    $status = CharacterStatus::query()->create(['character_id' => $character->id, 'solarsystem_id' => $from, 'is_online' => true]);

    return ['status' => $status, 'connection' => $connection];
}

it('moves a character, tells the maps and logs the jump from one parallel ESI round', function () {
    Event::fake([CharacterStatusUpdatedEvent::class]);
    ['status' => $status, 'connection' => $connection] = probeSetup(31000231, 31000232);
    Http::fake(['*/characters/*/location/*' => Http::response(['solar_system_id' => 31000232])]);

    $moved = app(ProbeLocationsAction::class)->handle(collect([$status->fresh()]));

    expect($moved)->toBe(1)
        ->and($status->fresh()->solarsystem_id)->toBe(31000232)
        ->and(MapConnectionJump::query()->where('map_connection_id', $connection->id)->count())->toBe(1);
    Event::assertDispatched(CharacterStatusUpdatedEvent::class, fn (CharacterStatusUpdatedEvent $event) => $event->map_id === $connection->map_id);
});

it('leaves characters that did not move, failed answers and expired tokens alone', function () {
    Event::fake([CharacterStatusUpdatedEvent::class]);
    ['status' => $still] = probeSetup(31000233, 31000234);
    ['status' => $expired] = probeSetup(31000235, 31000236, expired: true);
    Http::fake(['*/characters/*/location/*' => Http::response(['solar_system_id' => 31000233])]);

    expect(app(ProbeLocationsAction::class)->handle(collect([$still->fresh(), $expired->fresh()])))->toBe(0)
        ->and($expired->fresh()->solarsystem_id)->toBe(31000235);
    Http::assertSentCount(1);

    Http::fake(['*' => Http::response('down', 503)]);
    expect(app(ProbeLocationsAction::class)->handle(collect([$still->fresh()])))->toBe(0);
    Event::assertNotDispatched(CharacterStatusUpdatedEvent::class);
});

it('does not move a character back while the app just reported it (chat log)', function () {
    ['status' => $status] = probeSetup(31000237, 31000238);
    $status->update(['app_location_at' => now()]);
    Http::fake(['*/characters/*/location/*' => Http::response(['solar_system_id' => 31000238])]);

    expect(app(ProbeLocationsAction::class)->handle(collect([$status->fresh()])))->toBe(0)
        ->and($status->fresh()->solarsystem_id)->toBe(31000237);
});
