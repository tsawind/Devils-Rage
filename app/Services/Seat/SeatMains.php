<?php

declare(strict_types=1);

namespace App\Services\Seat;

use App\Models\Character;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
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
        /** @var array<int, int> $mainIdOf character id => main character id */
        $mainIdOf = [];
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
                    if ($mainId !== null) {
                        $mainIdOf[$characterId] = $mainId;
                    }
                }
            }

            $next = $response->json('links.next');
            $url = is_string($next) && $next !== '' ? $next : null;
        }

        foreach (collect($mainOf)->groupBy(fn (string $main): string => $main, preserveKeys: true) as $main => $characters) {
            $ids = $characters->keys()->all();
            $mainId = $mainIdOf[$ids[0]] ?? null;
            Character::query()->whereKey($ids)->update(['seat_main_name' => $main, 'seat_main_id' => $mainId]);
        }
        Character::query()
            ->whereNotNull('seat_main_name')
            ->when($mainOf !== [], fn ($query) => $query->whereKeyNot(array_keys($mainOf)))
            ->update(['seat_main_name' => null, 'seat_main_id' => null]);

        Cache::forever(self::SYNCED_KEY, now()->getTimestamp());

        $this->syncPeople();

        return count($mainOf);
    }

    /**
     * Patch 39: character name => the person's name (SeAT's "Name you go by", else the
     * main's name; a guest's own name in the mapper).
     *
     * @return array<string, string>
     */
    public static function people(): array
    {
        /** @var array<string, string> $people */
        $people = Character::query()
            ->whereNotNull('seat_person_name')
            ->pluck('seat_person_name', 'name')
            ->all();

        /** @var Collection<int, User> $guests */
        $guests = User::query()
            ->whereNotNull('display_name')
            ->with('characters:id,name,user_id')
            ->get(['id', 'display_name']);
        foreach ($guests as $guest) {
            foreach ($guest->characters as $character) {
                $people[$character->name] ??= (string) $guest->display_name;
            }
        }

        return $people;
    }

    /**
     * Whether a name is already someone's (case-insensitive): a SeAT person or another guest.
     */
    public static function nameTaken(string $name, ?int $exceptUserId = null): bool
    {
        $lower = mb_strtolower(mb_trim($name));

        return Character::query()->whereRaw('lower(seat_person_name) = ?', [$lower])
            ->when($exceptUserId !== null, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('user_id')->orWhere('user_id', '!=', $exceptUserId)))
            ->exists()
            || User::query()->whereRaw('lower(display_name) = ?', [$lower])
                ->when($exceptUserId !== null, fn ($query) => $query->whereKeyNot($exceptUserId))
                ->exists();
    }

    /**
     * Patch 39: each character's person name from the SeAT plugin's bot API. Nothing
     * changes when the plugin can't be reached (or isn't connected).
     */
    private function syncPeople(): void
    {
        $client = app(SeatClient::class);
        if (! $client->configured()) {
            return;
        }

        try {
            $people = $client->people();
        } catch (SeatUnavailableException) {
            return;
        }

        $seen = [];
        foreach ($people as $person) {
            $ids = array_map(intval(...), $person['character_ids']);
            if ($ids === [] || $person['name'] === '') {
                continue;
            }
            Character::query()->whereKey($ids)->update(['seat_person_name' => mb_substr($person['name'], 0, 32)]);
            array_push($seen, ...$ids);
        }

        Character::query()
            ->whereNotNull('seat_person_name')
            ->when($seen !== [], fn ($query) => $query->whereKeyNot($seen))
            ->update(['seat_person_name' => null]);
    }
}
