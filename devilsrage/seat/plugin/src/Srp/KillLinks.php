<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Srp;

/**
 * Patch 54: every way pilots share a loss, turned into what SRP needs (kill id + hash):
 * - EVE's ESI link (with or without /latest/ or /v1/, with or without ?datasource=…),
 * - an in-game kill link (killReport:<id>:<hash>, as pasted from the game),
 * - a zKillboard kill link (id only: the hash is looked up afterwards),
 * and a battle (zKillboard "related" or br.evetools "related" link): a system and a time window.
 * Plain PHP, no SeAT needed (tested by tests/srp-links-test.php).
 */
final class KillLinks
{
    /** How far around a battle's time its losses are looked for. */
    public const int BATTLE_WINDOW_SECONDS = 3600;

    /**
     * @return array{id: int, hash: string|null}|null
     */
    public static function kill(string $text): ?array
    {
        $text = trim($text);
        if (preg_match('~killReport:(\d+):([0-9a-f]{40})~i', $text, $m)) {
            return ['id' => (int) $m[1], 'hash' => strtolower($m[2])];
        }
        if (preg_match('~killmails/(\d+)/([0-9a-f]{40})~i', $text, $m)) {
            return ['id' => (int) $m[1], 'hash' => strtolower($m[2])];
        }
        if (preg_match('~zkillboard\.com/kill/(\d+)~i', $text, $m)) {
            return ['id' => (int) $m[1], 'hash' => null];
        }

        return null;
    }

    /**
     * Every kill link in a block of text (one per line, or pasted together), first of each id.
     *
     * @return list<array{id: int, hash: string|null}>
     */
    public static function kills(string $text): array
    {
        $found = [];
        foreach (preg_split('~[\s,]+~', $text) ?: [] as $piece) {
            $kill = self::kill($piece);
            if ($kill !== null && ! isset($found[$kill['id']])) {
                $found[$kill['id']] = $kill;
            }
        }

        return array_values($found);
    }

    /**
     * A battle: zkillboard.com/related/<system>/<YYYYMMDDHHMM>/ or br.evetools.org/related/<system>/<unix time>.
     *
     * @return array{system: int, from: int, to: int}|null unix times (UTC)
     */
    public static function battle(string $text): ?array
    {
        $text = trim($text);
        $at = null;
        $system = null;
        if (preg_match('~zkillboard\.com/related/(\d+)/(\d{12})~i', $text, $m)) {
            $system = (int) $m[1];
            $time = \DateTimeImmutable::createFromFormat('YmdHi', $m[2], new \DateTimeZone('UTC'));
            $at = $time === false ? null : $time->getTimestamp();
        } elseif (preg_match('~br\.evetools\.org/related/(\d+)/(\d{9,11})~i', $text, $m)) {
            $system = (int) $m[1];
            $at = (int) $m[2];
        }
        if ($system === null || $at === null || $system <= 0) {
            return null;
        }

        return ['system' => $system, 'from' => $at - self::BATTLE_WINDOW_SECONDS, 'to' => $at + self::BATTLE_WINDOW_SECONDS];
    }

    /** The ESI address SRP fetches a killmail from. */
    public static function esiUrl(int $id, string $hash): string
    {
        return sprintf('https://esi.evetech.net/killmails/%d/%s/', $id, $hash);
    }
}
