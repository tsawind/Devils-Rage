<?php
declare(strict_types=1);
// Run: php devilsrage/seat/plugin/tests/pi-money-test.php (plain PHP, no SeAT needed).
require __DIR__.'/../src/Pi/PiPlanner.php';
require __DIR__.'/../src/Pi/PiMoney.php';
use DevilsRage\Seat\Pi\PiMoney;

$fails = 0;
function check(string $what, bool $ok): void { global $fails; echo ($ok ? "ok   " : "FAIL ") . $what . "\n"; if (!$ok) $fails++; }
$isk = fn (float $v): string => number_format($v / 1e6, 1).' m';

// 480 Mechanical Parts and 300 Consumer Electronics a day: 30 cycles (CE runs out), 90 Robotics.
$prices = ['Robotics' => 80_000.0, 'Mechanical Parts' => 9_000.0, 'Consumer Electronics' => 10_000.0, 'Condensates' => 1_000.0, 'Oxides' => 9_000.0, 'Coolant' => 9_000.0];
$up = PiMoney::upgrades(['Mechanical Parts' => 480, 'Consumer Electronics' => 300, 'Viral Agent' => 900], $prices);
check('only recipes we can feed', count($up) === 1 && $up[0]['p3'] === 'Robotics');
check('90 Robotics a day from 30 cycles', $up[0]['per_day'] === 90);
check('gain = 90 x 80k - 300 x (9k + 10k)', abs($up[0]['gain_per_day'] - (90 * 80_000 - 300 * 19_000)) < 0.01);
check('two factories for 30 cycles a day', $up[0]['factories'] === 2);
// A P3 worth less than its P2 is never suggested.
check('losing recipe left out', PiMoney::upgrades(['Oxides' => 500, 'Coolant' => 500], $prices) === []);
// With 10% tax: P3 export tax, P2 import at half, the P2 export no longer paid.
$taxed = PiMoney::upgrades(['Mechanical Parts' => 300, 'Consumer Electronics' => 300], $prices, 0.10)[0];
$tax = 90 * 60000 * 0.10 + 2 * (300 * 7200 * 0.10) / 2 - 2 * 300 * 7200 * 0.10;
check('customs tax both ways', abs($taxed['gain_per_day'] - (90 * 80_000 - 300 * 19_000 - $tax)) < 0.01);
check('every recipe input is a known name', in_array('Robotics', PiMoney::recipeNames(), true) && in_array('Water-Cooled CPU', PiMoney::recipeNames(), true));

// The list: biggest month first, stock compared by its ISK now; empty items left out.
$list = PiMoney::suggestions([
    'stopped' => ['count' => 2, 'per_day' => 1e6],
    'short' => ['count' => 3, 'per_day' => 5e6, 'planets' => [['name' => 'A · J1 I', 'per_day' => 2e6]]],
    'hub' => ['name' => 'Amarr', 'per_day' => 1e5, 'share' => 0.005],
    'stock' => ['value' => 100e6, 'm3' => 5000.0],
    'upgrade' => null,
    'grow' => ['planets' => 0, 'per_day' => 0.0, 'characters' => 0],
], $isk);
check('feed first (150m/month), then stock (100m), then restart (30m)', array_column($list, 'kind') === ['feed', 'stock', 'restart']);
check('hub under 1% left out', ! in_array('hub', array_column($list, 'kind'), true));
check('restart counts a month', abs($list[2]['per_month'] - 30e6) < 0.01);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
