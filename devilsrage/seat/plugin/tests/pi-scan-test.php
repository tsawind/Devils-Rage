<?php
declare(strict_types=1);
// Run: php devilsrage/seat/plugin/tests/pi-scan-test.php (plain PHP, no SeAT needed).
require __DIR__.'/../src/Pi/PiScan.php';
use DevilsRage\Seat\Pi\PiScan;

$fails = 0;
function check(string $what, bool $ok): void { global $fails; echo ($ok ? "ok   " : "FAIL ") . $what . "\n"; if (!$ok) $fails++; }

$cells = PiScan::GRID_W * PiScan::GRID_H;
$empty = str_repeat(chr(0), $cells);
check('a whole grid decodes', PiScan::decode(base64_encode($empty)) === $empty);
check('a short grid does not', PiScan::decode(base64_encode('abc')) === null);
check('bytes over 251 are refused', PiScan::decode(base64_encode(str_repeat(chr(255), $cells))) === null);
check('not base64 is refused', PiScan::decode('***') === null);

check('empty covers nothing', PiScan::coverage($empty) === 0.0);
$full = str_repeat(chr(126), $cells);
check('full covers everything', abs(PiScan::coverage($full) - 1.0) < 1e-9);
// The north half seen: half the surface.
$north = str_repeat(chr(126), $cells / 2).str_repeat(chr(0), $cells / 2);
check('north half is half the surface', abs(PiScan::coverage($north) - 0.5) < 1e-6);
// A polar cap row counts for much less than an equator row.
$cap = substr_replace($empty, str_repeat(chr(126), PiScan::GRID_W), 0, PiScan::GRID_W);
$equator = substr_replace($empty, str_repeat(chr(126), PiScan::GRID_W), 90 * PiScan::GRID_W, PiScan::GRID_W);
check('pole rows count less than equator rows', PiScan::coverage($cap) < PiScan::coverage($equator) / 20);

check('heat 0 and 1 encode to 1 and 251', PiScan::encodeHeat(0) === 1 && PiScan::encodeHeat(1) === 251 && PiScan::encodeHeat(7) === 251);
$one = $empty;
$one[90 * PiScan::GRID_W + 180] = chr(PiScan::encodeHeat(0.8));
check('heat at the equator, longitude π', abs((float) PiScan::heatAt($one, M_PI / 2 + 0.001, M_PI + 0.001) - 0.8) < 0.01);
check('unseen is null', PiScan::heatAt($one, 0.1, 0.1) === null);
check('longitude wraps', PiScan::heatAt($one, M_PI / 2 + 0.001, M_PI + 0.001 + 4 * M_PI) !== null);

$old = $full;
$new = $empty;
$new[0] = chr(200);
$merged = PiScan::merge($old, $new);
check('a rescan keeps what it did not see', ord($merged[5]) === 126);
check('and replaces what it saw', ord($merged[0]) === 200);
check('no old map: the new one', PiScan::merge(null, $new) === $new);

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);
