<?php

declare(strict_types=1);

use App\Models\Killmail;

use function Pest\Laravel\artisan;

it('keeps wormhole kills 100 days and known-space kills a week', function () {
    $jita = makeSolarsystem(30000142);
    $wormhole = makeSolarsystem(31003990, -1.0, 'wormhole');
    $make = fn (int $id, int $system, int $daysAgo) => Killmail::query()->create([
        'id' => $id,
        'hash' => fake()->sha1(),
        'solarsystem_id' => $system,
        'time' => now()->subDays($daysAgo),
        'data' => ['killmail_id' => $id, 'solar_system_id' => $system, 'victim' => ['ship_type_id' => 587], 'attackers' => []],
        'zkb' => ['totalValue' => 1_000_000],
    ]);

    $make(1, $jita, 2);
    $make(2, $jita, 10);
    $make(3, $wormhole, 10);
    $make(4, $wormhole, 120);

    artisan('app:purge-old-killmails')->assertSuccessful();

    expect(Killmail::query()->orderBy('id')->pluck('id')->all())->toBe([1, 3]);
});
