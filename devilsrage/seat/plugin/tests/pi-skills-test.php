<?php
declare(strict_types=1);
// Run: php devilsrage/seat/plugin/tests/pi-skills-test.php (plain PHP, no SeAT needed).
require __DIR__.'/../src/Skills/SkillMath.php';
require __DIR__.'/../src/Pi/PiSkills.php';
use DevilsRage\Seat\Pi\PiSkills;
use DevilsRage\Seat\Skills\SkillMath;

$fails = 0;
function check(string $what, bool $ok): void { global $fails; echo ($ok ? "ok   " : "FAIL ") . $what . "\n"; if (!$ok) $fails++; }

// No PI yet, IC 0 -> 5 planets after; 900k ISK a planet a day; 2 injectors at 800m.
$p = PiSkills::potential(['isk' => 1.6e9, 'planets_after' => 5], 0, 900000.0);
check('5 new planets, 4.5m a day', $p['extra_planets'] === 5 && abs($p['per_day'] - 4.5e6) < 0.01);
check('pays back in about 356 days', abs($p['payback_days'] - 1.6e9 / 4.5e6) < 0.001);
// Already 6 of 6 planets: nothing to add, no payback.
$full = PiSkills::potential(['isk' => 0.0, 'planets_after' => 6], 6, 900000.0);
check('full character adds nothing', $full['extra_planets'] === 0 && $full['per_day'] === 0.0 && $full['payback_days'] === null);
// Skilled, 3 of 5 planets: 2 free planets, nothing to buy.
$free = PiSkills::potential(['isk' => 0.0, 'planets_after' => 5], 3, 900000.0);
check('two free planets', $free['extra_planets'] === 2 && $free['payback_days'] === null);
// Injectors get weaker past 5M SP: a 4.9M SP alt needs 2 for 600k, a 60M main needs 2.
check('injectors 4.9M SP, 600k', SkillMath::injectorsFor(4_900_000, 600_000) === 2);
check('injectors 60M SP, 600k', SkillMath::injectorsFor(60_000_000, 600_000) === 2);
check('targets CC 5 + IC 4', PiSkills::TARGETS === ['Command Center Upgrades' => 5, 'Interplanetary Consolidation' => 4]);

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
