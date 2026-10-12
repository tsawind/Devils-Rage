<?php

declare(strict_types=1);

namespace App\Services\Discord\Commands;

use App\Models\DiscordAccount;
use Discord\Parts\Interactions\ApplicationCommand;

/**
 * "/help" (every command, one line each) and "/help command:x" (all its subcommands and options).
 * Built from the command definitions themselves.
 */
final readonly class HelpCommand implements CommandDefinition
{
    use ReadsInteractionOptions;

    /** Discord messages stop at 2000 characters; the handler trims at 1900. */
    private const int LIMIT = 1850;

    public function definition(): SlashCommand
    {
        $topic = Option::string('command')->description('Explain one command in detail');
        foreach (app(CommandRegistry::class)->all() as $command) {
            if (! $command instanceof self) {
                $name = $command->definition()->name();
                $topic->choice('/'.$name, $name);
            }
        }

        return SlashCommand::make('help', 'What this bot can do')->options($topic);
    }

    public function requiresLinkedAccount(): bool
    {
        return false;
    }

    public function respond(ApplicationCommand $interaction, ?DiscordAccount $account): string
    {
        $topic = $this->option($interaction->data, 'command');

        return $this->text($topic === null ? null : (string) $topic);
    }

    public function text(?string $topic): string
    {
        $commands = app(CommandCatalogue::class)->all();

        if ($topic !== null) {
            foreach ($commands as $command) {
                if ($command['name'] === $topic) {
                    return $this->detail($command);
                }
            }
        }

        $lines = ['**The Devil\'s Rage bot** · answers are only shown to you'];
        foreach (['Map' => 'link once with `/account link`', 'SeAT' => 'link your Discord in SeAT → Connector → Identities'] as $section => $hint) {
            $lines[] = sprintf('**%s** (%s)', $section, $hint);
            foreach ($commands as $command) {
                if ($command['section'] === $section && $command['name'] !== 'help') {
                    $lines[] = sprintf('• `/%s` · %s', $command['name'], $command['description']);
                }
            }
        }
        $lines[] = '-# `/help command:` explains one command with all its options. Everything is also on the map\'s Settings → Discord page.';

        return implode("\n", $lines);
    }

    /**
     * @param  array{name: string, description: string, needs: string, usages: list<array{usage: string, description: string, options: list<array{name: string, description: string, required: bool, choices: list<string>}>}>}  $command
     */
    private function detail(array $command): string
    {
        $full = $this->detailLines($command, true);

        return mb_strlen($full) <= self::LIMIT ? $full : $this->detailLines($command, false);
    }

    /**
     * @param  array{name: string, description: string, needs: string, usages: list<array{usage: string, description: string, options: list<array{name: string, description: string, required: bool, choices: list<string>}>}>}  $command
     */
    private function detailLines(array $command, bool $withOptionText): string
    {
        $lines = [sprintf('**/%s** · %s', $command['name'], $command['description']), '-# Needs: '.$command['needs']];
        foreach ($command['usages'] as $usage) {
            if (! $withOptionText) {
                $names = array_map(fn (array $option): string => $option['required'] ? $option['name'] : '['.$option['name'].']', $usage['options']);
                $lines[] = sprintf('`%s%s` · %s', $usage['usage'], $names === [] ? '' : ' '.implode(' ', $names), $usage['description']);

                continue;
            }

            $lines[] = sprintf('`%s` · %s', $usage['usage'], $usage['description']);
            foreach ($usage['options'] as $option) {
                $lines[] = sprintf(
                    '  ◦ `%s`%s · %s%s',
                    $option['name'],
                    $option['required'] ? '' : ' (optional)',
                    $option['description'],
                    $option['choices'] === [] ? '' : ' ('.implode(', ', $option['choices']).')',
                );
            }
        }

        return implode("\n", $lines);
    }
}
