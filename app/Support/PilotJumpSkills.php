<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Character;
use App\Models\EsiToken;
use App\Models\Type;
use Illuminate\Support\Facades\Cache;
use NicolasKion\Esi\DTO\CharacterSkills;
use NicolasKion\Esi\DTO\Skill;
use NicolasKion\Esi\Enums\EsiScope;
use NicolasKion\Esi\Esi;
use Throwable;

/**
 * Patch 37: the jump skills of a pilot, read with the Skills permission (cached an hour):
 * Jump Drive Calibration, Capital Jump Portal Generation, Command Carriers and the four
 * racial Carrier skills. Null when the pilot hasn't granted Skills or ESI fails.
 */
final readonly class PilotJumpSkills
{
    public const array SKILLS = [
        'jdc' => 'Jump Drive Calibration',
        'portal' => 'Capital Jump Portal Generation',
        'command' => 'Command Carriers',
        'amarr' => 'Amarr Carrier',
        'caldari' => 'Caldari Carrier',
        'gallente' => 'Gallente Carrier',
        'minmatar' => 'Minmatar Carrier',
    ];

    public function __construct(private Esi $esi) {}

    /**
     * @return array<string, int>|null skill key → active level
     */
    public function levels(Character $character): ?array
    {
        if (! $character->getEsiTokenWithScope(EsiScope::ReadSkills) instanceof EsiToken) {
            return null;
        }

        /** @var array<string, int>|null */
        return Cache::remember(sprintf('pilot-jump-skills:%d', $character->id), now()->addHour(), function () use ($character): ?array {
            try {
                $result = $this->esi->getCharacterSkills($character);
            } catch (Throwable) {
                return null;
            }
            /** @var mixed $data */
            $data = $result->data;
            if ($result->failed() || ! $data instanceof CharacterSkills) {
                return null;
            }

            $ids = self::skillIds();
            $byId = [];
            /** @var array<int, mixed> $skills */
            $skills = $data->skills;
            foreach ($skills as $skill) {
                if ($skill instanceof Skill) {
                    $byId[$skill->skill_id] = $skill->active_skill_level;
                }
            }

            $levels = [];
            foreach (self::SKILLS as $key => $name) {
                $levels[$key] = isset($ids[$name]) ? ($byId[$ids[$name]] ?? 0) : 0;
            }

            return $levels;
        });
    }

    /**
     * @return array<string, int> skill name → type id
     */
    private static function skillIds(): array
    {
        /** @var array<string, int> */
        return Cache::remember('pilot-jump-skills:ids', now()->addDay(), fn (): array => Type::query()
            ->whereIn('name', array_values(self::SKILLS))
            ->pluck('id', 'name')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }
}
