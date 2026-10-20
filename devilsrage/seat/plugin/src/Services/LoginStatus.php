<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Seat\Eveapi\Models\RefreshToken;
use Throwable;

/**
 * Patch 49: for My accounts, whether each character's logins work: in SeAT (its login is there,
 * not expired, with every permission SeAT asks for) and in the mapper (asked over the bot key).
 */
final class LoginStatus
{
    public const OK = 'ok';
    public const EXPIRED = 'expired';
    public const SCOPES = 'scopes';
    public const MISSING = 'missing';
    /** Patch 49b: logged in on purpose with public data only. Counts as fine. */
    public const PUBLIC = 'public';

    /**
     * SeAT login state per character id: ok, expired (log in again), scopes (permissions missing:
     * log in again to add them) or missing (never logged in to SeAT).
     *
     * @param  list<int>  $characterIds
     * @param  list<int>  $publicOnly  characters the pilot logged in with public data only
     * @return array<int, string>
     */
    public function seat(array $characterIds, array $publicOnly = []): array
    {
        $tokens = RefreshToken::withTrashed()
            ->whereIn('character_id', $characterIds)
            ->get()
            ->keyBy(fn (RefreshToken $token): int => (int) $token->character_id);
        $required = $this->requiredScopes();

        $status = [];
        foreach ($characterIds as $id) {
            $token = $tokens->get($id);
            if ($token === null) {
                $status[$id] = self::MISSING;
            } elseif ($token->trashed()) {
                $status[$id] = self::EXPIRED;
            } elseif (in_array($id, $publicOnly, true)) {
                $status[$id] = self::PUBLIC;
            } elseif (array_diff($required, (array) $token->scopes) !== []) {
                $status[$id] = self::SCOPES;
            } else {
                $status[$id] = self::OK;
            }
        }

        return $status;
    }

    /**
     * Whether the mapper has a working login per character id; null when the mapper couldn't
     * be asked (not set up, or down).
     *
     * @param  list<int>  $characterIds
     * @param  list<int>  $publicOnly  for these, any mapper login counts (no tracking wanted)
     * @return array<int, bool>|null
     */
    public function mapper(array $characterIds, array $publicOnly = []): ?array
    {
        $key = (string) config('devilsrage.bot_api_key', '');
        $url = rtrim((string) config('devilsrage.mapper_url', ''), '/');
        if ($characterIds === [] || strlen($key) < 32 || $url === '') {
            return null;
        }

        try {
            $response = Http::timeout(4)
                ->acceptJson()
                ->withHeaders(['X-DR-Key' => $key, 'User-Agent' => "Devil's Rage SeAT plugin"])
                ->get($url.'/seat-api/characters', ['ids' => implode(',', $characterIds)]);
            if (! $response->successful()) {
                Log::warning('devilsrage: the mapper answered '.$response->status().' about logins');

                return null;
            }
            $answer = (array) $response->json('characters', []);
            $known = (array) $response->json('known', []);
        } catch (Throwable $e) {
            Log::warning('devilsrage: could not ask the mapper about logins: '.$e->getMessage());

            return null;
        }

        $status = [];
        foreach ($characterIds as $id) {
            $status[$id] = in_array($id, $publicOnly, true)
                ? (bool) ($known[(string) $id] ?? $answer[(string) $id] ?? false)
                : (bool) ($answer[(string) $id] ?? false);
        }

        return $status;
    }

    /**
     * The permissions SeAT asks for (its default SSO scope profile), without publicData.
     *
     * @return list<string>
     */
    public function requiredScopes(): array
    {
        try {
            $profiles = collect(setting('sso_scopes', true));
        } catch (Throwable) {
            return [];
        }
        $default = $profiles->first(fn ($profile): bool => (bool) ($profile->default ?? false)) ?? $profiles->first();

        return array_values(array_diff((array) ($default->scopes ?? []), ['publicData']));
    }
}
