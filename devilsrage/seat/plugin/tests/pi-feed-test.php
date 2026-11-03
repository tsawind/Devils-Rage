<?php
declare(strict_types=1);
// Run: php devilsrage/seat/plugin/tests/pi-feed-test.php (plain PHP, no SeAT needed).
require __DIR__.'/../src/Pi/PiFeed.php';
use DevilsRage\Seat\Pi\PiFeed;

$fails = 0;
function check(string $what, bool $ok): void { global $fails; echo ($ok ? "ok   " : "FAIL ") . $what . "\n"; if (!$ok) $fails++; }

// Types: 1 Microorganisms (P0), 2 Bacteria (P1), 10 Oxides, 11 Coolant, 12 Mechanical Parts, 13 Consumer Electronics (P2),
// 20 Condensates, 21 Robotics (P3), 30 Organic Mortar Applicators (P4).
$types = [1 => ['tier' => 0], 2 => ['tier' => 1], 10 => ['tier' => 2], 11 => ['tier' => 2], 12 => ['tier' => 2], 13 => ['tier' => 2], 20 => ['tier' => 3], 21 => ['tier' => 3], 30 => ['tier' => 4]];
// Tsawind's J145735 IX: Microorganisms extractor, 2 basic (→ Bacteria), 4 advanced Condensates, 4 advanced Robotics, 4 high-tech OMA.
$f = fn (string $kind, array $in, array $out): array => ['kind' => $kind, 'inputs' => $in, 'outputs' => $out];
$factories = [$f('basic', [1], [2]), $f('basic', [1], [2])];
for ($i = 0; $i < 4; $i++) { $factories[] = $f('advanced', [10, 11], [20]); $factories[] = $f('advanced', [12, 13], [21]); $factories[] = $f('hightech', [20, 21, 2], [30]); }
$planet = ['extractors' => [['product' => 1]], 'factories' => $factories];
$need = PiFeed::imports($planet, $types);
check('imports the four P2 only', array_keys($need) === [10, 11, 12, 13]);
check('10 of each P2 per advanced per hour', $need[10] === 40 && $need[13] === 40);

// A normal P0 → P2 planet feeds itself.
$p2 = ['extractors' => [['product' => 1], ['product' => 3]], 'factories' => [$f('basic', [1], [2]), $f('basic', [3], [4]), $f('advanced', [2, 4], [12])]];
check('P0 → P2 planet imports nothing', PiFeed::imports($p2, $types + [3 => ['tier' => 0], 4 => ['tier' => 1]]) === []);

// Share: your characters make 30 Oxides / h but plenty of the rest: 75% fed, and only 75% of each used.
$shares = PiFeed::share(['IX' => $need, 'V' => $need], [10 => 30.0, 11 => 100.0, 12 => 100.0, 13 => 100.0]);
check('first planet fed 75%', abs($shares['IX']['fed'] - 0.75) < 1e-9);
check('uses only what the scarcest input allows', abs($shares['IX']['items'][11]['got'] - 30.0) < 1e-9);
check('second planet gets nothing of Oxides: 0%', $shares['V']['fed'] === 0.0);
check('enough for both: both 100%', PiFeed::share(['IX' => $need, 'V' => $need], [10 => 80.0, 11 => 80.0, 12 => 80.0, 13 => 80.0])['V']['fed'] === 1.0);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
