<?php

declare(strict_types=1);

namespace App\Actions\MapRally;

use App\Models\Map;
use App\Models\MapRallyPing;
use App\Models\MapSolarsystem;
use App\Models\MapWebhook;
use App\Models\MapWebhookRole;
use App\Models\Solarsystem;
use App\Models\User;
use App\Services\Discord\RetriesDiscordRateLimits;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Patch 29: "Form up at the rally point" posted to a Discord webhook, with the
 * pinger's pick of mention (a saved role, @here, @everyone, or none), the ticked
 * routes and predictions, and a note. Patch 30: also "Rally moved" and "Stand
 * down" (no @here / @everyone for those), and every ping is logged.
 * One ping of each kind per map every two minutes.
 */
final readonly class SendRallyPingAction
{
    use RetriesDiscordRateLimits;

    public const int COOLDOWN_SECONDS = 120;

    private const int MAX_SECTIONS_LENGTH = 5000;

    /** @var array<string, array{verb: string, color: int}> */
    private const array KINDS = [
        'form_up' => ['verb' => '⚑ Form up at %s', 'color' => 0xEC4899],
        'moved' => ['verb' => '⚑ Rally moved: form up at %s', 'color' => 0xF97316],
        'stand_down' => ['verb' => '✋ Stand down (rally was %s)', 'color' => 0x64748B],
    ];

    /**
     * @param  array{kind?: string|null, map_webhook_id: int, mention: string, sections?: list<array{title: string, text: string}>|null, note?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function handle(Map $map, User $user, array $data): void
    {
        if ($map->rally_solarsystem_id === null) {
            throw ValidationException::withMessages(['map_webhook_id' => 'Set a rally point first.']);
        }

        $webhook = MapWebhook::query()->where('map_id', $map->id)->findOrFail($data['map_webhook_id']);

        $kind = $this->kind($data);
        $cooldownKey = $this->cooldownKey($map, $kind);
        if (! Cache::add($cooldownKey, true, self::COOLDOWN_SECONDS)) {
            throw ValidationException::withMessages(['map_webhook_id' => 'Someone sent this ping less than two minutes ago.']);
        }

        $payload = $this->payload($map, $user, $data);

        try {
            Http::timeout(10)
                ->retry(
                    3,
                    fn (int $attempt, Throwable $exception): int => $this->retryDelayMilliseconds($exception),
                    fn (Throwable $exception): bool => $this->wasRateLimited($exception),
                )
                ->post($webhook->discord_webhook_url, $payload)
                ->throw();
        } catch (Throwable) {
            Cache::forget($cooldownKey);

            throw ValidationException::withMessages(['map_webhook_id' => 'Discord did not take the ping. Check the webhook in the map\'s Discord settings.']);
        }

        MapRallyPing::query()->create([
            'map_id' => $map->id,
            'user_id' => $user->id,
            'character_name' => $user->active_character->name ?? null,
            'kind' => $kind,
            'title' => $payload['embeds'][0]['title'],
            'channel' => $webhook->name,
            'mention' => $payload['content'] ?? null,
        ]);
    }

    /**
     * @param  array{kind?: string|null, map_webhook_id: int, mention: string, sections?: list<array{title: string, text: string}>|null, note?: string|null}  $data
     * @return array<string, mixed>
     */
    public function payload(Map $map, User $user, array $data): array
    {
        $solarsystem = Solarsystem::query()->find($map->rally_solarsystem_id);
        $alias = MapSolarsystem::query()
            ->where('map_id', $map->id)
            ->where('solarsystem_id', $map->rally_solarsystem_id)
            ->value('alias');
        $name = $solarsystem->name ?? 'the rally point';
        $where = filled($alias) ? sprintf('%s (%s)', mb_trim((string) $alias), $name) : $name;

        $fields = [];
        $length = 0;
        // Discord allows 6000 characters per embed: sections past ~5000 are left out.
        foreach ($data['sections'] ?? [] as $section) {
            $value = sprintf("```\n%s\n```", $section['text']);
            if ($length + mb_strlen($value) > self::MAX_SECTIONS_LENGTH) {
                $fields[] = ['name' => 'More', 'value' => 'Too long for one ping: open the map for the rest.'];

                break;
            }
            $length += mb_strlen($value);
            $fields[] = ['name' => $section['title'], 'value' => $value];
        }
        if (filled($data['note'] ?? null)) {
            $fields[] = ['name' => 'Note', 'value' => (string) $data['note']];
        }

        $kind = self::KINDS[$this->kind($data)];
        $payload = [
            'embeds' => [[
                'title' => sprintf($kind['verb'], $where),
                'color' => $kind['color'],
                'fields' => $fields,
                'footer' => ['text' => sprintf('Pinged by %s · %s', $user->active_character->name ?? 'a pilot', $map->name)],
                'timestamp' => now()->toIso8601String(),
            ]],
            'allowed_mentions' => ['parse' => []],
        ];

        $mention = $this->mention($map, $data['mention']);
        if ($mention !== null) {
            $payload['content'] = $mention['content'];
            $payload['allowed_mentions'] = $mention['allowed_mentions'];
        }

        return $payload;
    }

    /**
     * @return array{content: string, allowed_mentions: array<string, mixed>}|null
     */
    private function mention(Map $map, string $mention): ?array
    {
        if ($mention === 'here' || $mention === 'everyone') {
            return ['content' => '@'.$mention, 'allowed_mentions' => ['parse' => ['everyone']]];
        }

        if (str_starts_with($mention, 'role:')) {
            $role = MapWebhookRole::query()->where('map_id', $map->id)->find((int) mb_substr($mention, 5));

            return $role?->mention();
        }

        return null;
    }

    /**
     * @param  array{kind?: string|null}  $data
     */
    private function kind(array $data): string
    {
        $kind = $data['kind'] ?? 'form_up';

        return array_key_exists((string) $kind, self::KINDS) ? (string) $kind : 'form_up';
    }

    private function cooldownKey(Map $map, string $kind): string
    {
        return sprintf('rally-ping:%d:%s', $map->id, $kind);
    }
}
