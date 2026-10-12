<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use Illuminate\Support\Facades\Http;
use Warlof\Seat\Connector\Models\User as ConnectorUser;

/**
 * Direct messages from the SeAT Discord bot (the token saved in Connector → Settings)
 * to a SeAT user's linked Discord account.
 */
final class DiscordDm
{
    private const API = 'https://discord.com/api/v10';

    public function send(int $seatUserId, string $content): bool
    {
        $token = $this->token();
        $discordId = ConnectorUser::query()
            ->where('connector_type', 'discord')
            ->where('user_id', $seatUserId)
            ->value('connector_id');
        if ($token === null || $discordId === null) {
            return false;
        }

        $request = Http::withHeaders(['Authorization' => 'Bot '.$token])->acceptJson()->timeout(10);
        $channel = $request->post(self::API.'/users/@me/channels', ['recipient_id' => (string) $discordId]);
        if (! $channel->successful()) {
            return false;
        }

        return $request->post(self::API.'/channels/'.$channel->json('id').'/messages', [
            'content' => mb_substr($content, 0, 1900),
            'allowed_mentions' => ['parse' => []],
        ])->successful();
    }

    private function token(): ?string
    {
        $settings = setting('seat-connector.drivers.discord', true);
        $token = is_object($settings) ? ($settings->bot_token ?? null) : null;

        return is_string($token) && $token !== '' ? $token : null;
    }
}
