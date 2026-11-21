<?php

declare(strict_types=1);

use App\Jobs\Characters\ResolveStructureName;
use App\Models\Character;
use App\Models\EsiScope;
use App\Models\EsiToken;
use App\Models\StructureName;
use Illuminate\Support\Facades\Queue;
use NicolasKion\Esi\DTO\EsiResult;
use NicolasKion\Esi\DTO\Position;
use NicolasKion\Esi\DTO\Structure;
use NicolasKion\Esi\Enums\EsiScope as EsiScopeName;
use NicolasKion\Esi\Esi;

/**
 * 20 Nov: "Docked in J123 - Home Fort" instead of a structure number.
 */
function structurePilot(bool $granted = true): Character
{
    $character = Character::factory()->create();
    $token = EsiToken::query()->create([
        'character_id' => $character->id,
        'character_owner_hash' => 'hash',
        'access_token' => 'access',
        'refresh_token' => 'refresh',
        'expires_at' => now()->addMinutes(20),
    ]);
    if ($granted) {
        $token->esiScopes()->attach(EsiScope::query()->firstOrCreate(['name' => EsiScopeName::ReadStructures->value])->id);
    }

    return $character;
}

it('reads a citadel name from EVE and keeps it', function (): void {
    $character = structurePilot();
    $esi = Mockery::mock(Esi::class);
    $esi->shouldReceive('getStructure')->once()->andReturn(new EsiResult(data: new Structure('J123 - Home Fort', 98000001, new Position(1.0, 2.0, 3.0), 31000001, 35833)));
    app()->instance(Esi::class, $esi);

    app()->call([new ResolveStructureName((int) $character->id, 1_035_000_000_001), 'handle']);

    expect(StructureName::nameOf(1_035_000_000_001))->toBe('J123 - Home Fort')
        ->and(StructureName::query()->find(1_035_000_000_001)?->solarsystem_id)->toBe(31000001);
});

it('asks once per structure, only with the permission, only while the name is unknown', function (): void {
    Queue::fake();
    $granted = structurePilot();
    $notGranted = structurePilot(granted: false);

    ResolveStructureName::askFor($notGranted, 1_035_000_000_002);
    Queue::assertNothingPushed();

    ResolveStructureName::askFor($granted, 1_035_000_000_002);
    ResolveStructureName::askFor($granted, 1_035_000_000_002);
    Queue::assertPushed(ResolveStructureName::class, 1);

    StructureName::query()->create(['id' => 1_035_000_000_003, 'name' => 'Known Keepstar']);
    ResolveStructureName::askFor($granted, 1_035_000_000_003);
    Queue::assertPushed(ResolveStructureName::class, 1);
});

it('keeps nothing when EVE refuses (the pilot cannot dock there)', function (): void {
    $character = structurePilot();
    $esi = Mockery::mock(Esi::class);
    $esi->shouldReceive('getStructure')->andReturn(new EsiResult(error: new NicolasKion\Esi\DTO\EsiError(403, 'Forbidden')));
    app()->instance(Esi::class, $esi);

    app()->call([new ResolveStructureName((int) $character->id, 1_035_000_000_004), 'handle']);

    expect(StructureName::nameOf(1_035_000_000_004))->toBeNull();
});
