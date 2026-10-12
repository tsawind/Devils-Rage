<?php

declare(strict_types=1);

namespace App\Services\Discord\Commands;

use Discord\Parts\Interactions\ApplicationCommand;

trait ManagesChannelPermissions
{
    private function canManageChannels(ApplicationCommand $interaction): bool
    {
        $member = $interaction->member;

        return $member !== null && ($member->permissions->administrator || $member->permissions->manage_channels);
    }

    private function guildId(ApplicationCommand $interaction): ?string
    {
        return $interaction->guild_id === null ? null : (string) $interaction->guild_id;
    }

    private function channelId(ApplicationCommand $interaction): ?string
    {
        return $interaction->channel_id === null ? null : (string) $interaction->channel_id;
    }
}
