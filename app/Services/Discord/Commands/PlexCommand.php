<?php

declare(strict_types=1);

namespace App\Services\Discord\Commands;

use App\Actions\Discord\ShowSeatAccountsAction;
use App\Models\DiscordAccount;
use Discord\Parts\Interactions\ApplicationCommand;

final readonly class PlexCommand implements CommandDefinition
{
    public function __construct(private ShowSeatAccountsAction $accounts) {}

    public function definition(): SlashCommand
    {
        return SlashCommand::make('plex', 'Your PLEX and ISK per account, and how many months of Omega the PLEX covers');
    }

    public function requiresLinkedAccount(): bool
    {
        return false;
    }

    public function respond(ApplicationCommand $interaction, ?DiscordAccount $account): string
    {
        return $this->accounts->plex((string) $interaction->user->id);
    }
}
