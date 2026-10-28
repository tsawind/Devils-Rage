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

// ---------- Patch 59: raw buffers, piling up / running low, launch vs haul, fresh spots, worth ----------
$gt = $types + [2310 => ['name' => 'Noble Gas', 'tier' => 0, 'volume' => 0.005], 2311 => ['name' => 'Reactive Gas', 'tier' => 0, 'volume' => 0.005],
  2392 => ['name' => 'Oxidizing Compound', 'tier' => 1], 3683 => ['name' => 'Oxygen', 'tier' => 1], 2317 => ['name' => 'Oxides', 'tier' => 2, 'volume' => 0.75]];
$gx = fn (int $pin, int $type, string $name, int $qty) => ['pin_id' => $pin, 'product' => $type, 'product_name' => $name, 'cycle_time' => 1800, 'qty' => $qty,
  'heads' => 6, 'lat' => 1.0, 'lon' => 1.0, 'install' => $now - 100 * $h, 'expiry' => $now + 64 * $h];
$gb = fn (int $pin, int $in, int $out) => ['pin_id' => $pin, 'kind' => 'basic', 'inputs' => [$in], 'outputs' => [$out]];
$store = fn (int $pin, int $type, string $name, int $amount, ?int $was) => ['pin_id' => $pin, 'kind' => 'storage facility', 'capacity' => 12000, 'used' => $amount * 0.005,
  'contents' => $amount > 0 ? [['type_id' => $type, 'name' => $name, 'tier' => 0, 'amount' => $amount]] : [],
  'previous' => $was === null ? null : ['at' => $now - 24 * $h, 'amounts' => $was > 0 ? [$type => $was] : []]];
$gas = ['character_id' => 9, 'planet_id' => 77, 'name' => 'J145735 VIII', 'type' => 'gas', 'level' => 5, 'system_id' => 1, 'last_update' => $now,
  'extractors' => [$gx(3, 2310, 'Noble Gas', 6000), $gx(10, 2311, 'Reactive Gas', 6000)],
  'factories' => [$gb(5, 2311, 2392), $gb(7, 2311, 2392), $gb(6, 2310, 3683), $gb(9, 2310, 3683),
    ['pin_id' => 2, 'kind' => 'advanced', 'inputs' => [2392, 3683], 'outputs' => [2317]], ['pin_id' => 4, 'kind' => 'advanced', 'inputs' => [2392, 3683], 'outputs' => [2317]]],
  'storage' => [['pin_id' => 1, 'kind' => 'launchpad', 'capacity' => 10000, 'used' => 2000, 'contents' => []],
    $store(8, 2310, 'Noble Gas', 500000, 500000), $store(11, 2311, 'Reactive Gas', 500000, 500000)]];
$gp = new PiPlanner($gt, [], $now);
$kinds59 = fn (array $steps): array => array_values(array_filter(array_column($steps, 'kind'), fn (string $k): bool => $k !== 'restart'));
$only59 = fn (array $steps): array => array_values(array_filter($steps, fn (array $st): bool => $st['kind'] !== 'restart'));

$lines = $gp->rawLines($gas);
check('raw lines through storage: 12,000 / h each', $lines['Noble Gas']['need'] === 12000 && $lines['Reactive Gas']['per_hour'] === 12000);
$gi = $gp->planet($gas);
check('steady buffers: nothing to do', $kinds59($gp->steps($gi)) === []);
check('buffer trend 0 / h', $gi['buffers']['Noble Gas']['per_hour'] === 0);

$pile = $gas; $pile['storage'][1] = $store(8, 2310, 'Noble Gas', 2000000, 1700000);
$pi = $gp->planet($pile);
check('piling: +12,500 / h', $pi['buffers']['Noble Gas']['per_hour'] === 12500);
$ps = $only59($gp->steps($pi));
check('piling step (got '.implode(',', array_column($ps, 'kind')).')', array_column($ps, 'kind') === ['pile'] && str_contains($ps[0]['title'], 'Noble Gas is piling up'));

