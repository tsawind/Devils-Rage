<?php
declare(strict_types=1);
// Run: php devilsrage/seat/plugin/tests/pi-layout-test.php (plain PHP, no SeAT needed).
require __DIR__.'/../src/Pi/PiScan.php';
require __DIR__.'/../src/Pi/PiPlanner.php';
require __DIR__.'/../src/Pi/PiTemplate.php';
require __DIR__.'/../src/Pi/PiLayout.php';
use DevilsRage\Seat\Pi\PiLayout;
use DevilsRage\Seat\Pi\PiScan;
use DevilsRage\Seat\Pi\PiTemplate;

$fails = 0;
function check(string $what, bool $ok): void { global $fails; echo ($ok ? "ok   " : "FAIL ") . $what . "\n"; if (!$ok) $fails++; }

$barren = ['ecu' => 2848, 'launchpad' => 2544, 'storage' => 2541, 'basic' => 2473];
$make = fn (float $radius, float $lat = 1.0, float $lon = 3.2) => PiLayout::p1(['planet_type_id' => 2016, 'radius_km' => $radius, 'hotspot' => [$lat, $lon], 'toward' => 0.7, 'types' => $barren, 'p0' => 2073, 'p1' => 2393]);

$i = $make(2820);
echo "     J145735 I (2,820 km): {$i['factories']} factories, ring {$i['ring_km']} km, power {$i['power']}, CPU {$i['cpu']}\n";
check('fits a command center 5', $i['power'] <= 19000 && $i['cpu'] <= 25415);
check('as many factories as fit: one more does not', (function () use ($i) { $more = PiLayout::load($i['factories'] + 1, PiLayout::ringKm($i['factories'] + 2), 10); return $more[0] > 19000 || $more[1] > 25415; })());
check('11 basic factories, like Tsawind\'s J145735 I', $i['factories'] === 11);
$parsed = PiTemplate::parse(json_encode($i['template']));
check('EVE template checks out', $parsed['CmdCtrLv'] === 5 && count($parsed['P']) === 3 + $i['factories']);
$ecu = $i['template']['P'][2];
check('extractor on the hotspot, 10 heads, pulls the raw', abs($ecu['La'] - 1.0) < 1e-4 && abs($ecu['Lo'] - 3.2) < 1e-4 && $ecu['H'] === 10 && $ecu['S'] === 2073);
check('planet diameter in the template', $i['template']['Diam'] === 5640.0);
$closest = INF;
$pins = $i['template']['P'];
foreach ($pins as $a => $p) { foreach ($pins as $b => $q) { if ($a < $b) { $closest = min($closest, PiLayout::distance($p['La'], $p['Lo'], $q['La'], $q['Lo']) * 2820); } } }
check('buildings at least 29 km apart (closest '.round($closest, 1).' km)', $closest >= 29);
check('every factory linked to the launchpad', count(array_filter($i['template']['L'], fn ($l) => $l['S'] === 1)) === $i['factories'] + 1);
check('every factory fed raw and sends P1 to the launchpad', count(array_filter($i['template']['R'], fn ($r) => $r['T'] === 2393 && end($r['P']) === 1)) === $i['factories']);
$bigger = $make(15850);
check('a gas giant: the same layout, link km the same', $bigger['factories'] === $i['factories'] && abs($bigger['ring_km'] - $i['ring_km']) < 1e-9);
$move = PiLayout::move(1.0, 3.2, 1.1, 50, 2820);
check('walking 50 km lands 50 km away', abs(PiLayout::distance(1.0, 3.2, $move[0], $move[1]) * 2820 - 50) < 1e-6);
check('and the bearing back points there', abs(PiLayout::bearing(1.0, 3.2, $move[0], $move[1]) - 1.1) < 1e-6);
$lower = PiLayout::p1(['planet_type_id' => 2016, 'radius_km' => 2820, 'hotspot' => [1.0, 3.2], 'types' => $barren, 'p0' => 2073, 'p1' => 2393, 'cc_level' => 4]);
check('a command center 4 fits fewer factories', $lower['factories'] < $i['factories'] && $lower['power'] <= 17000);

// Hotspot: a planted hot blob is found; one with a corp mate's heads on it is left alone.
$cells = PiScan::GRID_W * PiScan::GRID_H;
$map = str_repeat(chr(PiScan::encodeHeat(0.2)), $cells);
$blob = function (string $m, int $row, int $col, float $heat): string { for ($r = $row - 3; $r <= $row + 3; $r++) { for ($c = $col - 3; $c <= $col + 3; $c++) { $m[$r * PiScan::GRID_W + $c] = chr(PiScan::encodeHeat($heat)); } } return $m; };
$map = $blob($map, 60, 100, 0.9);
$map = $blob($map, 120, 250, 0.8);
$h = PiLayout::hotspot($map, 2820);
check('finds the hottest blob', $h !== null && abs($h['lat'] - 60.5 / 180 * M_PI) < 0.05 && abs($h['lon'] - 100.5 / 360 * 2 * M_PI) < 0.05);
$h2 = PiLayout::hotspot($map, 2820, [[60.5 / 180 * M_PI, 100.5 / 360 * 2 * M_PI]]);
check('skips ground a corp mate already pulls', $h2 !== null && abs($h2['lat'] - 120.5 / 180 * M_PI) < 0.05);

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);
