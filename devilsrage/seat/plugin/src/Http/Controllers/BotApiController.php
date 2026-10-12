<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use CryptaTech\Seat\Fitting\Models\Fitting;
use DevilsRage\Seat\Fits\EsiFitConverter;
use DevilsRage\Seat\Models\Account;
use DevilsRage\Seat\Services\ExtractionValue;
use DevilsRage\Seat\Services\FarmOverview;
use DevilsRage\Seat\Services\FarmPrices;
use DevilsRage\Seat\Services\Health;
use DevilsRage\Seat\Services\Holdings;
use DevilsRage\Seat\Services\Leadership;
use DevilsRage\Seat\Services\OmegaStatus;
use DevilsRage\Seat\Services\StructureFuel;
use DevilsRage\Seat\Services\AssetLocator;
use DevilsRage\Seat\Skills\SkillCheck;
use DevilsRage\Seat\Skills\SkillMath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Web\Http\Controllers\Controller;
use Seat\Web\Models\User;
use Warlof\Seat\Connector\Models\User as ConnectorUser;

/**
 * Read-only answers for the mapper's Discord bot, looked up by Discord user ID
 * (the link pilots made in SeAT's Connector). Protected by the BotApiKey middleware.
 */
class BotApiController extends Controller
{
    public function jumpShips(string $discordId, AssetLocator $assets): JsonResponse
    {
        $user = $this->userFor($discordId);
        if ($user === null) {
            return $this->notLinked();
        }

        return response()->json(['ships' => $assets->jumpShips($user->associatedCharacterIds())]);
    }

    public function assets(Request $request, string $discordId, AssetLocator $assets): JsonResponse
    {
        $user = $this->userFor($discordId);
        if ($user === null) {
            return $this->notLinked();
        }

        $systems = $assets->systems($user->associatedCharacterIds(), $request->boolean('ships_only'));

        return response()->json(['systems' => array_slice($systems, 0, 300)]);
    }

    public function fits(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        $fits = Fitting::query()
            ->with('ship')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')
            ->limit(25)
            ->get()
            ->map(fn (Fitting $fit): array => [
                'id' => (int) $fit->fitting_id,
                'name' => (string) $fit->name,
                'ship' => (string) $fit->ship->typeName,
            ]);

        return response()->json(['fits' => $fits]);
    }

