<?php

declare(strict_types=1);

use App\Models\ShipProfile;
use App\Models\Type;
use App\Support\ShipMass;

it('patch 37: a Higgs doubles hull, plates and prop', function () {
    $type = Type::query()->first();
    if (! $type instanceof Type) {
        $this->markTestSkipped('No types seeded.');
    }
    $hull = Type::loggedJumpMass($type->id);

    $plain = ShipMass::fromFit($type->id, false, 0.0, 50_000_000.0);
    $higgs = ShipMass::fromFit($type->id, true, 0.0, 50_000_000.0);

    expect($plain)->toBe(['cold' => $hull, 'hot' => $hull + 50_000_000])
        ->and($higgs)->toBe(['cold' => $hull * 2, 'hot' => $hull * 2 + 100_000_000]);
});

it('patch 37: a profile logs its hot or cold mass', function () {
    $profile = new ShipProfile(['cold_mass' => 203_000_000, 'hot_mass' => 303_000_000]);

    expect($profile->jumpMass(false))->toBe(203_000_000)
        ->and($profile->jumpMass(true))->toBe(303_000_000);
});
