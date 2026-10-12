<?php

declare(strict_types=1);

namespace App\Services\Discord\Commands;

use App\Actions\Discord\ManageChannelFeedAction;
use App\Actions\Discord\ShowHealthAction;
use App\Enums\DiscordFeedKind;
use App\Models\DiscordAccount;
use Discord\Parts\Interactions\ApplicationCommand;

final readonly class HealthCommand implements CommandDefinition
{
    use ManagesChannelPermissions;
    use ReadsInteractionOptions;

    public function __construct(
        private ShowHealthAction $health,
        private ManageChannelFeedAction $feeds,
    ) {}

    public function definition(): SlashCommand
    {
        return SlashCommand::make('health', 'Are SeAT logins, jobs and backups OK? (corp leaders)')->options(
            SubCommand::make('now', 'Check now'),
            SubCommand::make('daily', 'Post here when something breaks, checked daily (Manage Channels)'),
            SubCommand::make('stop', 'Stop the health posts in this channel (Manage Channels)'),
        );
    }

    public function requiresLinkedAccount(): bool
    {
        return true;
    }

    public function respond(ApplicationCommand $interaction, ?DiscordAccount $account): string
    {
        return match ($this->subcommand($interaction)->name) {
            'now' => $this->health->handle($account->discord_user_id),
            'daily' => $this->feeds->start($account, DiscordFeedKind::Health, null, $this->guildId($interaction), $this->channelId($interaction), $this->canManageChannels($interaction)),
            'stop' => $this->feeds->stop(DiscordFeedKind::Health, null, $this->channelId($interaction), $this->canManageChannels($interaction)),
            default => 'That health command is unavailable.',
        };
    }
}
