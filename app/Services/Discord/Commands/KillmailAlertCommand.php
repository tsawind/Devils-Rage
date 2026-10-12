<?php

declare(strict_types=1);

namespace App\Services\Discord\Commands;

final readonly class KillmailAlertCommand implements AlertVariantDefinition
{
    use BuildsCommandOptions;

    public function definition(bool $withMentions): SubCommand
    {
        $subCommand = SubCommand::make('killmail', 'A kill happens within jumps of the map chain')->options(
            $this->mapOption(),
            Option::integer('jumps')->description('Maximum jumps from the chain (0 = only inside the chain)')->required()->between(0, 20),
        );

        if ($withMentions) {
            $subCommand->options(...$this->mentionOptions());
        }

        return $subCommand->options(Option::boolean('skip-highsec')->description('Skip kills in high-sec systems'));
    }
}
