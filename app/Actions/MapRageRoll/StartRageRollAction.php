<?php

declare(strict_types=1);

namespace App\Actions\MapRageRoll;

use App\Models\Map;
use App\Models\MapRallyPing;
use App\Models\MapSolarsystem;
use App\Models\MapWebhook;
use App\Models\Solarsystem;
use App\Models\User;
use App\Services\Discord\RetriesDiscordRateLimits;
use App\Support\Broadcasting\MapBroadcaster;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Patch 35: "RAGE ROLL" from a system's right-click menu. Starts the map-wide red
 * mode for everyone and (unless "Don't ping" was picked) posts it to a Discord
 * webhook with the system, its static, the nearest way into k-space, the targets
 * and a note. One rage roll ping per map every two minutes; every ping is logged
 * with the rally pings.
 */
final readonly class StartRageRollAction
{
    use RetriesDiscordRateLimits;

    public const int COOLDOWN_SECONDS = 120;

    public const string KIND = 'rage_roll';

    private const int COLOR = 0xDC2626;

    public function __construct(
        private ResolveRageRollTargetsAction $resolveTargets,
        private MapBroadcaster $mapBroadcaster,
    ) {}

    /**
     * @param  array{solarsystem_id: int, map_webhook_id?: int|null, mention?: string|null, system_text?: string|null, static_text?: string|null, kspace_text?: string|null, note?: string|null, targets?: list<string>|null, scanning?: bool|null}  $data
     *
     * @throws ValidationException
     */
    public function handle(Map $map, User $user, array $data): void
    {
        $target_ids = $this->resolveTargets->handle($data['targets'] ?? []);

        if (filled($data['map_webhook_id'] ?? null)) {
            $this->ping($map, $user, $data, $target_ids);
        }

        $map->update([
            'rage_roll_solarsystem_id' => $data['solarsystem_id'],
            'rage_roll_started_at' => now(),
            'rage_roll_started_by' => $user->active_character->name ?? null,
            'rage_roll_targets' => $target_ids,
            'rage_roll_scanning' => (bool) ($data['scanning'] ?? false),
        ]);

        $this->mapBroadcaster->metadataUpdated($map);
    }

    /**
     * @param  array{solarsystem_id: int, map_webhook_id?: int|null, mention?: string|null, system_text?: string|null, static_text?: string|null, kspace_text?: string|null, note?: string|null, targets?: list<string>|null, scanning?: bool|null}  $data
     * @param  list<int>  $target_ids
     *
     * @throws ValidationException
     */
    private function ping(Map $map, User $user, array $data, array $target_ids): void
    {
        $webhook = MapWebhook::query()->where('map_id', $map->id)->findOrFail($data['map_webhook_id']);

        $cooldown_key = sprintf('rally-ping:%d:%s', $map->id, self::KIND);
        if (! Cache::add($cooldown_key, true, self::COOLDOWN_SECONDS)) {
            throw ValidationException::withMessages(['map_webhook_id' => 'Someone sent a rage roll ping less than two minutes ago. Pick "Don\'t ping" to start the roll anyway.']);
        }

        $payload = $this->payload($map, $user, $data, $target_ids);

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
            Cache::forget($cooldown_key);

            throw ValidationException::withMessages(['map_webhook_id' => 'Discord did not take the ping. Check the webhook in the map\'s Discord settings.']);
        }

        MapRallyPing::query()->create([
            'map_id' => $map->id,
            'user_id' => $user->id,
            'character_name' => $user->active_character->name ?? null,
            'kind' => self::KIND,
            'title' => $payload['embeds'][0]['title'],
            'channel' => $webhook->name,
            'mention' => $payload['content'] ?? null,
        ]);
    }

    /**
     * @param  array{solarsystem_id: int, mention?: string|null, system_text?: string|null, static_text?: string|null, kspace_text?: string|null, note?: string|null, scanning?: bool|null}  $data
     * @param  list<int>  $target_ids
     * @return array<string, mixed>
     */
    public function payload(Map $map, User $user, array $data, array $target_ids): array
    {
        $alias = MapSolarsystem::query()
            ->where('map_id', $map->id)
            ->where('solarsystem_id', $data['solarsystem_id'])
            ->value('alias');
        $name = Solarsystem::query()->whereKey($data['solarsystem_id'])->value('name') ?? 'a system';
        $label = filled($data['system_text'] ?? null)
            ? (string) $data['system_text']
            : (filled($alias) && mb_trim((string) $alias) !== $name ? sprintf('%s (%s)', mb_trim((string) $alias), $name) : $name);
        $title = sprintf('⚡ RAGE ROLL: %s', $label);

        $fields = [];
        if ((bool) ($data['scanning'] ?? false)) {
            $title .= ' + RAGE SCANNING';
            $fields[] = ['name' => '⚔ Rage Scanning session', 'value' => 'Turn on your Rage speed for the scanner. The mapper switches everyone to Rage speed now and back off when the roll ends.'];
        }
        if (filled($data['static_text'] ?? null)) {
            $fields[] = ['name' => 'Static', 'value' => (string) $data['static_text']];
        }
        $fields[] = ['name' => 'Nearest k-space', 'value' => filled($data['kspace_text'] ?? null) ? (string) $data['kspace_text'] : 'No k-space exit on the map'];
        if ($target_ids !== []) {
            $names = Solarsystem::query()->whereIn('id', $target_ids)->pluck('name')->all();
            $fields[] = ['name' => 'Rolling for', 'value' => implode(', ', $names)];
        }
        if (filled($data['note'] ?? null)) {
            $fields[] = ['name' => 'Note', 'value' => (string) $data['note']];
        }

        $payload = [
            'embeds' => [[
                'title' => $title,
                'color' => self::COLOR,
                'fields' => $fields,
                'footer' => ['text' => sprintf('%s · %s · open the map to join', $user->active_character->name ?? 'a pilot', $map->name)],
                'timestamp' => now()->toIso8601String(),
            ]],
            'allowed_mentions' => ['parse' => []],
        ];

        $mention = $data['mention'] ?? 'none';
        if ($mention === 'here' || $mention === 'everyone') {
            $payload['content'] = '@'.$mention;
            $payload['allowed_mentions'] = ['parse' => ['everyone']];
        }

        return $payload;
    }
}
