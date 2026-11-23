<?php
declare(strict_types=1);
// Run: php devilsrage/seat/plugin/tests/pi-planet-order-test.php (plain PHP, no SeAT needed).
require __DIR__.'/../src/Pi/PlanetOrder.php';
use DevilsRage\Seat\Pi\PlanetOrder;

$fails = 0;
function check(string $what, bool $ok): void { global $fails; echo ($ok ? "ok   " : "FAIL ") . $what . "\n"; if (!$ok) $fails++; }

// Tsa's screenshot came out I, II, IV, IX, V.
$names = ['J145735 IX', 'J145735 V', 'J145735 I', 'J145735 IV', 'J145735 II'];
usort($names, [PlanetOrder::class, 'compare']);
check('system order I, II, IV, V, IX', $names === ['J145735 I', 'J145735 II', 'J145735 IV', 'J145735 V', 'J145735 IX']);

$all = ['Jita X', 'Jita IX', 'Amarr XII', 'Jita XIV', 'Jita III', 'Amarr VIII'];
usort($all, [PlanetOrder::class, 'compare']);
check('systems first, then numbers', $all === ['Amarr VIII', 'Amarr XII', 'Jita III', 'Jita IX', 'Jita X', 'Jita XIV']);

foreach (['I' => 1, 'IV' => 4, 'IX' => 9, 'XIV' => 14, 'XIX' => 19, 'XL' => 40] as $numeral => $value) {
    check("$numeral = $value", PlanetOrder::number($numeral) === $value);
}
// A name with no numeral keeps its whole name and comes after the numbered ones.
check('no numeral', PlanetOrder::split('Thera') === ['Thera', PHP_INT_MAX]);
check('system with spaces', PlanetOrder::split('New Caldari II') === ['New Caldari', 2]);
check('equal names', PlanetOrder::compare('J145735 V', 'J145735 V') === 0);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
