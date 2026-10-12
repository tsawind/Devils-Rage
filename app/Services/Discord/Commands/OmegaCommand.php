<?php

declare(strict_types=1);

namespace App\Services\Discord\Commands;

use App\Actions\Discord\ShowSeatAccountsAction;
use App\Models\DiscordAccount;
use Discord\Parts\Interactions\ApplicationCommand;

final readonly class OmegaCommand implements CommandDefinition
{
    public function __construct(private ShowSeatAccountsAction $accounts) {}

    public function definition(): SlashCommand
    {
        return SlashCommand::make('omega', 'When Omega and MCT run out on each of your accounts');
    }

    public function requiresLinkedAccount(): bool
    {
        return false;
    }

    public function respond(ApplicationCommand $interaction, ?DiscordAccount $account): string
    {
        return $this->accounts->omega((string) $interaction->user->id);
    }
}
