<?php

declare(strict_types=1);

namespace App\Services\Discord\Commands;

use App\Actions\Discord\ShowExtractionsAction;
use App\Models\DiscordAccount;
use Discord\Parts\Interactions\ApplicationCommand;

final readonly class ExtractCommand implements CommandDefinition
{
    public function __construct(private ShowExtractionsAction $extractions) {}

    public function definition(): SlashCommand
    {
        return SlashCommand::make('extract', 'Skill points you could sell now without losing skills the corp needs');
    }

    public function requiresLinkedAccount(): bool
    {
        return false;
    }

    public function respond(ApplicationCommand $interaction, ?DiscordAccount $account): string
    {
        return $this->extractions->handle((string) $interaction->user->id);
    }
}
