<?php

declare(strict_types=1);

use App\Support\HoleExpiry;
use Carbon\CarbonImmutable;

$found = CarbonImmutable::parse('2026-10-11 00:00:00');

it('lets a hole live its type plus 3 h', function (?int $hours, string $leaves) use ($found) {
    expect(HoleExpiry::deadline($found, $hours === null ? null : $hours * 3600)->format('Y-m-d H:i'))->toBe($leaves);
})->with([
    '16 h hole' => [16, '2026-10-11 19:00'],
    '24 h hole' => [24, '2026-10-12 03:00'],
    '48 h hole' => [48, '2026-10-13 03:00'],
    'type not known' => [null, '2026-10-12 03:00'],
]);

it('goes 5½ h after the first EOL mark and 2 h after the first EOL! mark, whichever is first', function () use ($found) {
    $eol = $found->addHours(10);

    expect(HoleExpiry::deadline($found, 24 * 3600, $eol)->format('H:i'))->toBe('15:30')
        ->and(HoleExpiry::deadline($found, 24 * 3600, $eol, $found->addHours(11))->format('H:i'))->toBe('13:00')
        // A late mark never keeps a hole past its life.
        ->and(HoleExpiry::deadline($found, 16 * 3600, $found->addHours(18))->format('H:i'))->toBe('19:00');
});

it('picks the earliest of several marks', function () use ($found) {
    expect(HoleExpiry::earliest(null, $found->addHour(), $found))->toEqual($found)
        ->and(HoleExpiry::earliest(null, null))->toBeNull();
});

it('patch 37: an imminent hole leaves the map an hour after it turned imminent', function () {
    $created = CarbonImmutable::parse('2026-10-11 10:00');
    $imminent = CarbonImmutable::parse('2026-10-11 20:00');

    expect(HoleExpiry::deadline($created, 86_400, null, null, $imminent)->toDateTimeString())->toBe('2026-10-11 21:00:00');
});
