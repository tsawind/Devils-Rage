<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use CryptaTech\Seat\Fitting\Models\FittingItem;
use CryptaTech\Seat\Fitting\Models\Fitting;
use DevilsRage\Seat\Fits\EsiFitConverter;
use DevilsRage\Seat\Models\SkillPlanSkill;
use DevilsRage\Seat\Skills\FarmMath;
use DevilsRage\Seat\Skills\SkillCheck;
use DevilsRage\Seat\Skills\TradeMath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\Character\CharacterInfoSkill;
use Seat\Eveapi\Models\Character\CharacterSkill;
use Seat\Eveapi\Models\Clones\CharacterImplant;
use Seat\Eveapi\Models\Sde\InvType;
use Seat\Eveapi\Models\Skills\CharacterAttribute;
use Seat\Eveapi\Models\Skills\CharacterSkillQueue;

/**
 * Per character: implants, remap, Biology / PI / farm skills, what it trains and how
 * fast, extractions ready, and which skill points could be extracted for quick ISK
 * without touching anything a corp doctrine, skill plan or the keep list needs.
 */
final class FarmOverview
{
    /** Implant attribute bonus attribute IDs => short attribute name. */
    private const IMPLANT_BONUS = [175 => 'Cha', 176 => 'Int', 177 => 'Mem', 178 => 'Per', 179 => 'Wil'];

    private const COLUMNS = ['Cha' => 'charisma', 'Int' => 'intelligence', 'Mem' => 'memory', 'Per' => 'perception', 'Wil' => 'willpower'];

    public function __construct(
        private readonly FarmSkills $farmSkills,
        private readonly SkillCheck $skillCheck,
        private readonly OmegaStatus $omega,
    ) {}

    /**
     * @param  list<int>  $characterIds
     * @param  float  $profitPerExtraction  ISK one extraction earns (instant sale)
     * @return array<int, array<string, mixed>> per character ID
     */
    public function characters(array $characterIds, float $profitPerExtraction): array
    {
        if ($characterIds === []) {
            return [];
        }

        $ids = $this->farmSkills->ids();
        $sp = CharacterInfoSkill::query()->whereIn('character_id', $characterIds)->get()->keyBy('character_id');
        $attributes = CharacterAttribute::query()->whereIn('character_id', $characterIds)->get()->keyBy('character_id');
        $implants = $this->implantBonuses($characterIds);
        $skills = CharacterSkill::query()
            ->whereIn('character_id', $characterIds)
            ->get(['character_id', 'skill_id', 'skillpoints_in_skill', 'trained_skill_level'])
            ->groupBy('character_id');
        $rates = $this->omega->trainingRates($characterIds);
        $training = $this->training($characterIds);
        $protected = $this->protectedSkillIds();
        $skillNames = InvType::query()
            ->whereIn('typeID', $skills->flatten()->pluck('skill_id')->unique()->all())
            ->pluck('typeName', 'typeID');

        $result = [];
        foreach ($characterIds as $characterId) {
            $levels = ($skills->get($characterId) ?? collect())->keyBy('skill_id');
            $level = fn (string $name): int => (int) ($levels->get($ids[$name] ?? 0)?->trained_skill_level ?? 0);
            $totalSp = (int) ($sp->get($characterId)?->total_sp ?? 0);
            $bonus = $implants[$characterId] ?? [];
            $remap = $this->remap($attributes->get($characterId), $bonus);

            $candidates = ($skills->get($characterId) ?? collect())
                ->reject(fn ($row): bool => isset($protected[(int) $row->skill_id]))
                ->sortByDesc('skillpoints_in_skill');
            $candidateSp = (int) $candidates->sum('skillpoints_in_skill');
            $extractable = min(FarmMath::extractorsReady($totalSp), intdiv($candidateSp, FarmMath::SP_PER_EXTRACTOR));
            $spPerMinute = $rates[$characterId]['omega'] ?? $this->restingRate($attributes->get($characterId));

            $result[$characterId] = [
                'total_sp' => $totalSp,
                'unallocated' => (int) ($sp->get($characterId)?->unallocated_sp ?? 0),
                'ready' => FarmMath::extractorsReady($totalSp),
                'implants' => $bonus,
                'remap' => $remap,
                'biology' => $level(FarmSkills::BIOLOGY),
                'pi' => collect(FarmSkills::PI)->map(fn (int $want, string $name): array => ['have' => $level($name), 'want' => $want])->all(),
                'everyone' => collect(FarmSkills::EVERYONE)->mapWithKeys(fn (string $name): array => [$name => $level($name)])->all(),
                'farm_levels' => collect(FarmSkills::FARM)->mapWithKeys(fn (string $name): array => [$name => $level($name)])->all(),
                'training' => $training[$characterId] ?? null,
                'sp_per_hour' => isset($rates[$characterId]) ? $rates[$characterId]['actual'] * 60 : null,
                'extract' => [
                    'count' => $extractable,
                    'isk' => $extractable * $profitPerExtraction,
                    'retrain_days' => $spPerMinute > 0 ? $extractable * FarmMath::SP_PER_EXTRACTOR / $spPerMinute / 1440 : null,
                    'skills' => $candidates->take(5)->map(fn ($row): array => [
                        'name' => (string) ($skillNames[$row->skill_id] ?? $row->skill_id),
                        'level' => (int) $row->trained_skill_level,
                        'sp' => (int) $row->skillpoints_in_skill,
                    ])->values()->all(),
                ],
            ];
        }

        return $result;
    }