$drain = $gas; $drain['storage'][2] = $store(11, 2311, 'Reactive Gas', 24000, 72000);
$ds = $only59($gp->steps($gp->planet($drain)));
check('running low: -2,000 / h, dry in 12 h', array_column($ds, 'kind') === ['drain'] && str_contains($ds[0]['why'], '2,000 / h') && str_contains($ds[0]['why'], '12 h'));
$emptied = $gas; $emptied['storage'][2] = $store(11, 2311, 'Reactive Gas', 0, 30000);
check('emptied store still has a trend', $gp->planet($emptied)['buffers']['Reactive Gas']['per_hour'] === -1250);
$noHistory = $gas; $noHistory['storage'][1] = $store(8, 2310, 'Noble Gas', 2000000, null);
check('no earlier reading: no trend', $gp->planet($noHistory)['buffers']['Noble Gas']['per_hour'] === null);

// Balance + storage agree: the balance step says so and no separate pile / drain step.
$both = $drain; $both['extractors'][1]['qty'] = 4000; $both['storage'][1] = $store(8, 2310, 'Noble Gas', 1900000, 1600000);
$bs = $only59($gp->steps($gp->planet($both)));
check('balance mentions storage (got '.implode(',', array_column($bs, 'kind')).')', array_column($bs, 'kind') === ['balance'] && str_contains($bs[0]['why'], 'storage agrees') && str_contains($bs[0]['why'], 'piling up'));

// Launchpad: launch to the customs office when it fits, haul when it doesn't.
$full = $gas; $full['storage'][0]['used'] = 9500; $full['customs'] = ['used' => 20000.0, 'capacity' => 35000, 'contents' => []];
$ls = $only59($gp->steps($gp->planet($full)));
check('launch when the customs office has room', array_column($ls, 'kind') === ['launch'] && str_contains($ls[0]['why'], '15,000 m³ free'));
$full['customs']['used'] = 30000.0;
$hs = $only59($gp->steps($gp->planet($full)));
check('haul when it does not', array_column($hs, 'kind') === ['haul'] && str_contains($hs[0]['why'], 'bring a hauler'));
$full['storage'][1] = $store(8, 2310, 'Noble Gas', 2300000, 2300000);
check('a full raw storage is not a haul', in_array('pile', array_column($gp->steps($gp->planet($full)), 'kind'), true));

// Fresh spot: losing 1 of 12 heads pays from about 8%, 2 heads from about 17%.
check('fresh spot 25% / 12 heads -> 10 heads ok', str_contains($gp->freshSpotAdvice(25, 12), 'only 10 heads'));
check('fresh spot 12% / 12 heads -> 11 ok', str_contains($gp->freshSpotAdvice(12, 12), 'keep 11 heads'));
check('fresh spot 6% / 12 heads -> keep all', str_contains($gp->freshSpotAdvice(6, 12), 'all 12 heads'));

// Worth: 2 advanced x 5 Oxides / h = 240 / day, held back by a short line.
$prices = [2317 => ['buy' => 9000.0, 'sell' => 11000.0]];
$v = $gp->value($gi, $prices);
check('worth 240 Oxides / day: buy 2.16 m, split 2.4 m', $v['items'][0]['per_day'] === 240 && abs($v['buy'] - 2160000) < 1 && abs($v['split'] - 2400000) < 1 && abs($v['m3'] - 180) < 0.01);
$short = $gas; $short['extractors'][1]['qty'] = 4500;
check('short line (75%) -> 180 / day', $gp->value($gp->planet($short), $prices)['items'][0]['per_day'] === 180);
$rawOnly = $gas; $rawOnly['factories'] = [];
$rv = $gp->value($gp->planet($rawOnly), [2310 => ['buy' => 100.0, 'sell' => 120.0]]);
check('extraction-only planet sells raw (288,000 Noble Gas / day)', $rv['items'][0]['per_day'] === 288000 && abs($rv['buy'] - 28800000) < 1);


