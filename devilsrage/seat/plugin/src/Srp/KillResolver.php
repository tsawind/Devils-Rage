<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Srp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Seat\Eveapi\Jobs\Killmails\Detail;
use Seat\Eveapi\Models\Killmails\Killmail;
use Seat\Eveapi\Models\Killmails\KillmailDetail;
use Throwable;

/**
 * Patch 54: a kill's hash (SeAT's own killmails first, else zKillboard's API) and the killmail
 * itself in SeAT (fetched from ESI when SeAT doesn't have it yet), so SRP can price it.
 */
final class KillResolver
{
    public function hash(int $killId): ?string
    {
        $known = Killmail::query()->find($killId)?->killmail_hash;
        if (is_string($known) && $known !== '') {
            return $known;
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders(['User-Agent' => "Devil's Rage SeAT plugin", 'Accept' => 'application/json'])
                ->get(sprintf('https://zkillboard.com/api/killID/%d/', $killId));
            $hash = $response->successful() ? $response->json('0.zkb.hash') : null;
        } catch (Throwable $e) {
            Log::warning('devilsrage: zKillboard did not answer for kill '.$killId.': '.$e->getMessage());
            $hash = null;
        }

        return is_string($hash) && preg_match('~^[0-9a-f]{40}$~i', $hash) ? strtolower($hash) : null;
    }

    /** Whether the killmail is in SeAT now (fetched from ESI when it wasn't). */
    public function load(int $killId, string $hash): bool
    {
        if (KillmailDetail::query()->find($killId) !== null) {
            return true;
        }
        Killmail::query()->firstOrCreate(['killmail_id' => $killId], ['killmail_hash' => $hash]);
        try {
            Detail::dispatchSync($killId, $hash);
        } catch (Throwable $e) {
            Log::warning('devilsrage: could not fetch killmail '.$killId.' from ESI: '.$e->getMessage());
        }

        return KillmailDetail::query()->find($killId) !== null;
    }
}
