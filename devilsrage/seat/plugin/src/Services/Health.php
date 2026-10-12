<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use DevilsRage\Seat\Models\AccountCharacter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Corporation\CorporationRole;
use Seat\Eveapi\Models\RefreshToken;

/**
 * What can quietly break: the CEO's and directors' SeAT logins (structure attack and
 * fuel pings depend on them), pilots' characters whose login expired, failed jobs.
 */
final class Health
{
    /** A token SeAT refreshes every ~20 minutes; a day without a refresh means trouble. */
    private const STALE_HOURS = 24;

    public function __construct(private readonly Corporation $corporation) {}

    /**
     * @return array{leaders: list<array{name: string, role: string, status: string}>, expired: list<string>, expired_count: int, failed_jobs: int|null}
     */
    public function report(): array
    {
        $corporationId = $this->corporation->mainId();
        $ceoId = $this->corporation->ceoId();
        $directorIds = $corporationId === null ? [] : CorporationRole::query()
            ->where('corporation_id', $corporationId)
            ->where('role', 'Director')
            ->where('type', 'roles')
            ->distinct()
            ->pluck('character_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $leaderIds = array_values(array_unique(array_filter([$ceoId, ...$directorIds])));
        $names = CharacterInfo::query()->whereIn('character_id', $leaderIds)->pluck('name', 'character_id');
        $tokens = RefreshToken::withTrashed()->whereIn('character_id', $leaderIds)->get()->keyBy('character_id');

        $leaders = [];
        foreach ($leaderIds as $id) {
            $token = $tokens->get($id);
            $leaders[] = [
                'name' => (string) ($names[$id] ?? $id),
                'role' => $id === $ceoId ? 'CEO' : 'Director',
                'status' => match (true) {
                    $token === null => 'missing',
                    $token->trashed() => 'expired',
                    $token->updated_at !== null && Carbon::parse($token->updated_at)->lt(Carbon::now()->subHours(self::STALE_HOURS)) => 'stale',
                    default => 'ok',
                },
            ];
        }

        // Characters pilots put in accounts whose SeAT login was dropped by EVE.
        $accountIds = AccountCharacter::query()->where('external', false)->pluck('character_id')->map(fn ($id): int => (int) $id)->all();
        $expiredIds = RefreshToken::onlyTrashed()->whereIn('character_id', $accountIds)->pluck('character_id')
            ->diff(RefreshToken::query()->whereIn('character_id', $accountIds)->pluck('character_id'))
            ->map(fn ($id): int => (int) $id)->values()->all();
        $expiredNames = CharacterInfo::query()->whereIn('character_id', $expiredIds)->orderBy('name')->limit(15)->pluck('name')->all();

        return [
            'leaders' => $leaders,
            'expired' => array_map('strval', $expiredNames),
            'expired_count' => count($expiredIds),
            'failed_jobs' => Schema::hasTable('failed_jobs')
                ? DB::table('failed_jobs')->where('failed_at', '>', Carbon::now()->subDay())->count()
                : null,
        ];
    }
}
