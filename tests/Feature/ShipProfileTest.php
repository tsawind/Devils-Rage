<?php

declare(strict_types=1);

use App\Models\ShipProfile;
use App\Support\ShipMass;

it('patch 37: each active entangler takes 80% off, stacking penalized (Devoter with a Higgs: 32.4m → 6.48m → 1.97m → 1.07m)', function () {
    $cold = 32_400_000;

    expect((int) round($cold * ShipMass::entanglerFactor(1)))->toBe(6_480_000)
        ->and(round($cold * ShipMass::entanglerFactor(2) / 1_000_000, 2))->toBe(1.97)
        ->and(round($cold * ShipMass::entanglerFactor(3) / 1_000_000, 2))->toBe(1.07)
        ->and(round(132_400_000 * ShipMass::entanglerFactor(1) / 1_000_000, 2))->toBe(26.48);
});

it('patch 37: Armor Layering 5% and Advanced Armor Layering 2% per level lighten plates', function () {
    expect(ShipMass::plateMass(100.0, 5, 5))->toEqualWithDelta(67.5, 0.0001)
        ->and(ShipMass::plateMass(100.0, 0, 0))->toBe(100.0);
});

it('patch 37: props are named short', function () {
    expect(ShipMass::propLabel('10000MN Monopropellant Enduring Afterburner'))->toBe('10000MN AB')
        ->and(ShipMass::propLabel('50000MN Microwarpdrive II'))->toBe('50000MN MWD');
});

it('patch 37: a profile logs hot or cold, entanglers only with the thread switch on', function () {
    $profile = new ShipProfile([
        'cold_mass' => 32_400_000,
        'hot_mass' => 132_400_000,
        'options' => [
            ['key' => 'hot0', 'label' => 'HOT', 'mode' => 'hot', 'mass' => 132_400_000],
            ['key' => 'cold', 'label' => 'COLD', 'mode' => 'cold', 'mass' => 32_400_000],
            ['key' => 'hot0-e1', 'label' => 'HOT · 1 entangler', 'mode' => 'hot', 'mass' => 26_480_000],
            ['key' => 'cold-e3', 'label' => 'COLD · 3 entanglers', 'mode' => 'cold', 'mass' => 1_073_000],
        ],
    ]);
    $plain = new ShipProfile(['cold_mass' => 203_000_000, 'hot_mass' => 303_000_000]);

    expect($profile->jumpMass(false))->toBe(32_400_000)
        ->and($profile->jumpMass(true))->toBe(132_400_000)
        ->and($profile->jumpMass(false, true))->toBe(1_073_000)
        ->and($profile->jumpMass(true, true))->toBe(26_480_000)
        ->and($plain->jumpMass(true))->toBe(303_000_000);

    $carrier = new ShipProfile([
        'cold_mass' => 1_340_000_000,
        'hot_mass' => 1_840_000_000,
        'options' => [
            ['key' => 'hot0', 'label' => 'HOT 50000MN MWD', 'mode' => 'hot', 'mass' => 1_840_000_000],
            ['key' => 'hot1', 'label' => 'HOT 500MN MWD', 'mode' => 'hot', 'mass' => 1_390_000_000],
            ['key' => 'cold', 'label' => 'COLD', 'mode' => 'cold', 'mass' => 1_340_000_000],
        ],
    ]);
    expect($carrier->jumpMass(true))->toBe(1_840_000_000);
});

it('patch 37: props of one size are one option (50000MN MWD and 10000MN AB add the same mass)', function () {
    App\Models\Category::query()->firstOrCreate(['id' => 6], ['name' => 'Ship']);
    App\Models\Group::query()->firstOrCreate(['id' => 547], ['name' => 'Carrier', 'category_id' => 6]);
    App\Models\Type::query()->create(['id' => 23757, 'name' => 'Archon', 'group_id' => 547, 'mass' => 1_340_000_000]);

    $options = ShipMass::options(23757, false, 0.0, [
        ['name' => '50000MN Microwarpdrive II', 'mass' => 500_000_000],
        ['name' => '10000MN Monopropellant Enduring Afterburner', 'mass' => 500_000_000],
        ['name' => '500MN Microwarpdrive II', 'mass' => 50_000_000],
    ], 0);

    expect(array_column($options, 'label'))->toBe(['HOT 50000MN MWD / 10000MN AB', 'HOT 500MN MWD', 'COLD'])
        ->and(array_column($options, 'mass'))->toBe([1_840_000_000, 1_390_000_000, 1_340_000_000]);
});

it('patch 37: a capital typed in with hot, semi and cold (plates off noted)', function () {
    App\Models\Category::query()->firstOrCreate(['id' => 6], ['name' => 'Ship']);
    App\Models\Group::query()->firstOrCreate(['id' => 547], ['name' => 'Carrier', 'category_id' => 6]);
    App\Models\Type::query()->create(['id' => 23757, 'name' => 'Archon', 'group_id' => 547, 'mass' => 1_340_000_000]);
    $map = App\Models\Map::factory()->create();
    $user = App\Models\User::factory()
        ->has(App\Models\Character::factory()->has(App\Models\MapAccess::factory(['permission' => App\Enums\Permission::Member])->for($map)))
        ->create();

    Pest\Laravel\actingAs($user)->putJson(route('maps.ship-profiles.update', $map), [
        'character_id' => 77,
        'ship_type_id' => 23757,
        'cold_mass' => 1_200_000_000,
        'semi_mass' => 1_250_000_000,
        'hot_mass' => 1_700_000_000,
        'plates_off' => true,
    ])->assertOk();

    $profile = ShipProfile::query()->where('character_id', 77)->firstOrFail();
    expect(array_column($profile->options ?? [], 'label'))->toBe(['HOT', 'SEMI', 'COLD'])
        ->and($profile->jumpMass(true))->toBe(1_700_000_000)
        ->and($profile->note)->toBe('plates off')
        ->and($profile->source)->toBe('manual');
});

it('patch 37: a ship with no prop gets one button in the jump log, not HOT and COLD at the same mass', function () {
    $dread = new ShipProfile(['cold_mass' => 1_260_000_000, 'hot_mass' => 1_260_000_000, 'options' => [
        ['key' => 'hot0', 'label' => 'HOT', 'mode' => 'hot', 'mass' => 1_260_000_000],
        ['key' => 'cold', 'label' => 'COLD', 'mode' => 'cold', 'mass' => 1_260_000_000],
    ]]);

    expect(array_column(App\Support\JumpOptions::fromProfile($dread), 'kind'))->toBe(['cold']);
});

it('patch 53: a fit read from one ship is not used for another ship of the same type', function () {
    $profile = new ShipProfile(['cold_mass' => 203_000_000, 'hot_mass' => 303_000_000, 'ship_item_id' => 1_000_000_001]);
    $anyShip = new ShipProfile(['cold_mass' => 203_000_000, 'hot_mass' => 303_000_000]);

    expect($profile->fitsShip(1_000_000_001))->toBeTrue()
        ->and($profile->fitsShip(1_000_000_002))->toBeFalse()
        ->and($profile->fitsShip(null))->toBeTrue()
        ->and($anyShip->fitsShip(1_000_000_002))->toBeTrue();
});
