<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use Illuminate\Support\Facades\Http;
use Warlof\Seat\Connector\Models\User as ConnectorUser;

/**
 * Direct messages from the SeAT Discord bot (the token saved in Connector → Settings)
 * to a SeAT user's linked Discord account (or, Patch 39, any member of the corp server).
 */
final class DiscordDm
{
    private const API = 'https://discord.com/api/v10';

    public function send(int $seatUserId, string $content): bool
    {
        return $this->trySend($seatUserId, $content) === null;
    }

    /**
     * Patch 62i: sends, and says why not (null when it went): no bot, no linked Discord, or
     * what Discord answered (DMs closed, not on the corp server…).
     */
    public function trySend(int $seatUserId, string $content): ?string
    {
        $discordId = ConnectorUser::query()
            ->where('connector_type', 'discord')
            ->where('user_id', $seatUserId)
            ->value('connector_id');
        if ($discordId === null) {
            return 'not_linked';
        }

        return $this->trySendTo((string) $discordId, $content);
    }

    /**
     * Patch 39: a DM to any Discord user the bot shares a server with (a member who isn't
     * on SeAT yet, say).
     */
    public function sendTo(string $discordId, string $content): bool
    {
        return $this->trySendTo($discordId, $content) === null;
    }

    /**
     * Patch 62i: null when sent; else 'no_bot', 'dms_closed' (Discord 50007: DMs from server
     * members off, or no server shared with the bot), or 'discord_<status>'.
     */
    public function trySendTo(string $discordId, string $content): ?string
    {
        $token = $this->token();
        if ($token === null) {
            return 'no_bot';
        }

        try {
            $request = Http::withHeaders(['Authorization' => 'Bot '.$token])->acceptJson()->timeout(10);
            $channel = $request->post(self::API.'/users/@me/channels', ['recipient_id' => $discordId]);
            if (! $channel->successful()) {
                return self::why($channel->status(), $channel->json('code'));
            }
            $sent = $request->post(self::API.'/channels/'.$channel->json('id').'/messages', [
                'content' => mb_substr($content, 0, 1900),
                'allowed_mentions' => ['parse' => []],
            ]);
        } catch (\Throwable) {
            return 'discord_unreachable';
        }

        return $sent->successful() ? null : self::why($sent->status(), $sent->json('code'));
    }

    private static function why(int $status, mixed $code): string
    {
        return (int) $code === 50007 ? 'dms_closed' : 'discord_'.$status;
    }

    /**
     * Patch 39: members of the corp's Discord server with a role whose name contains $role
     * (needs the Server Members Intent). Null when the bot can't read them.
     *
     * @return list<array{id: string, name: string}>|null
     */
    public function membersWithRole(string $role): ?array
    {
        $token = $this->token();
        $guildId = $this->guildId();
        if ($token === null || $guildId === null) {
            return null;
        }

        $request = Http::withHeaders(['Authorization' => 'Bot '.$token])->acceptJson()->timeout(15);
        $roles = $request->get(self::API.'/guilds/'.$guildId.'/roles');
        if (! $roles->successful()) {
            return null;
        }
        $roleIds = collect((array) $roles->json())
            ->filter(fn ($item): bool => is_array($item) && str_contains(mb_strtolower((string) ($item['name'] ?? '')), mb_strtolower($role)))
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();
        if ($roleIds === []) {
            return [];
        }

        $members = [];
        $after = '0';
        for ($page = 0; $page < 20; $page++) {
            $response = $request->get(self::API.'/guilds/'.$guildId.'/members', ['limit' => 1000, 'after' => $after]);
            if (! $response->successful()) {
                return null;
            }
            $batch = (array) $response->json();
            foreach ($batch as $member) {
                $user = (array) ($member['user'] ?? []);
                if (($user['bot'] ?? false) || array_intersect($roleIds, array_map('strval', (array) ($member['roles'] ?? []))) === []) {
                    continue;
                }
                $members[] = [
                    'id' => (string) ($user['id'] ?? ''),
                    'name' => (string) ($member['nick'] ?? $user['global_name'] ?? $user['username'] ?? ''),
                ];
            }
            if (count($batch) < 1000) {
                break;
            }
            $after = (string) (end($batch)['user']['id'] ?? $after);
        }

        return $members;
    }

    private function guildId(): ?string
    {
        $settings = setting('seat-connector.drivers.discord', true);
        $guild = is_object($settings) ? ($settings->guild_id ?? null) : null;

        return is_scalar($guild) && (string) $guild !== '' ? (string) $guild : null;
    }

    private function token(): ?string
    {
        $settings = setting('seat-connector.drivers.discord', true);
        $token = is_object($settings) ? ($settings->bot_token ?? null) : null;

        return is_string($token) && $token !== '' ? $token : null;
    }
}