    /**
     * Skills nobody should extract: anything a seat-fitting fit or a corp skill plan
     * needs (with prerequisites), plus the keep list. Cached for an hour.
     *
     * @return array<int, true>
     */
    public function protectedSkillIds(): array
    {
        return Cache::remember('devilsrage.farm.protected', 3600, function (): array {
            $types = FittingItem::query()
                ->where('flag', '!=', EsiFitConverter::FLAG_CARGO)
                ->distinct()
                ->pluck('type_id')
                ->merge(Fitting::query()->distinct()->pluck('ship_type_id'))
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            $planSkills = SkillPlanSkill::query()->distinct()->pluck('skill_id')->map(fn ($id): int => (int) $id)->all();
            $keep = array_values(array_intersect_key($this->farmSkills->ids(), array_flip(FarmSkills::KEEP)));

            $required = array_keys($this->skillCheck->requiredFor([...$types, ...$planSkills, ...$keep]));

            return array_fill_keys([...$required, ...$planSkills, ...$keep], true);
        });
    }

    /**
     * @param  list<int>  $characterIds
     * @return array<int, array<string, int>> character => attribute => best implant bonus
     */
    private function implantBonuses(array $characterIds): array
    {
        $rows = CharacterImplant::query()->whereIn('character_id', $characterIds)->get(['character_id', 'type_id']);
        if ($rows->isEmpty()) {
            return [];
        }

        $bonuses = DB::table('dgmTypeAttributes')
            ->whereIn('typeID', $rows->pluck('type_id')->unique()->all())
            ->whereIn('attributeID', array_keys(self::IMPLANT_BONUS))
            ->get(['typeID', 'attributeID', 'valueInt', 'valueFloat'])
            ->groupBy('typeID');

        $result = [];
        foreach ($rows as $row) {
            foreach ($bonuses->get($row->type_id) ?? [] as $bonus) {
                $value = (int) ($bonus->valueInt ?? $bonus->valueFloat);
                $attribute = self::IMPLANT_BONUS[(int) $bonus->attributeID];
                if ($value > 0) {
                    $result[(int) $row->character_id][$attribute] = max($result[(int) $row->character_id][$attribute] ?? 0, $value);
                }
            }
        }

        return $result;
    }

    /**
     * The two attributes the character is mapped for (implants taken off), e.g. "Per/Wil".
     *
     * @param  array<string, int>  $implants
     */
    private function remap(?CharacterAttribute $attributes, array $implants): ?string
    {
        if ($attributes === null) {
            return null;
        }

        $base = [];
        foreach (self::COLUMNS as $short => $column) {
            $base[$short] = (int) $attributes->{$column} - ($implants[$short] ?? 0);
        }
        arsort($base);
        $top = array_slice(array_keys($base), 0, 2);
        $values = array_values($base);
        if (($values[0] ?? 0) - ($values[2] ?? 0) < 3) {
            return 'even';
        }

        $order = ['Per' => 0, 'Wil' => 1, 'Int' => 2, 'Mem' => 3, 'Cha' => 4];
        usort($top, fn (string $a, string $b): int => $order[$a] <=> $order[$b]);

        return implode('/', $top);
    }

    /**
     * SP per minute on the character's two best attributes (when it isn't training).
     */
    private function restingRate(?CharacterAttribute $attributes): float
    {
        if ($attributes === null) {
            return 0.0;
        }

        $values = array_map(fn (string $column): int => (int) $attributes->{$column}, self::COLUMNS);
        rsort($values);

        return TradeMath::spPerMinute($values[0], $values[1]);
    }

    /**
     * @param  list<int>  $characterIds
     * @return array<int, array{skill: string, level: int, ends: Carbon, queue_ends: Carbon}>
     */
    private function training(array $characterIds): array
    {
        $now = Carbon::now();
        $queue = CharacterSkillQueue::query()
            ->whereIn('character_id', $characterIds)
            ->where('finish_date', '>', $now)
            ->orderBy('queue_position')
            ->get()
            ->groupBy('character_id');
        $names = InvType::query()->whereIn('typeID', $queue->flatten()->pluck('skill_id')->unique()->all())->pluck('typeName', 'typeID');

        $result = [];
        foreach ($queue as $characterId => $entries) {
            $current = $entries->first();
            $result[(int) $characterId] = [
                'skill' => (string) ($names[$current->skill_id] ?? $current->skill_id),
                'level' => (int) $current->finished_level,
                'ends' => Carbon::parse($current->finish_date),
                'queue_ends' => Carbon::parse($entries->max('finish_date')),
            ];
        }

        return $result;
    }
}
