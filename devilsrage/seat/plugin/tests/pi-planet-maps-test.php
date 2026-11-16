<?php
declare(strict_types=1);
// Run: php devilsrage/seat/plugin/tests/pi-planet-maps-test.php (plain PHP, no SeAT needed).
require __DIR__.'/../src/Pi/PlanetMaps.php';
use DevilsRage\Seat\Pi\PlanetMaps;

$fails = 0;
function check(string $what, bool $ok): void { global $fails; echo ($ok ? "ok   " : "FAIL ") . $what . "\n"; if (!$ok) $fails++; }

check('a bare map of the right size is fine', PlanetMaps::problem('bare', str_repeat("\1", 360 * 180 * 3)) === null);
check('a short bare map is not', PlanetMaps::problem('bare', str_repeat("\1", 100)) !== null);
check('a sky starts with SKY2', PlanetMaps::problem('sky', "SKY2\1\3" . str_repeat("\0", 50)) === null);
check('anything else is not a sky', PlanetMaps::problem('sky', 'hello world') !== null);
check('unknown kinds are refused', PlanetMaps::problem('moon', 'x') !== null);
check('planet types from the SDE', PlanetMaps::typeFromName('Planet (Gas)') === 'gas' && PlanetMaps::typeFromName('Planet (Lava)') === 'lava');
$b64 = base64_encode(random_bytes(1000));
$pieces = str_split($b64, 300);
check('pieces put back together', PlanetMaps::assemble($pieces, count($pieces)) === $b64);
unset($pieces[2]);
check('a missing piece means not yet', PlanetMaps::assemble($pieces, 4) === null);
check('base64 pieces split anywhere still decode', base64_decode(implode('', str_split($b64, 7)), true) === base64_decode($b64, true));

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
