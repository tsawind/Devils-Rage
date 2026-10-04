<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapConnection;
use App\Models\MapConnectionJump;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Models\User;
use App\Support\Undo\MapUndoSnapshots;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

function undoUser(Map $map, Permission $permission = Permission::Member): User
{
    $user = User::factory()
        ->has(Character::factory()->has(MapAccess::factory(['permission' => $permission])->for($map)))
        ->create();

    $user->forceFill(['preferred_character_id' => $user->characters()->value('id')])->save();

    return $user->refresh();
}

/**
 * @return array{0: MapSolarsystem, 1: MapSolarsystem, 2: MapConnection, 3: Signature, 4: Signature, 5: MapConnectionJump}
 */
function undoChain(Map $map): array
{
    $home = placeMapSolarsystem($map, 30004101);
    $home->update(['pinned' => true]);
    $alpha = placeMapSolarsystem($map, 30004102, 400, 100);
    $alpha->update(['alias' => 'A']);
    $connection = MapConnection::factory()->create([
        'map_id' => $map->id,
        'from_map_solarsystem_id' => $home->id,
        'to_map_solarsystem_id' => $alpha->id,
    ]);
    $out = Signature::factory()->create(['map_solarsystem_id' => $home->id, 'map_connection_id' => $connection->id, 'signature_id' => 'ABC-123']);
    $back = Signature::factory()->create(['map_solarsystem_id' => $alpha->id, 'map_connection_id' => $connection->id, 'signature_id' => 'XWE-456']);
    $jump = MapConnectionJump::factory()->create([
        'map_id' => $map->id,
        'map_connection_id' => $connection->id,
        'from_solarsystem_id' => 30004101,
        'to_solarsystem_id' => 30004102,
    ]);

    return [$home, $alpha, $connection, $out, $back, $jump];
}

it('puts a deleted system back with its pipe, signatures and logged jumps', function () {
    $map = Map::factory()->create();
    [$home, $alpha, $connection, $out, $back, $jump] = undoChain($map);
    $user = undoUser($map);
    $token = (string) Str::uuid();

    actingAs($user)
        ->delete(route('map-solarsystems.destroy', $alpha), ['undo_token' => $token])
        ->assertRedirect();

    expect(MapSolarsystem::find($alpha->id))->toBeNull()
        ->and(MapConnection::find($connection->id))->toBeNull()
        ->and(Signature::find($out->id))->toBeNull();

    actingAs($user)
        ->post(route('map-undo.store', $token))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $restored = MapSolarsystem::find($alpha->id);
    expect($restored)->not->toBeNull()
        ->and($restored->alias)->toBe('A')
        ->and($restored->position_x)->toBe(400)
        ->and(MapConnection::find($connection->id)?->from_map_solarsystem_id)->toBe($home->id)
        ->and(Signature::find($out->id)?->map_connection_id)->toBe($connection->id)
        ->and(Signature::find($back->id)?->signature_id)->toBe('XWE-456')
        ->and(MapConnectionJump::find($jump->id))->not->toBeNull();
});

it('puts a deleted pipe back with the signatures on both sides', function () {
    $map = Map::factory()->create();
    [, , $connection, $out, $back] = undoChain($map);
    $user = undoUser($map);
    $token = (string) Str::uuid();

    actingAs($user)
        ->delete(route('map-connections.destroy', $connection), ['undo_token' => $token])
        ->assertRedirect();

    expect(MapConnection::find($connection->id))->toBeNull();

    actingAs($user)->post(route('map-undo.store', $token))->assertRedirect();

    expect(MapConnection::find($connection->id))->not->toBeNull()
        ->and(Signature::find($out->id)?->map_connection_id)->toBe($connection->id)
        ->and(Signature::find($back->id)?->map_connection_id)->toBe($connection->id);
});

