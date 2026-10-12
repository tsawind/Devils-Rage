<?php

declare(strict_types=1);

namespace App\Services\Discord\Commands;

use App\Actions\Discord\ListChainExitsAction;
use App\Actions\Discord\ManageChannelFeedAction;
use App\Enums\DiscordFeedKind;
use App\Models\DiscordAccount;
use Discord\Parts\Interactions\ApplicationCommand;

final readonly class ExitsCommand implements CommandDefinition
{
    use BuildsCommandOptions;
    use ManagesChannelPermissions;
    use ReadsInteractionOptions;

    public function __construct(
        private ListChainExitsAction $listExits,
        private ManageChannelFeedAction $feeds,
    ) {}

    public function definition(): SlashCommand
    {
        return SlashCommand::make('exits', 'The chain\'s k-space exits')->options(
            SubCommand::make('now', 'List the exits with security, region and nearest trade hub')->options($this->mapOption()),
            SubCommand::make('here', 'Post every new exit of a map in this channel (Manage Channels)')->options($this->mapOption()),
            SubCommand::make('stop', 'Stop posting new exits in this channel (Manage Channels)')->options(
                Option::string('map')->description('Only this map (default: all maps)')->autocomplete(),
            ),
        );
    }

    public function requiresLinkedAccount(): bool
    {
        return true;
    }

    public function respond(ApplicationCommand $interaction, ?DiscordAccount $account): string
    {
        $subcommand = $this->subcommand($interaction);
        $map = $this->option($subcommand, 'map');

        return match ($subcommand->name) {
            'now' => $this->listExits->handle($account, (int) $map),
            'here' => $this->feeds->start($account, DiscordFeedKind::ChainExits, (int) $map, $this->guildId($interaction), $this->channelId($interaction), $this->canManageChannels($interaction)),
            'stop' => $this->feeds->stop(DiscordFeedKind::ChainExits, $map === null ? null : (int) $map, $this->channelId($interaction), $this->canManageChannels($interaction)),
            default => 'That exits command is unavailable.',
        };
    }
}
