<?php

declare(strict_types=1);

namespace App\Actions\MapRally;

use App\Models\Map;
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
 * pinger's pick of mention (a saved role, @here, or none), a route and a note.
 * One ping per map every two minutes.
 */
final readonly class SendRallyPingAction
{
    use RetriesDiscordRateLimits;

    public const int COOLDOWN_SECONDS = 120;

    /**
     * @param  array{map_webhook_id: int, mention: string, route?: string|null, note?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function handle(Map $map, User $user, array $data): void
    {
        if ($map->rally_solarsystem_id === null) {
            throw ValidationException::withMessages(['map_webhook_id' => 'Set a rally point first.']);
        }

        $webhook = MapWebhook::query()->where('map_id', $map->id)->findOrFail($data['map_webhook_id']);

        if (! Cache::add($this->cooldownKey($map), true, self::COOLDOWN_SECONDS)) {
            throw ValidationException::withMessages(['map_webhook_id' => 'Someone pinged the rally point less than two minutes ago.']);
        }

        try {
            Http::timeout(10)
                ->retry(
                    3,
                    fn (int $attempt, Throwable $exception): int => $this->retryDelayMilliseconds($exception),
                    fn (Throwable $exception): bool => $this->wasRateLimited($exception),
                )
                ->post($webhook->discord_webhook_url, $this->payload($map, $user, $data))
                ->throw();
        } catch (Throwable) {
            Cache::forget($this->cooldownKey($map));

            throw ValidationException::withMessages(['map_webhook_id' => 'Discord did not take the ping. Check the webhook in the map\'s Discord settings.']);
        }
    }

    /**
     * @param  array{map_webhook_id: int, mention: string, route?: string|null, note?: string|null}  $data
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
        if (filled($data['route'] ?? null)) {
            $fields[] = ['name' => 'Route', 'value' => sprintf("```\n%s\n```", $data['route'])];
        }
        if (filled($data['note'] ?? null)) {
            $fields[] = ['name' => 'Note', 'value' => (string) $data['note']];
        }

        $payload = [
            'embeds' => [[
                'title' => sprintf('⚑ Form up at %s', $where),
                'color' => 0xEC4899,
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
        if ($mention === 'here') {
            return ['content' => '@here', 'allowed_mentions' => ['parse' => ['everyone']]];
        }

        if (str_starts_with($mention, 'role:')) {
            $role = MapWebhookRole::query()->where('map_id', $map->id)->find((int) mb_substr($mention, 5));

            return $role?->mention();
        }

        return null;
    }

    private function cooldownKey(Map $map): string
    {
        return sprintf('rally-ping:%d', $map->id);
    }
}
