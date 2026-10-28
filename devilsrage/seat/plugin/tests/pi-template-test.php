<?php
declare(strict_types=1);
// Run: php devilsrage/seat/plugin/tests/pi-template-test.php (plain PHP, no SeAT needed).
require __DIR__.'/../src/Pi/PiPlanner.php';
require __DIR__.'/../src/Pi/PiTemplate.php';
use DevilsRage\Seat\Pi\PiPlanner;
use DevilsRage\Seat\Pi\PiTemplate;

$fails = 0;
function check(string $what, bool $ok): void { global $fails; echo ($ok ? "ok   " : "FAIL ") . $what . "\n"; if (!$ok) $fails++; }
function throws(callable $f, string $part): bool { try { $f(); return false; } catch (\InvalidArgumentException $e) { return str_contains($e->getMessage(), $part); } }

// Girl Shady's gas planet, exactly as EVE copied it (Oxides from Reactive Gas + Noble Gas).
$oxides = '{"CmdCtrLv": 5, "Cmt": "Girl Shady 2026.10.28 16:40:21", "Diam": 31700.0, "L": [{"D": 6, "Lv": 0, "S": 1}, {"D": 7, "Lv": 0, "S": 5}, {"D": 4, "Lv": 0, "S": 1}, {"D": 9, "Lv": 0, "S": 6}, {"D": 3, "Lv": 0, "S": 8}, {"D": 8, "Lv": 0, "S": 9}, {"D": 5, "Lv": 0, "S": 11}, {"D": 2, "Lv": 0, "S": 1}, {"D": 7, "Lv": 0, "S": 1}, {"D": 11, "Lv": 0, "S": 10}], "P": [{"H": 0, "La": 1.34494, "Lo": 4.39611, "S": null, "T": 2543}, {"H": 0, "La": 1.34494, "Lo": 4.40846, "S": 2317, "T": 2494}, {"H": 6, "La": 1.29672, "Lo": 4.39637, "S": 2310, "T": 3060}, {"H": 0, "La": 1.34515, "Lo": 4.38373, "S": 2317, "T": 2494}, {"H": 0, "La": 1.36894, "Lo": 4.39646, "S": 2392, "T": 2492}, {"H": 0, "La": 1.33294, "Lo": 4.39613, "S": 3683, "T": 2492}, {"H": 0, "La": 1.35694, "Lo": 4.39621, "S": 2392, "T": 2492}, {"H": 0, "La": 1.30885, "Lo": 4.39624, "S": null, "T": 2536}, {"H": 0, "La": 1.32088, "Lo": 4.39618, "S": 3683, "T": 2492}, {"H": 6, "La": 1.39298, "Lo": 4.39625, "S": 2311, "T": 3060}, {"H": 0, "La": 1.38098, "Lo": 4.39657, "S": null, "T": 2536}], "Pln": 13, "R": [{"P": [1, 2], "Q": 40, "T": 2392}, {"P": [1, 4], "Q": 40, "T": 3683}, {"P": [1, 2], "Q": 40, "T": 3683}, {"P": [5, 7, 1], "Q": 20, "T": 2392}, {"P": [7, 1], "Q": 20, "T": 2392}, {"P": [3, 8], "Q": 131568, "T": 2310}, {"P": [10, 11], "Q": 92456, "T": 2311}, {"P": [9, 6, 1], "Q": 20, "T": 3683}, {"P": [6, 1], "Q": 20, "T": 3683}, {"P": [2, 1], "Q": 5, "T": 2317}, {"P": [4, 1], "Q": 5, "T": 2317}, {"P": [11, 5], "Q": 3000, "T": 2311}, {"P": [11, 5, 7], "Q": 3000, "T": 2311}, {"P": [8, 9], "Q": 3000, "T": 2310}, {"P": [8, 9, 6], "Q": 3000, "T": 2310}, {"P": [1, 4], "Q": 40, "T": 2392}]}';
$types = [
  13 => ['name' => 'Planet (Gas)', 'tier' => -1],
  2543 => ['name' => 'Gas Launchpad', 'tier' => -1], 2494 => ['name' => 'Gas Advanced Industry Facility', 'tier' => -1],
  3060 => ['name' => 'Gas Extractor Control Unit', 'tier' => -1], 2492 => ['name' => 'Gas Basic Industry Facility', 'tier' => -1],
  2536 => ['name' => 'Gas Storage Facility', 'tier' => -1],
  2310 => ['name' => 'Noble Gas', 'tier' => 0], 2311 => ['name' => 'Reactive Gas', 'tier' => 0],
  2392 => ['name' => 'Oxidizing Compound', 'tier' => 1], 3683 => ['name' => 'Oxygen', 'tier' => 1], 2317 => ['name' => 'Oxides', 'tier' => 2],
];

