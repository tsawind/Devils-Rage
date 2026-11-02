<?php
declare(strict_types=1);
// Run: php devilsrage/seat/plugin/tests/pi-options-map-test.php (plain PHP, no SeAT needed).
require __DIR__.'/../src/Pi/PiRoutes.php';
require __DIR__.'/../src/Pi/PiPlanner.php';
require __DIR__.'/../src/Pi/PiOptions.php';
require __DIR__.'/../src/Pi/PiMap.php';
use DevilsRage\Seat\Pi\PiMap;
use DevilsRage\Seat\Pi\PiOptions;

$fails = 0;
function check(string $what, bool $ok): void { global $fails; echo ($ok ? "ok   " : "FAIL ") . $what . "\n"; if (!$ok) $fails++; }

// ---- Options: two lines like Tsa's 6 d 20 h planets (2 h cycles), 4 basic, 2 advanced.
$lines = [['qty' => 14658, 'cycle' => 7200, 'heads' => 6], ['qty' => 9000, 'cycle' => 7200, 'heads' => 6]];
$opts = PiOptions::forPlanet($lines, ['basic' => 4, 'advanced' => 2], 7200.0);
$by = array_column($opts, null, 'key');
check('five options', count($opts) === 5);
check('shorter restarts make more per hour', $by['daily']['raw'][0] > $by['two']['raw'][0] && $by['two']['raw'][0] > $by['four']['raw'][0] && $by['four']['raw'][0] > $by['weekly']['raw'][0]);
check('more P2 a week when restarted more often', $by['daily']['p2_week'] > $by['weekly']['p2_week']);
check('the smaller line sets the P2 pace', $by['weekly']['p2_week'] === (int) round($by['weekly']['raw'][1] / 6000 * 5 * 168));
check('ISK = P2 × value', abs($by['weekly']['isk_week'] - $by['weekly']['p2_week'] * 7200.0) < 1);
check('daily needs extra factories (power)', $by['daily']['extra_mw'] > 0 && $by['daily']['basics'] > 4);
check('logins per week', $by['weekly']['logins'] === 1.0 && $by['daily']['logins'] === 7.0);
check('a one-line planet has no P2 options', PiOptions::forPlanet([$lines[0]], ['basic' => 2, 'advanced' => 0], 7200.0) === []);

$total = array_column(PiOptions::total([$opts, $opts]), null, 'key');
check('totals add planets', abs($total['weekly']['isk_week'] - 2 * $by['weekly']['isk_week']) < 1 && $total['weekly']['planets'] === 2);
check('effort grows with logins', $total['daily']['effort_hours'] > $total['weekly']['effort_hours']);
check('extra ISK per hour only for the non-weekly', $total['weekly']['extra_isk_per_hour'] === null && $total['daily']['extra_isk_per_hour'] > 0);
check('no planets, no totals', PiOptions::total([]) === []);

// ---- Map: spots and pairs.
$now = 1_793_600_000;
$day = 86400;
$run = fn (int $c, int $p, float $lat, float $lon, int $qty, int $ago, int $heads = 6): array => ['character_id' => $c, 'product' => $p, 'product_name' => "P$p", 'heads' => $heads, 'lat' => $lat, 'lon' => $lon, 'qty' => $qty, 'cycle' => 7200, 'install' => $now - $ago * $day];
$runs = [
    $run(1, 100, 1.50, 2.00, 12000, 40),
    $run(2, 100, 1.51, 2.01, 6000, 10),   // same spot, worked down to about half
    $run(1, 100, 0.80, 4.00, 11000, 5),   // a second, fresh spot far away
    $run(1, 200, 1.52, 2.05, 9000, 8),    // the other resource next to the first spot
    $run(3, 200, 0.82, 4.02, 9500, 3),    // and next to the fresh one
    $run(1, 200, 1.0, 1.0, 0, 3),         // no numbers: ignored
];
$spots = PiMap::spots($runs);
check('four spots (one merged, one ignored)', count($spots) === 4);
$first = array_values(array_filter($spots, fn ($s) => $s['product'] === 100 && $s['runs'] === 2))[0] ?? null;
check('merged spot remembers both pilots', $first !== null && $first['characters'] === [1, 2]);
check('merged spot looks worked out', $first !== null && $first['worked'] > 0.3 && $first['latest'] < $first['best']);
check('spot follows the newest heads', $first !== null && $first['lat'] === 1.51);

$radius = 5000.0;
$pairs = PiMap::pairs($spots, 100, 200, $radius, 2000.0, $now);
check('two pairs in reach', count($pairs) === 2);
check('fresh, unworked pair first', $pairs[0]['a']['lat'] === 0.80 && $pairs[0]['b']['lat'] === 0.82);
check('pair distance is small', $pairs[0]['km'] < 200);
check('nothing in a tiny reach', PiMap::pairs($spots, 100, 200, $radius, 10.0, $now) === []);
check('quarter circle is a quarter of the circumference', abs(PiMap::distanceKm(M_PI / 2, 0, M_PI / 2, M_PI / 2, 1000.0) - M_PI / 2 * 1000) < 0.01);
check('pole to pole', abs(PiMap::distanceKm(0, 0, M_PI, 1, 1000.0) - M_PI * 1000) < 0.01);
check('freshness halves in 30 days', abs(PiMap::freshness($now - 30 * $day, $now) - 0.5) < 1e-9);
check('seam: longitudes 0.01 and 2π-0.01 are one spot', count(PiMap::spots([$run(1, 5, 1.0, 0.01, 9000, 1), $run(1, 5, 1.0, 2 * M_PI - 0.01, 9000, 0)])) === 1);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
