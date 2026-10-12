<?php

declare(strict_types=1);

namespace App\Services\Discord\Commands;

use App\Actions\Discord\ShowSeatFitAction;
use App\Models\DiscordAccount;
use Discord\Parts\Interactions\ApplicationCommand;

final readonly class FitCommand implements CommandDefinition
{
    use ReadsInteractionOptions;

    public function __construct(private ShowSeatFitAction $showFit) {}

    public function definition(): SlashCommand
    {
        return SlashCommand::make('fit', 'A corp fit, ready to import in EVE')->options(
            Option::string('name')->description('Fit name (part of it is enough)')->required(),
        );
    }

    public function requiresLinkedAccount(): bool
    {
        return false;
    }

    public function respond(ApplicationCommand $interaction, ?DiscordAccount $account): string
    {
        return $this->showFit->handle((string) $this->option($interaction->data, 'name'));
    }
}