it('puts a deleted selection back', function () {
    $map = Map::factory()->create();
    [, $alpha] = undoChain($map);
    $bravo = placeMapSolarsystem($map, 30004103, 400, 300);
    $user = undoUser($map);
    $token = (string) Str::uuid();

    actingAs($user)
        ->delete(route('map-selection.destroy'), ['map_solarsystem_ids' => [$alpha->id, $bravo->id], 'undo_token' => $token])
        ->assertRedirect();

    expect(MapSolarsystem::query()->whereIn('id', [$alpha->id, $bravo->id])->count())->toBe(0);

    actingAs($user)->post(route('map-undo.store', $token))->assertRedirect();

    expect(MapSolarsystem::query()->whereIn('id', [$alpha->id, $bravo->id])->count())->toBe(2);
});

it('undoing a paste drops what it added and resets what it changed', function () {
    $map = Map::factory()->create();
    [$home, , , $out] = undoChain($map);
    $user = undoUser($map);
    $token = (string) Str::uuid();
    $snapshots = app(MapUndoSnapshots::class);

    $snapshots->capture($map, $user->id, $token, signatureSystemIds: [$home->id], prune: true);
    $added = Signature::factory()->create(['map_solarsystem_id' => $home->id, 'signature_id' => 'NEW-001']);
    $out->update(['signature_id' => 'ZZZ-999']);

    actingAs($user)->post(route('map-undo.store', $token))->assertRedirect();

    expect(Signature::find($added->id))->toBeNull()
        ->and(Signature::find($out->id)?->signature_id)->toBe('ABC-123');
});

it('keeps the system someone else put back, instead of adding it twice', function () {
    $map = Map::factory()->create();
    [, $alpha] = undoChain($map);
    $user = undoUser($map);
    $token = (string) Str::uuid();

    actingAs($user)->delete(route('map-solarsystems.destroy', $alpha), ['undo_token' => $token])->assertRedirect();
    $again = placeMapSolarsystem($map, 30004102, 700, 100);

    actingAs($user)->post(route('map-undo.store', $token))->assertRedirect();

    expect(MapSolarsystem::query()->where('map_id', $map->id)->where('solarsystem_id', 30004102)->count())->toBe(1)
        ->and(MapSolarsystem::find($again->id))->not->toBeNull();
});

it('saves the current state under the redo token before putting things back', function () {
    $map = Map::factory()->create();
    [$home] = undoChain($map);
    $user = undoUser($map);
    $token = (string) Str::uuid();
    $redo = (string) Str::uuid();

    app(MapUndoSnapshots::class)->capture($map, $user->id, $token, signatureSystemIds: [$home->id], prune: true);

    actingAs($user)->post(route('map-undo.store', $token), ['redo_token' => $redo])->assertRedirect();

    expect(Cache::has('map-undo:'.$redo))->toBeTrue();
});

it('only the person who made the change can undo it', function () {
    $map = Map::factory()->create();
    [, $alpha] = undoChain($map);
    $owner = undoUser($map);
    $other = undoUser($map);
    $token = (string) Str::uuid();

    actingAs($owner)->delete(route('map-solarsystems.destroy', $alpha), ['undo_token' => $token])->assertRedirect();

    actingAs($other)->post(route('map-undo.store', $token))->assertSessionHasErrors('undo');

    expect(MapSolarsystem::find($alpha->id))->toBeNull();
});

it('a viewer cannot put changes back', function () {
    $map = Map::factory()->create();
    [$home] = undoChain($map);
    $viewer = undoUser($map, Permission::Viewer);
    $token = (string) Str::uuid();

    app(MapUndoSnapshots::class)->capture($map, $viewer->id, $token, [$home->id]);

    actingAs($viewer)->post(route('map-undo.store', $token))->assertForbidden();
});

it('an unknown or malformed token is refused', function () {
    $map = Map::factory()->create();
    $user = undoUser($map);

    actingAs($user)->post(route('map-undo.store', (string) Str::uuid()))->assertSessionHasErrors('undo');
    actingAs($user)->post('/map-undo/not-a-token')->assertNotFound();
});

it('deleting without a token saves nothing', function () {
    $map = Map::factory()->create();
    [, $alpha] = undoChain($map);
    $user = undoUser($map);

    actingAs($user)->delete(route('map-solarsystems.destroy', $alpha))->assertRedirect();

    expect(MapSolarsystem::find($alpha->id))->toBeNull();
});
