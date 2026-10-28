<?php
declare(strict_types=1);
// Run: php devilsrage/seat/plugin/tests/pi-routes-test.php (plain PHP, no SeAT needed).
require __DIR__.'/../src/Pi/PiRoutes.php';
use DevilsRage\Seat\Pi\PiRoutes;

$fails = 0;
function check(string $what, bool $ok): void { global $fails; echo ($ok ? "ok   " : "FAIL ") . $what . "\n"; if (!$ok) $fails++; }

// Tsa's lava: extractor 1 (Felsic 100) and 2 (Non-CS 200) into storages 3 and 4; basics 5, 6 (Felsic) and 7, 8 (Non-CS).
$kinds = [1 => 'extractor', 2 => 'extractor', 3 => 'storage facility', 4 => 'storage facility', 5 => 'basic', 6 => 'basic', 7 => 'basic', 8 => 'basic', 9 => 'launchpad'];
$routes = [
    ['from' => 1, 'to' => 3, 'type' => 100], ['from' => 2, 'to' => 4, 'type' => 200],
    ['from' => 4, 'to' => 7, 'type' => 200], ['from' => 4, 'to' => 8, 'type' => 200],
];
// Storage 3 is full of Felsic Magma and nothing takes it out.
$r = PiRoutes::check($kinds, $routes, [3 => [100 => 2_400_000], 4 => [200 => 10]], [100, 200]);
check('felsic stuck in storage 3', count($r['stuck']) === 1 && $r['stuck'][0]['pin'] === 3 && $r['stuck'][0]['type'] === 100);
// Non-CS is needed by 7 and 8; any-input wants it from 3, 4 and the launchpad 9: 6 routes, 2 there.
check('any-input wants 6 for Non-CS', $r['wanted'] === 6 && count($r['missing']) === 4);
check('missing includes storage 3 -> 7', in_array(['from' => 3, 'to' => 7, 'type' => 200], $r['missing'], true));

// Fixed: both storages feed every factory its raw, the launchpad too.
$full = $routes;
foreach ([3, 4, 9] as $holder) {
    foreach ([[100, 5], [100, 6], [200, 7], [200, 8]] as [$type, $factory]) {
        $full[] = ['from' => $holder, 'to' => $factory, 'type' => $type];
    }
}
$ok = PiRoutes::check($kinds, $full, [3 => [100 => 500]], [100, 200]);
check('nothing stuck, nothing missing', $ok['stuck'] === [] && $ok['missing'] === [] && $ok['wanted'] === 12);
// An empty storage is never "stuck"; something no factory or extractor here uses is left alone.
check('empty or unrelated contents ignored', PiRoutes::check($kinds, $routes, [3 => [100 => 0], 9 => [999 => 50]], [100, 200])['stuck'] === []);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
