<?php

declare(strict_types=1);

namespace App\Services\Discord\Commands;

use App\Actions\Discord\ManageChannelFeedAction;
use App\Actions\Discord\ShowStructureFuelAction;
use App\Enums\DiscordFeedKind;
use App\Models\DiscordAccount;
use Discord\Parts\Interactions\ApplicationCommand;

final readonly class FuelCommand implements CommandDefinition
{
    use ManagesChannelPermissions;
    use ReadsInteractionOptions;

    public function __construct(
        private ShowStructureFuelAction $fuel,
        private ManageChannelFeedAction $feeds,
    ) {}

    public function definition(): SlashCommand
    {
        return SlashCommand::make('fuel', 'Corp structure fuel from SeAT (corp leaders)')->options(
            SubCommand::make('now', 'Every corp structure and how long its fuel lasts'),
            SubCommand::make('daily', 'Post the fuel summary in this channel every day (Manage Channels)'),
            SubCommand::make('stop', 'Stop the daily fuel summary in this channel (Manage Channels)'),
        );
    }

    public function requiresLinkedAccount(): bool
    {
        return true;
    }

    public function respond(ApplicationCommand $interaction, ?DiscordAccount $account): string
    {
        return match ($this->subcommand($interaction)->name) {
            'now' => $this->fuel->handle($account->discord_user_id),
            'daily' => $this->feeds->start($account, DiscordFeedKind::FuelDaily, null, $this->guildId($interaction), $this->channelId($interaction), $this->canManageChannels($interaction)),
            'stop' => $this->feeds->stop(DiscordFeedKind::FuelDaily, null, $this->channelId($interaction), $this->canManageChannels($interaction)),
            default => 'That fuel command is unavailable.',
        };
    }
}
