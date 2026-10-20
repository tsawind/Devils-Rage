<?php
declare(strict_types=1);
// Run: php devilsrage/seat/plugin/tests/pi-planner-test.php (plain PHP, no SeAT needed).
require __DIR__.'/../src/Pi/PiPlanner.php';
use DevilsRage\Seat\Pi\PiPlanner;

$fails = 0;
function check(string $what, bool $ok): void { global $fails; echo ($ok ? "ok   " : "FAIL ") . $what . "\n"; if (!$ok) $fails++; }

$types = [
  2267 => ['name' => 'Base Metals', 'tier' => 0], 2270 => ['name' => 'Noble Metals', 'tier' => 0],
  2398 => ['name' => 'Reactive Metals', 'tier' => 1], 2399 => ['name' => 'Precious Metals', 'tier' => 1],
  3689 => ['name' => 'Mechanical Parts', 'tier' => 2], 2272 => ['name' => 'Heavy Metals', 'tier' => 0],
];
$now = 1_800_000_000;
$h = 3600;
$ex = fn (int $pin, int $type, string $name, int $qty, int $heads, ?int $expiry, int $len = 72, float $lat = 1.0, float $lon = 1.0, int $cycle = 1800) => [
  'pin_id' => $pin, 'product' => $type, 'product_name' => $name, 'cycle_time' => $cycle, 'qty' => $qty,
  'heads' => $heads, 'lat' => $lat, 'lon' => $lon, 'install' => $expiry === null ? null : $expiry - $len * 3600, 'expiry' => $expiry];
$basic = fn (int $pin, int $in, int $out) => ['pin_id' => $pin, 'kind' => 'basic', 'inputs' => [$in], 'outputs' => [$out]];
$adv = fn (int $pin) => ['pin_id' => $pin, 'kind' => 'advanced', 'inputs' => [2398, 2399], 'outputs' => [3689]];

// P0 -> P2 planet: 2 basic factories per raw line (need 12,000/h each), 1 advanced.
// Base Metals: 8 heads, 4,900 per 30 min = 9,800/h (82%). Noble: 10 heads, 7,300 / 30 min = 14,600/h (122%).
$p2 = ['character_id' => 1, 'planet_id' => 10, 'name' => 'J1 III', 'type' => 'barren', 'level' => 5, 'system_id' => 1, 'last_update' => $now - 2 * 86400,
  'extractors' => [$ex(1, 2267, 'Base Metals', 4900, 8, $now + 3 * $h), $ex(2, 2270, 'Noble Metals', 7300, 10, $now + 3 * $h, 24)],
  'factories' => [$basic(3, 2267, 2398), $basic(4, 2267, 2398), $basic(5, 2270, 2399), $basic(6, 2270, 2399), $adv(7)],
  'storage' => [['pin_id' => 8, 'kind' => 'launchpad', 'capacity' => 10000, 'used' => 9500]]];

$planner = new PiPlanner($types, [], $now);
$b = $planner->balance($p2);
check('balance found', $b !== null);
check('balance from Noble to Base', $b['from'] === 'Noble Metals' && $b['to'] === 'Base Metals');
check('percentages 82 / 122', $b['lines']['Base Metals']['percent'] === 82 && $b['lines']['Noble Metals']['percent'] === 122);
// per head: base 1225, noble 1460. exact = (1.2167-0.8167)/(1225/12000+1460/12000)=0.4/0.22375=1.79 -> 2; room 2, spare 9
check('move 2 heads (got '.$b['move'].')', $b['move'] === 2);
$after = (9800 + 2 * 1225) / 12000; $afterN = (14600 - 2 * 1460) / 12000;
check(sprintf('about even after (%.2f vs %.2f)', $after, $afterN), abs($after - $afterN) < 0.15);

$info = $planner->planet($p2);
check('status soon', $info['status'] === 'soon');
check('making Mechanical Parts P2', $info['making']['tier'] === 2 && $info['making']['names'] === ['Mechanical Parts']);
$steps = $planner->steps($info);
$kinds = array_column($steps, 'kind');
check('steps restart, balance, haul: '.implode(',', $kinds), $kinds === ['restart', 'balance', 'haul']);
check('restart says both', $steps[0]['title'] === 'Restart both extractors' && $steps[0]['time_word'] === 'stops');