// Customs tax: on a fixed value per tier (P2 7,200), not the market price.
$taxed = $gp->value($gi, $prices, 0.05);
check('5% tax on 240 Oxides = 86,400 / day', abs($taxed['tax'] - 86400) < 0.01 && abs($taxed['split'] - (2400000 - 86400)) < 1 && abs($taxed['buy'] - (2160000 - 86400)) < 1);
check('0% tax = no change', $gp->value($gi, $prices)['tax'] === 0.0);
check('export tax per tier', PiPlanner::exportTax(4, 2, 0.10) === 240000.0 && PiPlanner::exportTax(0, 1000, 0.10) === 500.0 && PiPlanner::exportTax(2, 10, -1.0) === 0.0);


// A factory-only planet counts only while it is being visited (imports), not forever.
$factoryOnly = $gas; $factoryOnly['extractors'] = [];
check('factory planet opened today counts', $gp->products($gp->planet($factoryOnly))[0]['per_day'] === 240);
$factoryOnly['last_update'] = $now - 30 * 86400;
check('factory planet not opened for 30 days counts nothing', $gp->products($gp->planet($factoryOnly)) === []);


// Patch 60: lining up timers without changing program lengths.
$at = fn (int $id, int $stop) => ['character_id' => $id, 'planet_id' => 100 + $id, 'name' => 'J1 '.$id, 'type' => 'gas', 'level' => 5, 'system_id' => 1, 'last_update' => $now,
  'extractors' => [['pin_id' => $id, 'product' => 2310, 'product_name' => 'Noble Gas', 'cycle_time' => 1800, 'qty' => 6000, 'heads' => 6, 'lat' => 1.0, 'lon' => 1.0, 'install' => $stop - 164 * $h, 'expiry' => $stop]],
  'factories' => [], 'storage' => []];
$lu = $gp->run([
  1 => ['name' => 'Due', 'planets' => [$at(1, $now - 5 * $h)]],
  2 => ['name' => 'Midweek', 'planets' => [$at(2, $now + 72 * $h)]],
  3 => ['name' => 'Close', 'planets' => [$at(3, $now + 150 * $h)]],
  4 => ['name' => 'InStep', 'planets' => [$at(4, $now + 166 * $h)]],
]);
check('next reset = now + 164 h', $lu['next_reset'] === $now + 164 * $h);
check('midweek character joins the run to line up', array_column($lu['characters'], 'name') === ['Due', 'Midweek'] && ($lu['characters'][1]['align'] ?? false) === true && $lu['characters'][1]['visits'][0]['steps'][0]['kind'] === 'align');
$byName = array_column($lu['clear'], null, 'name');
check('14 h early: leave it until the next reset', ($byName['Close']['align']['kind'] ?? null) === 'later' && $byName['Close']['align']['hours'] === 14);
check('in step: nothing said', $byName['InStep']['align'] === null);
$quiet = $gp->run([2 => ['name' => 'Midweek', 'planets' => [$at(2, $now + 72 * $h)]]]);
check('no run due: no line-up', $quiet['characters'] === [] && $quiet['next_reset'] === null && $quiet['clear'][0]['align'] === null);

// Customs offices go to the planet whose goods they hold.
$other = $gas; $other['planet_id'] = 78; foreach ($other['factories'] as &$f) { $f['outputs'] = $f['kind'] === 'advanced' ? [3689] : $f['outputs']; } unset($f);
$other['extractors'] = [$gx(3, 2267, 'Base Metals', 6000)];
$placed = $gp->placeCustoms([$gas, $other], [
  ['location_id' => 1, 'used' => 900.0, 'contents' => [['type_id' => 2317]]],
  ['location_id' => 2, 'used' => 50.0, 'contents' => [['type_id' => 3689], ['type_id' => 2267]]],
  ['location_id' => 3, 'used' => 10.0, 'contents' => [['type_id' => 2392]]],
  ['location_id' => 4, 'used' => 10.0, 'contents' => [['type_id' => 9999]]],
], 35000);
check('Oxides office -> planet 77, Mechanical Parts -> 78', ($placed['placed'][77]['used'] ?? 0) === 900.0 && ($placed['placed'][78]['used'] ?? 0) === 50.0);
check('ambiguous and unknown offices unplaced', count($placed['unplaced']) === 2);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
