<?php

declare(strict_types=1);

namespace App\Services\Seat;

use App\Models\Character;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Patch 37: each character's main, from SeAT. SeAT knows which characters belong to
 * which person (its users, each with a main character); the mapper shows names as
 * "Alt - Main" so everyone knows which human to ask. Synced hourly through SeAT's API
 * (an API token made in SeAT: Settings → API Tokens, allowing the mapper's address).
 */
final class SeatMains
{
    public const string SYNCED_KEY = 'seat-mains-synced-at';

    public static function configured(): bool
    {
        return filled(config('services.seat.url')) && filled(config('services.seat.token'));
    }

    /** SeAT is set up and answered in the last day: names without a main read "Missing SeAT". */
    public static function enabled(): bool
    {
        $synced = Cache::get(self::SYNCED_KEY);

        return self::configured() && is_int($synced) && $synced > now()->subDay()->getTimestamp();
    }

    /**
     * Character name => main name, for every character SeAT knows.
     *
     * @return array<string, string>
     */
    public static function byName(): array
    {
        /** @var array<string, string> $mains */
        $mains = Character::query()
            ->whereNotNull('seat_main_name')
            ->pluck('seat_main_name', 'name')
            ->all();

        return $mains;
    }

    /**
     * Pulls every SeAT user and stores each character's main. Returns how many
     * characters have a main. Nothing changes when SeAT can't be reached.
     */
    public function sync(): int
    {
        if (! self::configured()) {
            throw new RuntimeException('SEAT_URL and SEAT_API_TOKEN are not set.');
        }

        /** @var array<int, string> $mainOf character id => main name */
        $mainOf = [];
        $url = mb_rtrim((string) config('services.seat.url'), '/').'/api/v2/users';
        $pages = 0;

        while ($url !== null && $pages < 50) {
            $pages++;
            $response = Http::timeout(20)
                ->acceptJson()
                ->withHeaders(['X-Token' => (string) config('services.seat.token')])
                ->get($url)
                ->throw();

            /** @var array<int, array<string, mixed>> $users */
            $users = $response->json('data') ?? [];
            foreach ($users as $user) {
                $mainId = is_numeric($user['main_character_id'] ?? null) ? (int) $user['main_character_id'] : null;
                $characterIds = array_map(intval(...), array_filter((array) ($user['associated_character_ids'] ?? []), is_numeric(...)));
                if ($mainId !== null && ! in_array($mainId, $characterIds, true)) {
                    $characterIds[] = $mainId;
                }
                $mainName = $mainId !== null ? Character::query()->whereKey($mainId)->value('name') : null;
                $mainName ??= is_string($user['name'] ?? null) ? $user['name'] : null;
                if ($mainName === null) {
                    continue;
                }
                foreach ($characterIds as $characterId) {
                    $mainOf[$characterId] = $mainName;
                }
            }

            $next = $response->json('links.next');
            $url = is_string($next) && $next !== '' ? $next : null;
        }

        foreach (collect($mainOf)->groupBy(fn (string $main): string => $main, preserveKeys: true) as $main => $characters) {
            Character::query()->whereKey($characters->keys()->all())->update(['seat_main_name' => $main]);
        }
        Character::query()
            ->whereNotNull('seat_main_name')
            ->when($mainOf !== [], fn ($query) => $query->whereKeyNot(array_keys($mainOf)))
            ->update(['seat_main_name' => null]);

        Cache::forever(self::SYNCED_KEY, now()->getTimestamp());

        return count($mainOf);
    }
}
