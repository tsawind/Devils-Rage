<?php

declare(strict_types=1);

namespace App\Services\Discord\Commands;

use App\Actions\Discord\CheckCanFlyAction;
use App\Models\DiscordAccount;
use Discord\Parts\Interactions\ApplicationCommand;

final readonly class CanFlyCommand implements CommandDefinition
{
    use ReadsInteractionOptions;

    public function __construct(private CheckCanFlyAction $checkCanFly) {}

    public function definition(): SlashCommand
    {
        return SlashCommand::make('canfly', 'Which of your characters can fly a corp fit')->options(
            Option::string('fit')->description('Fit name (part of it is enough)')->required(),
        );
    }

    public function requiresLinkedAccount(): bool
    {
        return false;
    }

    public function respond(ApplicationCommand $interaction, ?DiscordAccount $account): string
    {
        return $this->checkCanFly->handle((string) $interaction->user->id, (string) $this->option($interaction->data, 'fit'));
    }
}
