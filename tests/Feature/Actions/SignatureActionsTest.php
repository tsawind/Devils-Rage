<?php

declare(strict_types=1);

use App\Actions\DeleteSignaturesAction;
use App\Actions\Signatures\DeleteSignatureAction;
use App\Actions\Signatures\PasteSignaturesAction;
use App\Actions\Signatures\StoreSignatureAction;
use App\Actions\Signatures\UpdateSignatureAction;
use App\Data\NewSignatureData;
use App\Data\SignatureData;
use App\Data\SignaturesData;
use App\Enums\ShipSize;
use App\Enums\SignatureCategory as SignatureCategoryEnum;
use App\Enums\WormholeSignature;
use App\Models\Map;
use App\Models\MapConnection;
use App\Models\Signature;
use App\Models\SignatureCategory;
use App\Models\SignatureType;
use App\Models\Wormhole;
use App\Models\WormholeStatic;
use App\Models\WormholeSystem;
use Illuminate\Validation\ValidationException;

it('stores a signature on a system', function () {
    $map = Map::factory()->create();
    $system = placeMapSolarsystem($map, 30011001);

    $signature = app(StoreSignatureAction::class)->handle($system, NewSignatureData::from(['signature_id' => 'ABC-123']));

    expect($signature->signature_id)->toBe('ABC-123')
        ->and($signature->map_solarsystem_id)->toBe($system->id);
});

it('updates a signature', function () {
    $map = Map::factory()->create();
    $system = placeMapSolarsystem($map, 30011002);
    $signature = $system->signatures()->create(['signature_id' => 'ABC-123']);

    app(UpdateSignatureAction::class)->handle($signature, SignatureData::from(['signature_id' => 'XYZ-999']));

    expect($signature->fresh()->signature_id)->toBe('XYZ-999');
});

it('resets the wormhole_id when the signature type is cleared to unknown', function () {
    $map = Map::factory()->create();
    $system = placeMapSolarsystem($map, 30011016);
    $wormhole = Wormhole::create([
        'name' => WormholeSignature::X877->value,
        'total_mass' => 3_300_000_000,
        'maximum_jump_mass' => 375_000_000,
        'maximum_lifetime' => 86_400,
        'leads_to' => 'c4',
    ]);
    $signature_type = SignatureType::query()->where('signature', WormholeSignature::X877)->firstOrFail();
    $signature = $system->signatures()->create([
        'signature_id' => 'ABC-123',
        'signature_type_id' => $signature_type->id,
        'wormhole_id' => $wormhole->id,
    ]);

    app(UpdateSignatureAction::class)->handle($signature, SignatureData::from(['signature_type_id' => null]));

    expect($signature->fresh())
        ->signature_type_id->toBeNull()
        ->wormhole_id->toBeNull();
});

it('deletes a signature', function () {
    $map = Map::factory()->create();
    $system = placeMapSolarsystem($map, 30011003);
    $signature = $system->signatures()->create(['signature_id' => 'ABC-123']);

    app(DeleteSignatureAction::class)->handle($signature);

    expect(Signature::find($signature->id))->toBeNull();
});

it('deletes multiple signatures from a system', function () {
    $map = Map::factory()->create();
    $system = placeMapSolarsystem($map, 30011004);
    $first = $system->signatures()->create(['signature_id' => 'AAA-111']);
    $second = $system->signatures()->create(['signature_id' => 'BBB-222']);

    app(DeleteSignaturesAction::class)->handle($system, [$first->id, $second->id]);

    expect($system->signatures()->count())->toBe(0);
});

it('pastes new signatures onto a system', function () {
    $map = Map::factory()->create();
    $system = placeMapSolarsystem($map, 30011005);

    app(PasteSignaturesAction::class)->handle(SignaturesData::from([
        'map_solarsystem_id' => $system->id,
        'signatures' => [
            ['signature_id' => 'AAA-111'],
            ['signature_id' => 'BBB-222'],
        ],
    ]));

    expect($system->signatures()->count())->toBe(2);
});

it('syncs the connection ship size from the signature wormhole type', function () {
    $map = Map::factory()->create();
    $origin = placeMapSolarsystem($map, 30011010);
    $target = placeMapSolarsystem($map, 30011011, 300, 300);
    $connection = MapConnection::create([
        'map_id' => $map->id,
        'from_map_solarsystem_id' => $origin->id,
        'to_map_solarsystem_id' => $target->id,
        'ship_size' => 'large',
        'lifetime' => 'healthy',
        'mass_status' => 'fresh',
    ]);
    $wormhole = makeWormhole();
    $signature = $origin->signatures()->create([
        'signature_id' => 'ABC-123',
        'map_connection_id' => $connection->id,
        'wormhole_id' => $wormhole->id,
        'lifetime' => 'healthy',
    ]);

    app(UpdateSignatureAction::class)->handle($signature, SignatureData::from(['signature_id' => 'ABC-123']));

    expect($connection->fresh()->ship_size)->toBe(ShipSize::ExtraLarge);
});

