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

it('patch 37: a profile logs the lightest mass of the kind (never more than really went through)', function () {
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

    expect($profile->jumpMass(false))->toBe(1_073_000)
        ->and($profile->jumpMass(true))->toBe(26_480_000)
        ->and($plain->jumpMass(true))->toBe(303_000_000);
});
