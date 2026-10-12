<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use DevilsRage\Seat\Skills\SkillCheck;
use DevilsRage\Seat\Skills\SkillMath;
use Illuminate\Support\Collection;
use Seat\Eveapi\Models\Sde\InvType;

/**
 * The Devil's Rage farm and "keep" skill lists. Skills are named here; rank,
 * training attributes and the book's Jita price come from SeAT's data, so a wrong
 * guess about a skill's attributes can't creep in.
 */
final class FarmSkills
{
    /** Everyone should have these (coffin Charons and Bowheads in wormholes). */
    public const EVERYONE = ['Caldari Freighter', 'ORE Freighter'];

    /** Big, slow skills worth farming on. Grouped on the page by their real attributes. */
    public const FARM = [
        'Amarr Freighter', 'Gallente Freighter', 'Minmatar Freighter',
        'Capital Ships', 'Minmatar Dreadnought', 'Minmatar Carrier',
        'Capital Repair Systems', 'Capital Remote Armor Repair Systems',
        'Capital Shield Operation', 'Capital Shield Emission Systems',
        'Cybernetics',
    ];

    /** Notes shown next to a skill. */
    public const NOTES = [
        'Minmatar Dreadnought' => 'Naglfar: keep at V if you have it',
        'Minmatar Carrier' => 'roller pilots; book is expensive',
        'Capital Ships' => 'needed for dreads and carriers',
        'Cybernetics' => 'better implants',
    ];

    /** Planetary interaction on farm characters. */
    public const PI = ['Command Center Upgrades' => 5, 'Interplanetary Consolidation' => 4];

    public const BIOLOGY = 'Biology';

    /** Never suggested for extraction. */
    public const KEEP = [
        'Caldari Freighter', 'ORE Freighter', 'Minmatar Dreadnought', 'Capital Ships', 'Cybernetics', 'Biology',
        'Command Center Upgrades', 'Interplanetary Consolidation',
    ];

    /** @var array<string, int>|null */
    private ?array $ids = null;

    public function __construct(
        private readonly SkillCheck $skills,
        private readonly JitaPrices $prices,
    ) {}

    /**
     * Skill name => type ID, for every skill named in this class.
     *
     * @return array<string, int>
     */
    public function ids(): array
    {
        if ($this->ids !== null) {
            return $this->ids;
        }

        $names = array_values(array_unique([...self::EVERYONE, ...self::FARM, ...array_keys(self::PI), self::BIOLOGY, ...self::KEEP]));

        return $this->ids = InvType::query()
            ->join('invGroups', 'invGroups.groupID', '=', 'invTypes.groupID')
            ->where('invGroups.categoryID', 16)
            ->whereIn('invTypes.typeName', $names)
            ->pluck('invTypes.typeID', 'invTypes.typeName')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Rank, attributes, SP to V, and book price of the farm and everyone skills.
     *
     * @return Collection<int, array{id: int, name: string, rank: float, attributes: string, sp_v: int, book: float, note: ?string, everyone: bool}>
     */
    public function catalogue(): Collection
    {
        $ids = $this->ids();
        $names = array_values(array_unique([...self::EVERYONE, ...self::FARM]));
        $wanted = array_values(array_filter(array_map(fn (string $name): ?int => $ids[$name] ?? null, $names)));
        $info = $this->skills->skillInfo($wanted);
        $books = $this->prices->get($wanted);

        return collect($names)
            ->filter(fn (string $name): bool => isset($ids[$name]))
            ->map(function (string $name) use ($ids, $info, $books): array {
                $id = $ids[$name];
                $skill = $info[$id] ?? ['rank' => 1.0, 'primary' => 165, 'secondary' => 166];

                return [
                    'id' => $id,
                    'name' => $name,
                    'rank' => $skill['rank'],
                    'attributes' => self::attributePair($skill['primary'], $skill['secondary']),
                    'sp_v' => SkillMath::skillPointsAt(5, $skill['rank']),
                    'book' => (float) ($books[$id]['sell'] ?? 0),
                    'note' => self::NOTES[$name] ?? null,
                    'everyone' => in_array($name, self::EVERYONE, true),
                ];
            })
            ->values();
    }

    /** "Per/Wil", "Int/Mem", ... (order-free, so Wil/Per counts as Per/Wil). */
    public static function attributePair(int $primary, int $secondary): string
    {
        $short = [164 => 'Cha', 165 => 'Int', 166 => 'Mem', 167 => 'Per', 168 => 'Wil'];
        $pair = [$short[$primary] ?? '?', $short[$secondary] ?? '?'];
        $order = ['Per' => 0, 'Wil' => 1, 'Int' => 2, 'Mem' => 3, 'Cha' => 4, '?' => 5];
        usort($pair, fn (string $a, string $b): int => $order[$a] <=> $order[$b]);

        return implode('/', $pair);
    }
}