$data = PiTemplate::parse($oxides);
check('parses EVE template', $data['Pln'] === 13 && count($data['P']) === 11);
$ids = PiTemplate::typeIds($data);
check('type ids cover pins, products, routes, planet', !array_diff([13, 2543, 2494, 3060, 2492, 2536, 2310, 2311, 2392, 3683, 2317], $ids));
$sum = PiTemplate::summary($data, $types);
check('planet Gas, CC 5', $sum['planet'] === 'Gas' && $sum['level'] === 5);
check('2 extractors, 6 heads each', count($sum['extractors']) === 2 && $sum['extractors'][0]['heads'] === 6 && $sum['extractors'][1]['heads'] === 6);
check('4 basic, 2 advanced', $sum['factories'] === ['basic' => 4, 'advanced' => 2, 'hightech' => 0]);
check('1 launchpad, 2 storage', $sum['storage'] === ['launchpad' => 1, 'storage' => 2]);
check('Noble + Reactive Gas -> Oxides P2', $sum['raw'] === ['Noble Gas', 'Reactive Gas'] && $sum['makes'] === ['Oxides'] && $sum['tier'] === 2);
check('title "Gas · Oxides (P2)" (got '.$sum['title'].')', $sum['title'] === 'Gas · Oxides (P2)');
check('comment kept', $sum['comment'] === 'Girl Shady 2026.10.28 16:40:21');
check('10 links', $sum['links'] === 10);
$spaced = json_encode(json_decode($oxides, true), JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION);
check('same fingerprint whatever the spacing', PiTemplate::fingerprint(PiTemplate::parse($spaced)) === PiTemplate::fingerprint($data));
// Unknown types still describe something.
$bare = PiTemplate::summary($data, []);
check('no type names -> still a title', $bare['planet'] === 'Unknown planet' && $bare['title'] !== '');
// Import-only colony (no extractors): raw from routes.
$noEx = $data; $noEx['P'][2]['T'] = 2536; $noEx['P'][9]['T'] = 2536;
$s2 = PiTemplate::summary($noEx, $types);
check('no extractors -> raw from routes', $s2['extractors'] === [] && $s2['raw'] === ['Noble Gas', 'Reactive Gas']);

// Bad input gets a plain message.
check('empty', throws(fn () => PiTemplate::parse('  '), 'Paste'));
check('not json', throws(fn () => PiTemplate::parse('Girl Shady'), "isn't a PI template"));
check('json without pins', throws(fn () => PiTemplate::parse('{"a": 1}'), 'no installations'));
$bad = json_decode($oxides, true); $bad['L'][0]['D'] = 40;
check('link to a missing pin', throws(fn () => PiTemplate::parse(json_encode($bad)), 'link'));
$bad = json_decode($oxides, true); $bad['R'][0]['P'] = [1, 0];
check('route to a missing pin', throws(fn () => PiTemplate::parse(json_encode($bad)), 'route'));
$bad = json_decode($oxides, true); $bad['P'][2]['H'] = 11;
check('11 heads refused', throws(fn () => PiTemplate::parse(json_encode($bad)), 'heads'));
$bad = json_decode($oxides, true); unset($bad['CmdCtrLv']);
check('no CC level', throws(fn () => PiTemplate::parse(json_encode($bad)), 'command center'));
check('too long', throws(fn () => PiTemplate::parse(str_repeat(' ', 10).'{"P":['.str_repeat('1,', 60000).'1]}'), 'too long'));
check('pin kinds', PiTemplate::pinKind('Barren Launchpad') === 'launchpad' && PiTemplate::pinKind('Lava Storage Facility') === 'storage facility' && PiTemplate::pinKind('Temperate High-Tech Production Plant') === 'hightech');

// Patch 59: every store on a planet, launchpads first, with what's in them.
$planner = new PiPlanner([], [], 1_800_000_000);
$stores = $planner->stores(['storage' => [
  ['pin_id' => 1, 'kind' => 'storage facility', 'capacity' => 12000, 'used' => 1200, 'contents' => [['name' => 'Noble Gas', 'amount' => 100], ['name' => 'Reactive Gas', 'amount' => 900]]],
  ['pin_id' => 2, 'kind' => 'command center', 'capacity' => 500, 'used' => 400],
  ['pin_id' => 3, 'kind' => 'launchpad', 'capacity' => 10000, 'used' => 2300],
  ['pin_id' => 4, 'kind' => 'storage facility', 'capacity' => 12000, 'used' => 6000],
]]);
check('3 stores, CC left out', count($stores) === 3);
check('launchpad first, then fuller storage', array_column($stores, 'kind') === ['launchpad', 'storage facility', 'storage facility'] && abs($stores[1]['fill'] - 0.5) < 1e-9);
check('contents largest first', $stores[2]['contents'][0]['name'] === 'Reactive Gas');
$only = $planner->stores(['storage' => [['pin_id' => 2, 'kind' => 'command center', 'capacity' => 500, 'used' => 250]]]);
check('CC alone still shown', count($only) === 1 && $only[0]['kind'] === 'command center' && $only[0]['contents'] === []);
check('no storage -> empty', $planner->stores(['storage' => []]) === []);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
