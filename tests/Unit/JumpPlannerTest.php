<?php

declare(strict_types=1);

use App\Support\JumpPlanner;

it('patch 37: jump range grows 20% per Jump Drive Calibration level', function (float $base, int $jdc, float $expected) {
    expect(JumpPlanner::range($base, $jdc))->toBe($expected);
})->with([
    'carrier JDC IV' => [JumpPlanner::CARRIER_BASE_LY, 4, 6.3],
    'carrier JDC V' => [JumpPlanner::CARRIER_BASE_LY, 5, 7.0],
    'BLOPS JDC IV' => [JumpPlanner::BLOPS_BASE_LY, 4, 7.2],
    'BLOPS JDC V' => [JumpPlanner::BLOPS_BASE_LY, 5, 8.0],
]);

it('patch 37: a conduit jump takes 5 ships plus 5 per Capital Jump Portal Generation level', function () {
    expect(JumpPlanner::conduitShips(0))->toBe(5)
        ->and(JumpPlanner::conduitShips(3))->toBe(20)
        ->and(JumpPlanner::conduitShips(4))->toBe(25)
        ->and(JumpPlanner::conduitShips(5))->toBe(30);
});

it('patch 37: a command carrier takes 20% more per racial Carrier level', function () {
    expect(JumpPlanner::conduitShips(5, true, 5))->toBe(60)
        ->and(JumpPlanner::conduitShips(4, true, 4))->toBe(45);
});

it('patch 37: no cyno in highsec, wormhole space, Pochven or Zarzakh', function () {
    expect(JumpPlanner::canLightCyno(['id' => 30002053, 'security' => 0.4, 'region_id' => 10000042]))->toBeTrue()
        ->and(JumpPlanner::canLightCyno(['id' => 30000142, 'security' => 0.95, 'region_id' => 10000002]))->toBeFalse()
        ->and(JumpPlanner::canLightCyno(['id' => 30000001, 'security' => 0.46, 'region_id' => 10000001]))->toBeFalse()
        ->and(JumpPlanner::canLightCyno(['id' => 31000005, 'security' => -1.0, 'region_id' => 11000001]))->toBeFalse()
        ->and(JumpPlanner::canLightCyno(['id' => 30000021, 'security' => -1.0, 'region_id' => 10000070]))->toBeFalse()
        ->and(JumpPlanner::canLightCyno(['id' => 30100000, 'security' => -1.0, 'region_id' => 10001000]))->toBeFalse();
});

it('patch 37: finds the nearest usable system by gates', function () {
    $ly = JumpPlanner::METERS_PER_LIGHTYEAR;
    $systems = [
        1 => ['id' => 1, 'name' => 'High', 'security' => 0.9, 'region_id' => 1, 'x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        2 => ['id' => 2, 'name' => 'Mid', 'security' => 0.6, 'region_id' => 1, 'x' => $ly, 'y' => 0.0, 'z' => 0.0],
        3 => ['id' => 3, 'name' => 'Low', 'security' => 0.3, 'region_id' => 1, 'x' => 2 * $ly, 'y' => 0.0, 'z' => 0.0],
    ];
    $gates = [1 => [2], 2 => [1, 3], 3 => [2]];

    expect(JumpPlanner::nearest(1, $systems, $gates, fn (array $system): bool => ! JumpPlanner::isHighsec($system['security'])))
        ->toBe(['id' => 3, 'name' => 'Low', 'gates' => 2])
        ->and(round(JumpPlanner::lightyears($systems[1], $systems[3]), 1))->toBe(2.0);
});
