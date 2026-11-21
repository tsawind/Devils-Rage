<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\EsiScope;
use App\Models\EsiToken;
use App\Support\PilotJumpFatigue;
use NicolasKion\Esi\DTO\EsiResult;
use NicolasKion\Esi\DTO\JumpFatigue;
use NicolasKion\Esi\Enums\EsiScope as EsiScopeName;
use NicolasKion\Esi\Esi;

/**
 * 20 Nov: jump fatigue for caps and black ops pilots, read with the Jump fatigue permission.
 */
function fatiguePilot(bool $granted = true): Character
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
        $token->esiScopes()->attach(EsiScope::query()->firstOrCreate(['name' => EsiScopeName::ReadFatigue->value])->id);
    }

    return $character;
}

function esiFatigue(?string $ends, ?string $lastJump): void
{
    $esi = Mockery::mock(Esi::class);
    $esi->shouldReceive('getCharacterFatigue')->andReturn(new EsiResult(data: new JumpFatigue($ends, $lastJump, $lastJump)));
    app()->instance(Esi::class, $esi);
}

it('reads how long the fatigue lasts and when the pilot last jumped', function (): void {
    $character = fatiguePilot();
    esiFatigue(now()->addMinutes(90)->toIso8601ZuluString(), now()->subMinutes(5)->toIso8601ZuluString());

    $fatigue = app(PilotJumpFatigue::class)->of($character);

    expect($fatigue)->not->toBeNull()
        ->and(Illuminate\Support\Carbon::parse($fatigue['ends'])->diffInMinutes(now(), true))->toBeGreaterThan(85)
        ->and($fatigue['last_jump'])->not->toBeNull();
});

it('says no fatigue once it has run out', function (): void {
    $character = fatiguePilot();
    esiFatigue(now()->subMinutes(3)->toIso8601ZuluString(), now()->subHours(2)->toIso8601ZuluString());

    expect(app(PilotJumpFatigue::class)->of($character))->toMatchArray(['ends' => null]);
});

it('says no fatigue when the pilot never jumped', function (): void {
    $character = fatiguePilot();
    esiFatigue(null, null);

    expect(app(PilotJumpFatigue::class)->of($character))->toBe(['ends' => null, 'last_jump' => null]);
});

it('reads nothing without the permission', function (): void {
    $character = fatiguePilot(granted: false);
    $esi = Mockery::mock(Esi::class);
    $esi->shouldNotReceive('getCharacterFatigue');
    app()->instance(Esi::class, $esi);

    expect(app(PilotJumpFatigue::class)->of($character))->toBeNull();
});