// Balanced planet: no balance step.
$even = $p2; $even['extractors'][0]['qty'] = 6000; $even['extractors'][1]['qty'] = 6100;
check('balanced -> no step', $planner->balance($even) === null);
// Both over what factories need: nothing.
$over = $p2; $over['extractors'][0]['qty'] = 7000; $over['extractors'][1]['qty'] = 9000;
check('both over -> no step', $planner->balance($over) === null);
// Short line already has 10 heads: suggests another extractor.
$fullP = $p2; $fullP['extractors'][0]['heads'] = 10; $fullP['extractors'][0]['qty'] = 5000;
$bf = $planner->balance($fullP);
check('short line full -> move 0, full', $bf !== null && $bf['move'] === 0 && $bf['full'] === true);
check('full line step text', str_contains($planner->steps($planner->planet($fullP))[1]['title'], 'Add an extractor'));
// No routes into factories (inputs unknown): nothing.
$noRoutes = $p2; foreach ($noRoutes['factories'] as &$f) { $f['inputs'] = []; } unset($f);
check('no routes -> no balance', $planner->balance($noRoutes) === null);
// Missing numbers -> submit step, no balance.
$nonum = $p2; $nonum['extractors'][0]['qty'] = null;
$ni = $planner->planet($nonum);
check('no numbers -> submit step', in_array('submit', array_column($planner->steps($ni), 'kind'), true) && $ni['balance'] === null);

// Depletion: earlier program same pin, same spot, same cycle, more per head.
$hist = [1 => [['install' => $now - 30 * 86400, 'product' => 2267, 'cycle_time' => 1800, 'qty' => 7000, 'heads' => 8, 'lat' => 1.01, 'lon' => 0.99]],
         2 => [['install' => $now - 30 * 86400, 'product' => 2270, 'cycle_time' => 1800, 'qty' => 7000, 'heads' => 10, 'lat' => 2.0, 'lon' => 2.0]]];
$dp = (new PiPlanner($types, $hist, $now))->depleted($p2['extractors']);
check('depleted: only Base Metals (same spot), down 30%', count($dp) === 1 && $dp[0]['product_name'] === 'Base Metals' && $dp[0]['down'] === 30);

// Run: character A due (planet stops in 3h + another stopping in 40h, different lengths), B clear.
$p2b = ['character_id' => 1, 'planet_id' => 11, 'name' => 'J1 V', 'type' => 'lava', 'level' => 4, 'system_id' => 1, 'last_update' => $now,
  'extractors' => [$ex(21, 2272, 'Heavy Metals', 5000, 9, $now + 40 * $h, 120)], 'factories' => [], 'storage' => []];
$p2c = ['character_id' => 1, 'planet_id' => 12, 'name' => 'J1 VII', 'type' => 'gas', 'level' => 4, 'system_id' => 1, 'last_update' => $now,
  'extractors' => [$ex(31, 2272, 'Heavy Metals', 5000, 9, $now + 90 * $h)], 'factories' => [], 'storage' => []];
$pB = ['character_id' => 2, 'planet_id' => 20, 'name' => 'J1 II', 'type' => 'storm', 'level' => 4, 'system_id' => 1, 'last_update' => $now,
  'extractors' => [$ex(41, 2272, 'Heavy Metals', 5000, 9, $now + 70 * $h)], 'factories' => [], 'storage' => []];
$run = $planner->run([1 => ['name' => 'Tsawind', 'planets' => [$p2, $p2b, $p2c]], 2 => ['name' => 'Alt', 'planets' => [$pB]]]);
check('one due character', count($run['characters']) === 1 && $run['characters'][0]['name'] === 'Tsawind');
check('visits J1 III and J1 V, not J1 VII', array_map(fn ($v) => $v['planet']['planet']['name'], $run['characters'][0]['visits']) === ['J1 III', 'J1 V']);
check('timer step (24h..120h)', $run['characters'][0]['timer'] !== null && str_contains($run['characters'][0]['timer']['why'], '5 d'));
check('minutes 5', $run['minutes'] === 5);
check('deadline = 3 h', $run['deadline'] === $now + 3 * $h);
check('Alt clear', count($run['clear']) === 1 && $run['clear'][0]['next_stop'] === $now + 70 * $h);

// Stopped + not set up extractor.
$stopped = $pB; $stopped['extractors'][0]['expiry'] = $now - 5 * $h; $stopped['extractors'][0]['install'] = $now - 77 * $h;
$si = $planner->planet($stopped);
check('stopped since 5h', $si['status'] === 'stopped' && $si['stopped_since'] === $now - 5 * $h);
check('stopped step word', $planner->steps($si)[0]['time_word'] === 'stopped' && $planner->steps($si)[0]['title'] === 'Restart the Heavy Metals extractor');
$idle = $pB; $idle['extractors'][0]['heads'] = 0; $idle['extractors'][0]['expiry'] = null;
$ii = $planner->planet($idle);
check('no program -> stopped + Start', $ii['status'] === 'stopped' && $planner->steps($ii)[0]['title'] === 'Start the Heavy Metals extractor');
// Factory-only planet never makes a character due.
$fac = ['character_id' => 3, 'planet_id' => 30, 'name' => 'J1 IV', 'type' => 'temperate', 'level' => 5, 'system_id' => 1, 'last_update' => $now,
  'extractors' => [], 'factories' => [$adv(50)], 'storage' => []];
$r2 = $planner->run([3 => ['name' => 'Fac', 'planets' => [$fac]]]);
check('factory planet -> clear', $r2['characters'] === [] && count($r2['clear']) === 1);
echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
