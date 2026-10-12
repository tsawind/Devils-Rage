<?php

declare(strict_types=1);

namespace App\Services\Seat;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The Devil's Rage SeAT plugin's read-only bot API (devilsrage/seat/plugin, BotApiController).
 * Pilots are looked up by the Discord account they linked in SeAT's Connector.
 *
 * Methods returning null mean "this Discord user has not linked SeAT".
 */
final class SeatClient
{
    private const int ASSET_CACHE_SECONDS = 900;

    public function configured(): bool
    {
        return filled(config('services.seat_bot.url')) && strlen((string) config('services.seat_bot.key')) >= 32;
    }

    /**
     * @return list<array{character: string, ship: string, jump_class: string, system_id: int, jdc: int}>|null
     */
    public function jumpShips(string $discordUserId): ?array
    {
        $json = $this->get('discord/'.$discordUserId.'/jump-ships');

        return $json === null ? null : array_values($json['ships'] ?? []);
    }

    /**
     * Cached for 15 minutes: SeAT itself refreshes assets about hourly.
     *
     * @return list<array{system_id: int, items: int, value: float, ships: list<string>}>|null
     */
    public function assetSystems(string $discordUserId, bool $shipsOnly): ?array
    {
        $key = sprintf('seat:assets:%s:%d', $discordUserId, $shipsOnly ? 1 : 0);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached['systems'];
        }

        $json = $this->get('discord/'.$discordUserId.'/assets', ['ships_only' => $shipsOnly ? 1 : 0]);
        $systems = $json === null ? null : array_values($json['systems'] ?? []);
        Cache::put($key, ['systems' => $systems], self::ASSET_CACHE_SECONDS);

        return $systems;
    }

    /**
     * @return list<array{id: int, name: string, ship: string}>
     */
    public function fits(string $search): array
    {
        return array_values($this->get('fits', ['q' => $search])['fits'] ?? []);
    }

    /**
     * @return array{id: int, name: string, ship: string, eft: string}|null
     */
    public function fit(int $fittingId): ?array
    {
        return $this->get('fits/'.$fittingId);
    }

    /**
     * @return array{fit: array{id: int, name: string, ship: string}, characters: list<array{character: string, can_fly: bool, close: bool, train_time: string, missing: list<string>, missing_count: int}>}|null
     */
    public function canFly(string $discordUserId, int $fittingId): ?array
    {
        return $this->get('discord/'.$discordUserId.'/can-fly/'.$fittingId);
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>|null null on 404 (not linked / not found)
     */
    private function get(string $path, array $query = []): ?array
    {
        if (! $this->configured()) {
            throw new SeatUnavailableException("SeAT is not connected to the bot yet (SEAT_BOT_KEY in the mapper's .env).");
        }

        try {
            $response = $this->request()->get($path, $query);
        } catch (ConnectionException $exception) {
            throw new SeatUnavailableException('SeAT did not answer.', previous: $exception);
        }

        if ($response->notFound()) {
            return null;
        }

        if (! $response->successful()) {
            throw new SeatUnavailableException($this->describe($response));
        }

        return (array) $response->json();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(mb_rtrim((string) config('services.seat_bot.url'), '/').'/devilsrage/api/')
            ->acceptJson()
            ->withHeaders(['X-DR-Key' => (string) config('services.seat_bot.key')])
            ->connectTimeout(4)
            ->timeout(10);
    }

    private function describe(Response $response): string
    {
        return match ($response->status()) {
            401 => 'SeAT refused the bot key (SEAT_BOT_KEY here must match DR_BOT_API_KEY in SeAT).',
            429 => 'SeAT is busy, try again in a minute.',
            default => 'SeAT answered with HTTP '.$response->status().'.',
        };
    }
}
