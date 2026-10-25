<?php
declare(strict_types=1);
// Run: php devilsrage/seat/plugin/tests/srp-links-test.php (plain PHP, no SeAT needed).
require __DIR__.'/../src/Srp/KillLinks.php';
use DevilsRage\Seat\Srp\KillLinks;

$fails = 0;
function check(string $what, bool $ok): void { global $fails; echo ($ok ? "ok   " : "FAIL ") . $what . "\n"; if (!$ok) $fails++; }

$hash = '0f6c3a1e5b9d2c7a8e4f1b3d5c7e9a0b2d4f6a8c';
$want = ['id' => 131234567, 'hash' => $hash];
check('ESI link with /latest/ and ?datasource', KillLinks::kill("https://esi.evetech.net/latest/killmails/131234567/$hash/?datasource=tranquility") === $want);
check('ESI link without /latest/ or a trailing slash', KillLinks::kill("https://esi.evetech.net/killmails/131234567/$hash") === $want);
check('old esi.tech.ccp.is /v1/ link', KillLinks::kill("https://esi.tech.ccp.is/v1/killmails/131234567/$hash/") === $want);
check('in-game kill link', KillLinks::kill("<url=killReport:131234567:$hash>Kill: LuigiSquirrel (Pacifier)</url>") === $want);
check('upper-case hash comes out lower-case', KillLinks::kill('killReport:131234567:'.strtoupper($hash)) === $want);
check('zKillboard link: id, hash looked up later', KillLinks::kill('https://zkillboard.com/kill/131234567/') === ['id' => 131234567, 'hash' => null]);
check('anything else: nothing', KillLinks::kill('https://zkillboard.com/character/93976142/') === null && KillLinks::kill('hello') === null);

$many = KillLinks::kills("https://zkillboard.com/kill/1/\nhttps://zkillboard.com/kill/1/ killReport:2:$hash, https://esi.evetech.net/killmails/3/$hash/");
check('several links at once, each kill once', array_column($many, 'id') === [1, 2, 3]);

$zkb = KillLinks::battle('https://zkillboard.com/related/31002077/202610211800/');
$at = gmmktime(18, 0, 0, 10, 21, 2026);
check('zKillboard related: system and an hour each side', $zkb === ['system' => 31002077, 'from' => $at - 3600, 'to' => $at + 3600]);
$br = KillLinks::battle('https://br.evetools.org/related/31002077/1792605600/');
check('br.evetools related: same battle', $br === ['system' => 31002077, 'from' => 1792605600 - 3600, 'to' => 1792605600 + 3600]);
check('the two links point at the same time', $at === 1792605600);
check('a kill link is not a battle', KillLinks::battle('https://zkillboard.com/kill/131234567/') === null);
check('ESI address for SRP', KillLinks::esiUrl(131234567, $hash) === "https://esi.evetech.net/killmails/131234567/$hash/");

echo $fails === 0 ? "\nall good\n" : "\n$fails failed\n";
exit($fails === 0 ? 0 : 1);
