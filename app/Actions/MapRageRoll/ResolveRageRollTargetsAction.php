<?php

declare(strict_types=1);

namespace App\Actions\MapRageRoll;

use App\Models\Solarsystem;
use Illuminate\Validation\ValidationException;

/**
 * Patch 35: turn typed target names ("J123456", "thera") into solarsystem ids,
 * refusing any name that isn't a real system.
 */
final readonly class ResolveRageRollTargetsAction
{
    public const int MAX_TARGETS = 20;

    /**
     * @param  list<string>  $names
     * @return list<int>
     *
     * @throws ValidationException
     */
    public function handle(array $names): array
    {
        $wanted = collect($names)
            ->map(fn (string $name): string => mb_trim($name))
            ->filter(fn (string $name): bool => $name !== '')
            ->unique(fn (string $name): string => mb_strtoupper($name))
            ->values();

        if ($wanted->count() > self::MAX_TARGETS) {
            throw ValidationException::withMessages(['targets' => sprintf('At most %d target systems.', self::MAX_TARGETS)]);
        }

        if ($wanted->isEmpty()) {
            return [];
        }

        $found = Solarsystem::query()
            ->whereIn('name', $wanted->all())
            ->get(['id', 'name'])
            ->keyBy(fn (Solarsystem $solarsystem): string => mb_strtoupper($solarsystem->name));

        $missing = $wanted->reject(fn (string $name): bool => $found->has(mb_strtoupper($name)));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['targets' => 'Unknown system: '.$missing->implode(', ')]);
        }

        return $wanted
            ->map(fn (string $name): int => (int) $found->get(mb_strtoupper($name))?->id)
            ->values()
            ->all();
    }
}