    public function fit(int $fittingId): JsonResponse
    {
        $fit = Fitting::query()->with(['items.type', 'ship'])->find($fittingId);
        if ($fit === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json([
            'id' => (int) $fit->fitting_id,
            'name' => (string) $fit->name,
            'ship' => (string) $fit->ship->typeName,
            'eft' => trim(preg_replace("/\n{3,}/", "\n\n", $fit->toEve()) ?? ''),
        ]);
    }

    public function canFly(string $discordId, int $fittingId, SkillCheck $skills): JsonResponse
    {
        $user = $this->userFor($discordId);
        if ($user === null) {
            return $this->notLinked();
        }

        $fit = Fitting::query()->with(['items', 'ship'])->find($fittingId);
        if ($fit === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $typeIds = $fit->items
            ->where('flag', '!=', EsiFitConverter::FLAG_CARGO)
            ->pluck('type_id')
            ->push($fit->ship_type_id)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $required = $skills->requiredFor($typeIds);
        $characterIds = array_map('intval', $user->associatedCharacterIds());
        $status = $skills->compare($characterIds, $required);
        $names = CharacterInfo::query()->whereIn('character_id', $characterIds)->pluck('name', 'character_id');
        $skillNames = $skills->names(array_keys($required));

        $characters = [];
        foreach ($status as $characterId => $row) {
            $characters[] = [
                'character' => (string) ($names[$characterId] ?? $characterId),
                'can_fly' => $row['missing'] === [],
                'close' => $row['missing'] !== [] && $row['seconds'] < SkillCheck::CLOSE_SECONDS,
                'train_time' => SkillMath::duration($row['seconds']),
                'seconds' => $row['seconds'],
                'missing' => array_map(
                    fn (int $skillId, int $level): string => ($skillNames[$skillId] ?? $skillId).' '.$level,
                    array_keys(array_slice($row['missing'], 0, 6, true)),
                    array_values(array_slice($row['missing'], 0, 6, true)),
                ),
                'missing_count' => count($row['missing']),
            ];
        }

        usort($characters, fn (array $a, array $b): int => $a['seconds'] <=> $b['seconds']);

        return response()->json([
            'fit' => ['id' => (int) $fit->fitting_id, 'name' => (string) $fit->name, 'ship' => (string) $fit->ship->typeName],
            'characters' => $characters,
        ]);
    }

    /**
     * Accounts with Omega / MCT state, PLEX (hangars + vault) and wallet ISK, for /omega and /plex.
     */
    public function accounts(string $discordId, OmegaStatus $omega, Holdings $holdings, FarmPrices $prices): JsonResponse
    {
        $user = $this->userFor($discordId);
        if ($user === null) {
            return $this->notLinked();
        }

        $characterIds = array_map('intval', $user->associatedCharacterIds());
        $names = CharacterInfo::query()->whereIn('character_id', $characterIds)->pluck('name', 'character_id');
        $accounts = Account::query()->where('user_id', $user->id)->with('characters')->orderBy('nickname')->get();
        $status = $omega->forAccounts($accounts);
        $held = $holdings->characters($characterIds);
        $byAccount = $holdings->accounts($accounts, $held);
        $date = fn ($value): ?string => $value?->format('Y-m-d');

        $inAccounts = [];
        $rows = [];
        foreach ($accounts as $account) {
            $om = $status[$account->id];
            $ids = $account->characters->pluck('character_id')->map(fn ($id): int => (int) $id)->all();
            $inAccounts = [...$inAccounts, ...$ids];
            $rows[] = [
                'nickname' => (string) $account->nickname,
                'characters' => $account->characters->map(fn ($link): string => (string) ($names[(int) $link->character_id] ?? $link->displayName()))->values()->all(),
                'omega' => ['state' => $om['state'], 'until' => $date($om['until']), 'days_left' => $om['days_left']],
                'mct' => ['slots' => $om['mct_slots'], 'state' => $om['mct_state'], 'until' => $date($om['mct_until']), 'days_left' => $om['mct_days_left']],
                'alpha' => array_map(fn (int $id): string => (string) ($names[$id] ?? $id), $om['alpha_characters']),
                'plex' => [
                    'hangar' => $byAccount[$account->id]['hangar'],
                    'vault' => $byAccount[$account->id]['vault'],
                    'vault_at' => $date($byAccount[$account->id]['vault_at']),
                    'total' => $byAccount[$account->id]['plex'],
                ],
                'isk' => $byAccount[$account->id]['isk'],
            ];
        }

        $loose = [];
        foreach (array_diff($characterIds, $inAccounts) as $id) {
            $loose[] = ['character' => (string) ($names[$id] ?? $id), 'plex' => $held[$id]['plex'] ?? 0, 'isk' => $held[$id]['isk'] ?? 0.0];
        }

        return response()->json([
            'accounts' => $rows,
            'loose' => $loose,
            'omega_plex_month' => $prices->omegaPlexPerMonth(24),
            'mct_plex_month' => $prices->mctPlexEach(24),
        ]);
    }

    /**
     * Skill points that could be extracted now without touching protected skills (/extract).
     */
    public function extract(string $discordId, FarmOverview $overview, ExtractionValue $value): JsonResponse
    {
        $user = $this->userFor($discordId);
        if ($user === null) {
            return $this->notLinked();
        }

        $characterIds = array_map('intval', $user->associatedCharacterIds());
        $names = CharacterInfo::query()->whereIn('character_id', $characterIds)->pluck('name', 'character_id');
        $perExtraction = $value->instantJita($characterIds);

        $characters = [];
        foreach ($overview->characters($characterIds, $perExtraction) as $id => $detail) {
            if ($detail['extract']['count'] === 0) {
                continue;
            }
            $characters[] = [
                'character' => (string) ($names[$id] ?? $id),
                'count' => $detail['extract']['count'],
                'isk' => $detail['extract']['isk'],
                'retrain_days' => $detail['extract']['retrain_days'],
                'skills' => $detail['extract']['skills'],
            ];
        }
        usort($characters, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return response()->json(['per_extraction' => $perExtraction, 'characters' => $characters]);
    }

    /**
     * Corp structure fuel (/fuel). Leaders only.
     */
    public function fuel(string $discordId, Leadership $leadership, StructureFuel $fuel): JsonResponse
    {
        $user = $this->userFor($discordId);
        if ($user === null) {
            return $this->notLinked();
        }

        if (! $leadership->isLeader($user)) {
            return response()->json(['error' => 'not_leader'], 403);
        }

        return response()->json(['structures' => $fuel->structures()]);
    }

    /**
     * Health of the SeAT logins and jobs (/health). Leaders only.
     */
    public function health(string $discordId, Leadership $leadership, Health $health): JsonResponse
    {
        $user = $this->userFor($discordId);
        if ($user === null) {
            return $this->notLinked();
        }

        if (! $leadership->isLeader($user)) {
            return response()->json(['error' => 'not_leader'], 403);
        }

        return response()->json($health->report());
    }

    private function userFor(string $discordId): ?User
    {
        if (! ctype_digit($discordId)) {
            return null;
        }

        $userId = ConnectorUser::query()
            ->where('connector_type', 'discord')
            ->where('connector_id', $discordId)
            ->value('user_id');

        return $userId === null ? null : User::query()->find($userId);
    }

    private function notLinked(): JsonResponse
    {
        return response()->json(['error' => 'not_linked'], 404);
    }
}
