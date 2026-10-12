<?php

declare(strict_types=1);

namespace App\Services\Discord\Commands;

use App\Actions\Discord\ListCapitalReachAction;
use App\Models\DiscordAccount;
use Discord\Parts\Interactions\ApplicationCommand;

final readonly class CapsCommand implements CommandDefinition
{
    use BuildsCommandOptions;
    use ReadsInteractionOptions;

    public function __construct(private ListCapitalReachAction $listCapitalReach) {}

    public function definition(): SlashCommand
    {
        return SlashCommand::make('caps', 'Which of your jump-drive ships can reach a chain exit right now')
            ->options($this->mapOption());
    }

    public function requiresLinkedAccount(): bool
    {
        return true;
    }

    public function respond(ApplicationCommand $interaction, ?DiscordAccount $account): string
    {
        return $this->listCapitalReach->handle($account, (int) $this->option($interaction->data, 'map'));
    }
}
