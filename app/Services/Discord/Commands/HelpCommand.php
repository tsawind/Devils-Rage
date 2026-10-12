<?php

declare(strict_types=1);

namespace App\Services\Discord\Commands;

use App\Models\DiscordAccount;
use Discord\Parts\Interactions\ApplicationCommand;

final readonly class HelpCommand implements CommandDefinition
{
    public const string TEXT = <<<'TEXT'
**The Devil's Rage bot** · answers are only shown to you
**Map** (link once with `/account link`)
• `/route` · shortest route from a map to a system
• `/alert dm …` / `/alert channel …` · alerts: system near chain, kills near chain, capital jump range (`xl` = only when every hole from home is XL)
• `/alerts` · list, pause or delete your alerts
**SeAT** (link your Discord in SeAT → Connector → Identities)
• `/caps` · which of your jump-drive ships can reach a chain exit right now
• `/assets near` · your stuff within a few gate jumps of the chain's exits
• `/assets watch` · DM when a new exit lands near your stuff · `/assets stop` to end it
• `/fit` · a corp fit, ready to import in EVE
• `/canfly` · which of your characters can fly a corp fit, and what the others miss
TEXT;

    public function definition(): SlashCommand
    {
        return SlashCommand::make('help', 'What this bot can do');
    }

    public function requiresLinkedAccount(): bool
    {
        return false;
    }

    public function respond(ApplicationCommand $interaction, ?DiscordAccount $account): string
    {
        return self::TEXT;
    }
}
