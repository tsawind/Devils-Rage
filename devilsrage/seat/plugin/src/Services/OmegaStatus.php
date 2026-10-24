<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use DevilsRage\Seat\Models\Account;
use DevilsRage\Seat\Skills\SkillCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Seat\Eveapi\Models\Skills\CharacterAttribute;
use Seat\Eveapi\Models\Skills\CharacterSkillQueue;

/**
 * Omega per EVE account: the end date the pilot typed, plus a guess from training
 * speed. An Alpha account trains at half the Omega rate, so a character whose current
 * skill trains at under 75 % of the Omega rate for its attributes is flagged "Alpha?".
 * The guess only works while a character is training.
 */
final class OmegaStatus
{
    /** Days before the end date that count as "running out". */
    public const SOON_DAYS = 7;

    private const ALPHA_RATIO = 0.75;

    public function __construct(private readonly SkillCheck $skills) {}

    /**
     * @param  iterable<Account>  $accounts  with characters loaded
     * @return array<int, array{until: ?Carbon, days_left: ?int, auto: bool, mct_slots: int, mct_until: ?Carbon, mct_days_left: ?int, mct_auto: bool, mct_state: string, state: string, alpha: bool, alpha_characters: list<int>}>
     */
    public function forAccounts(iterable $accounts): array
    {
        $accounts = collect($accounts);
        $characterIds = $accounts->flatMap(fn (Account $account) => $account->characters->pluck('character_id'))->map(fn ($id): int => (int) $id)->all();
        $alpha = $this->alphaCharacters($characterIds);

        $result = [];
        foreach ($accounts as $account) {
            $until = $account->omega_until;
            $daysLeft = $until === null ? null : (int) Carbon::today()->diffInDays($until, false);
            $alphaCharacters = $account->characters->pluck('character_id')->map(fn ($id): int => (int) $id)->intersect($alpha)->values()->all();

            $mctDays = $account->mct_until === null ? null : (int) Carbon::today()->diffInDays($account->mct_until, false);

            $result[$account->id] = [
                'until' => $until,
                'days_left' => $daysLeft,
                'auto' => (bool) $account->omega_auto,
                'mct_slots' => (int) $account->mct_slots,
                'mct_until' => $account->mct_until,
                'mct_days_left' => $mctDays,
                'mct_auto' => false,
                'mct_state' => match (true) {
                    (int) $account->mct_slots === 0 => 'none',
                    $mctDays === null => 'unknown',
                    $mctDays < 0 => 'expired',
                    $mctDays <= self::SOON_DAYS => 'soon',
                    default => 'ok',
                },
                'state' => match (true) {
                    (bool) $account->omega_auto => 'auto',
                    $daysLeft === null => 'unknown',
                    $daysLeft < 0 => 'expired',
                    $daysLeft <= self::SOON_DAYS => 'soon',
                    default => 'ok',
                },
                'alpha' => $alphaCharacters !== [],
                'alpha_characters' => $alphaCharacters,
            ];
        }

        return $result;
    }

    /**
     * Characters whose skill in training right now goes at Alpha speed.
     *
     * @param  list<int>  $characterIds
     * @return list<int>
     */
    public function alphaCharacters(array $characterIds): array
    {
        $alpha = [];
        foreach ($this->trainingRates($characterIds) as $characterId => $rate) {
            if ($rate['actual'] < $rate['omega'] * self::ALPHA_RATIO) {
                $alpha[] = $characterId;
            }
        }

        return $alpha;
    }

    /**
     * Skill points per minute of the skill each character trains right now (actual),
     * and what Omega would give for that skill and the character's attributes.
     * Characters not training are left out.
     *
     * @param  list<int>  $characterIds
     * @return array<int, array{actual: float, omega: float}>
     */
    public function trainingRates(array $characterIds): array
    {
        if ($characterIds === []) {
            return [];
        }

        $now = Carbon::now();
        $training = CharacterSkillQueue::query()
            ->whereIn('character_id', $characterIds)
            ->where('start_date', '<=', $now)
            ->where('finish_date', '>', $now)
            ->orderBy('queue_position')
            ->get()
            ->unique('character_id');
        if ($training->isEmpty()) {
            return [];
        }

        $info = $this->skills->skillInfo($training->pluck('skill_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());
        $attributes = CharacterAttribute::query()->whereIn('character_id', $training->pluck('character_id'))->get()->keyBy('character_id');

        $rates = [];
        foreach ($training as $entry) {
            $skill = $info[(int) $entry->skill_id] ?? null;
            $minutes = Carbon::parse($entry->start_date)->diffInMinutes(Carbon::parse($entry->finish_date));
            $points = (int) $entry->level_end_sp - (int) $entry->training_start_sp;
            if ($skill === null || $minutes < 10 || $points <= 0) {
                continue;
            }

            $rates[(int) $entry->character_id] = [
                'actual' => $points / $minutes,
                'omega' => $this->skills->omegaRate($attributes->get($entry->character_id), $skill),
            ];
        }

        return $rates;
    }

    /**
     * @return Collection<int, string> badge text per state
     */
    public static function labels(): Collection
    {
        return collect(['ok' => 'Omega', 'soon' => 'Omega ends soon', 'expired' => 'Omega ended', 'unknown' => 'Omega ?']);
    }
}