it('syncs the connection ship size when pasting over a typed connected signature', function () {
    $map = Map::factory()->create();
    $origin = placeMapSolarsystem($map, 30011012);
    $target = placeMapSolarsystem($map, 30011013, 300, 300);
    $connection = MapConnection::create([
        'map_id' => $map->id,
        'from_map_solarsystem_id' => $origin->id,
        'to_map_solarsystem_id' => $target->id,
        'ship_size' => 'large',
        'lifetime' => 'healthy',
        'mass_status' => 'fresh',
    ]);
    $wormhole = makeWormhole('X877', 375_000_000, 'c4');
    $signature_type = App\Models\SignatureType::query()->where('signature', 'X877')->firstOrFail();
    $origin->signatures()->create([
        'signature_id' => 'AAA-111',
        'map_connection_id' => $connection->id,
        'signature_type_id' => $signature_type->id,
        'wormhole_id' => $wormhole->id,
        'lifetime' => 'healthy',
    ]);
    $connection->update(['ship_size' => 'xlarge']);

    app(PasteSignaturesAction::class)->handle(SignaturesData::from([
        'map_solarsystem_id' => $origin->id,
        'signatures' => [
            ['signature_id' => 'AAA-111'],
        ],
    ]));

    expect($connection->fresh()->ship_size)->toBe(ShipSize::Large);
});

it('syncs the connection ship size when storing an already-connected typed signature', function () {
    $map = Map::factory()->create();
    $origin = placeMapSolarsystem($map, 30011014);
    $target = placeMapSolarsystem($map, 30011015, 300, 300);
    $connection = MapConnection::create([
        'map_id' => $map->id,
        'from_map_solarsystem_id' => $origin->id,
        'to_map_solarsystem_id' => $target->id,
        'ship_size' => 'xlarge',
        'lifetime' => 'healthy',
        'mass_status' => 'fresh',
    ]);
    makeWormhole('X877', 375_000_000, 'c4');
    $signature_type = App\Models\SignatureType::query()->where('signature', 'X877')->firstOrFail();

    app(StoreSignatureAction::class)->handle($origin, NewSignatureData::from([
        'signature_id' => 'NEW-001',
        'signature_type_id' => $signature_type->id,
        'map_connection_id' => $connection->id,
    ]));

    expect($connection->fresh()->ship_size)->toBe(ShipSize::Large);
});

it('patch 23: an unidentified signature that pastes as a combat site becomes a scannable combat site', function () {
    $combat = SignatureCategory::query()->firstWhere('code', SignatureCategoryEnum::Combat);
    $scannable = SignatureCategory::query()->firstWhere('code', SignatureCategoryEnum::ScannableCombat);
    $map = Map::factory()->create();
    $system = placeMapSolarsystem($map, 30011007);
    $paste = fn (array $signatures) => app(PasteSignaturesAction::class)->handle(SignaturesData::from([
        'map_solarsystem_id' => $system->id,
        'signatures' => $signatures,
    ]));

    $paste([['signature_id' => 'QGP-880'], ['signature_id' => 'ANO-100', 'signature_category_id' => $combat->id]]);
    $paste([
        ['signature_id' => 'QGP-880', 'signature_category_id' => $combat->id],
        ['signature_id' => 'ANO-100', 'signature_category_id' => $combat->id],
    ]);

    expect($scannable)->not->toBeNull()
        ->and($system->signatures()->firstWhere('signature_id', 'QGP-880')->signature_category_id)->toBe($scannable->id)
        ->and($system->signatures()->firstWhere('signature_id', 'ANO-100')->signature_category_id)->toBe($combat->id);

    $paste([['signature_id' => 'QGP-880', 'signature_category_id' => $combat->id]]);

    expect($system->signatures()->firstWhere('signature_id', 'QGP-880')->signature_category_id)->toBe($scannable->id);
});

it('patch 27: a system with two statics can have both marked, but not two of the same type', function () {
    $map = Map::factory()->create();
    $system = placeMapSolarsystem($map, 31000901);
    $wormholeSystem = WormholeSystem::query()->create(['id' => 31000901]);
    $c247 = Wormhole::create(['name' => 'C247', 'total_mass' => 2_000_000_000, 'maximum_jump_mass' => 375_000_000, 'maximum_lifetime' => 57_600, 'leads_to' => 'c3']);
    $h900 = Wormhole::create(['name' => 'H900', 'total_mass' => 3_000_000_000, 'maximum_jump_mass' => 375_000_000, 'maximum_lifetime' => 86_400, 'leads_to' => 'c5']);
    WormholeStatic::query()->create(['wormhole_system_id' => $wormholeSystem->id, 'wormhole_id' => $c247->id]);
    WormholeStatic::query()->create(['wormhole_system_id' => $wormholeSystem->id, 'wormhole_id' => $h900->id]);

    $system->signatures()->create(['signature_id' => 'HWB-996', 'wormhole_id' => $h900->id, 'is_static' => true]);
    $wrk = $system->signatures()->create(['signature_id' => 'WRK-992', 'wormhole_id' => $c247->id]);
    $other = $system->signatures()->create(['signature_id' => 'ABC-123', 'wormhole_id' => $c247->id]);

    app(UpdateSignatureAction::class)->handle($wrk, SignatureData::from(['is_static' => true]));
    expect($wrk->fresh()->is_static)->toBeTrue();

    expect(fn () => app(UpdateSignatureAction::class)->handle($other, SignatureData::from(['is_static' => true])))
        ->toThrow(ValidationException::class);
});

it('patch 27: a system with one static still allows only one hole marked static', function () {
    $map = Map::factory()->create();
    $system = placeMapSolarsystem($map, 31000902);
    $system->signatures()->create(['signature_id' => 'AAA-111', 'is_static' => true]);
    $second = $system->signatures()->create(['signature_id' => 'BBB-222']);

    expect(fn () => app(UpdateSignatureAction::class)->handle($second, SignatureData::from(['is_static' => true])))
        ->toThrow(ValidationException::class);
});
