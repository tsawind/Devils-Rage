<?php

declare(strict_types=1);

namespace App\Services\Discord\Commands;

/**
 * Every slash command with its subcommands and options, read from the same definitions
 * that are registered with Discord, so /help and the settings page never go stale.
 */
final readonly class CommandCatalogue
{
    /** Commands that answer from the Devil's Rage SeAT. */
    private const array SEAT = ['caps', 'assets', 'fit', 'canfly', 'plex', 'omega', 'extract', 'fuel'];

    public function __construct(private CommandRegistry $registry) {}

    /**
     * @return list<array{name: string, description: string, section: string, needs: string, usages: list<array{usage: string, description: string, options: list<array{name: string, description: string, required: bool, choices: list<string>}>}>}>
     */
    public function all(): array
    {
        $commands = [];
        foreach ($this->registry->all() as $command) {
            $definition = $command->definition()->toArray();
            $name = (string) $definition['name'];
            $isSeat = in_array($name, self::SEAT, true);

            $commands[] = [
                'name' => $name,
                'description' => (string) $definition['description'],
                'section' => $isSeat ? 'SeAT' : 'Map',
                'needs' => match (true) {
                    $command->requiresLinkedAccount() && $isSeat => 'map account + SeAT Discord link',
                    $command->requiresLinkedAccount() => 'map account (/account link)',
                    $isSeat => 'SeAT Discord link',
                    default => 'nothing',
                },
                'usages' => $this->usages('/'.$name, $definition),
            ];
        }

        return $commands;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_column($this->all(), 'name');
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array{usage: string, description: string, options: list<array{name: string, description: string, required: bool, choices: list<string>}>}>
     */
    private function usages(string $prefix, array $node): array
    {
        $children = $node['options'] ?? [];
        $nested = array_values(array_filter($children, fn (array $option): bool => in_array($option['type'], [1, 2], true)));
        if ($nested === []) {
            return [[
                'usage' => $prefix,
                'description' => (string) $node['description'],
                'options' => array_map(fn (array $option): array => [
                    'name' => (string) $option['name'],
                    'description' => (string) ($option['description'] ?? ''),
                    'required' => (bool) ($option['required'] ?? false),
                    'choices' => array_map(fn (array $choice): string => (string) $choice['name'], $option['choices'] ?? []),
                ], $children),
            ]];
        }

        $usages = [];
        foreach ($nested as $child) {
            $usages = [...$usages, ...$this->usages($prefix.' '.$child['name'], $child)];
        }

        return $usages;
    }
}
