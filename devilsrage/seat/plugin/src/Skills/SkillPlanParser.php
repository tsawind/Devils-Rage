<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Skills;

/**
 * Reads a skill plan pasted from EVE (Skill planner > copy to clipboard), EVEMon or a
 * hand-written list: one skill per line, "Skill Name 4" or "Skill Name IV".
 * Pure PHP, so it can be tested on its own; skill names are resolved later.
 */
final class SkillPlanParser
{
    private const ROMAN = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5];

    /**
     * Skills come back as name => highest level asked for, in first-seen order.
     *
     * @return array{skills: array<string, int>, ignored: list<string>}
     */
    public function parse(string $text): array
    {
        $skills = [];
        $ignored = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^(?:\d+[.)]\s*)?(.+?)\s+(?:level\s+)?([1-5]|I|II|III|IV|V)$/i', $line, $match) !== 1) {
                $ignored[] = $line;

                continue;
            }

            $name = trim($match[1]);
            $levelText = strtoupper($match[2]);
            $level = self::ROMAN[$levelText] ?? (int) $levelText;

            $key = $this->findKey($skills, $name) ?? $name;
            $skills[$key] = max($skills[$key] ?? 0, $level);
        }

        return ['skills' => $skills, 'ignored' => $ignored];
    }

    /**
     * @param  array<string, int>  $skills
     */
    private function findKey(array $skills, string $name): ?string
    {
        foreach (array_keys($skills) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                return $existing;
            }
        }

        return null;
    }
}
