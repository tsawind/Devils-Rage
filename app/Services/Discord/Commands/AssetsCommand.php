<?php

declare(strict_types=1);

namespace App\Services\Discord\Commands;

use App\Actions\Discord\ListAssetsNearChainAction;
use App\Actions\Discord\ManageAssetWatchAction;
use App\Models\DiscordAccount;
use Discord\Parts\Interactions\ApplicationCommand;

final readonly class AssetsCommand implements CommandDefinition
{
    use BuildsCommandOptions;
    use ReadsInteractionOptions;

    public function __construct(
        private ListAssetsNearChainAction $listAssetsNear,
        private ManageAssetWatchAction $manageWatch,
    ) {}

    public function definition(): SlashCommand
    {
        return SlashCommand::make('assets', 'Your stuff near the chain (from SeAT)')->options(
            SubCommand::make('near', 'Your stuff within a few gate jumps of the chain exits')->options(
                $this->mapOption(),
                $this->jumpsOption(),
                $this->shipsOption(),
            ),
            SubCommand::make('watch', 'DM me when a new exit lands near my stuff')->options(
                $this->mapOption(),
                $this->jumpsOption(),
                $this->shipsOption(),
            ),
            SubCommand::make('stop', 'Stop asset watch DMs')->options(
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
            'near' => $this->listAssetsNear->handle($account, (int) $map, (int) $this->option($subcommand, 'jumps'), (bool) $this->option($subcommand, 'ships')),
            'watch' => $this->manageWatch->watch($account, (int) $map, (int) $this->option($subcommand, 'jumps'), (bool) $this->option($subcommand, 'ships')),
            'stop' => $this->manageWatch->stop($account, $map === null ? null : (int) $map),
            default => 'That assets command is unavailable.',
        };
    }

    private function shipsOption(): Option
    {
        return Option::boolean('ships')->description('Only count ships');
    }
}
