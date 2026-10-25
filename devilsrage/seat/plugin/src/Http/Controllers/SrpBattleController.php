<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use Carbon\Carbon;
use DevilsRage\Seat\Srp\KillLinks;
use DevilsRage\Seat\Srp\KillResolver;
use DevilsRage\Seat\Srp\SrpFiler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Killmails\Killmail;
use Seat\Eveapi\Models\Killmails\KillmailDetail;
use Seat\Eveapi\Models\Killmails\KillmailVictim;
use Seat\Eveapi\Models\RefreshToken;
use Seat\Web\Http\Controllers\Controller;
use Seat\Web\Models\User;

/**
 * Patch 54: SRP for a whole fight. Paste a zKillboard or br.evetools "related" link (and/or any kill
 * links): every loss of a character registered in SeAT in that system around that time is listed
 * with its pilot's main, and the ticked ones are filed with the SRP plugin's own pricing.
 * SRP settlers file for anyone; a pilot with SRP request rights files their own losses.
 */
class SrpBattleController extends Controller
{
    public function index(Request $request, KillResolver $resolver): View
    {
        $link = trim((string) $request->query('link', ''));
        $extra = trim((string) $request->query('kills', ''));
        $battle = KillLinks::battle($link);
        $notes = [];
        $ids = [];

        if ($battle !== null) {
            $ids = KillmailDetail::query()
                ->where('solar_system_id', $battle['system'])
                ->whereBetween('killmail_time', [Carbon::createFromTimestampUTC($battle['from']), Carbon::createFromTimestampUTC($battle['to'])])
                ->pluck('killmail_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        } elseif ($link !== '' && KillLinks::kills($link) === []) {
            $notes[] = 'That link is neither a battle (zKillboard or br.evetools "related") nor a kill link.';
        }

        foreach (KillLinks::kills($link."\n".$extra) as $kill) {
            $hash = $kill['hash'] ?? $resolver->hash($kill['id']);
            if ($hash === null) {
                $notes[] = "Kill {$kill['id']}: no hash found (not in SeAT, zKillboard didn't answer).";
            } elseif ($resolver->load($kill['id'], $hash)) {
                $ids[] = $kill['id'];
            } else {
                $notes[] = "Kill {$kill['id']}: ESI didn't hand it over.";
            }
        }

        $rows = $this->losses(array_values(array_unique($ids)), $request->user());

        return view('devilsrage::srp.battle', [
            'link' => $link,
            'extra' => $extra,
            'battle' => $battle,
            'rows' => $rows['ours'],
            'others' => $rows['others'],
            'notes' => $notes,
            'srpInstalled' => class_exists(\CryptaTech\Seat\SeatSrp\Models\KillMail::class),
            'settler' => Gate::allows('srp.settle'),
        ]);
    }

    public function file(Request $request): RedirectResponse
    {
        $request->validate(['kill_ids' => ['required', 'array', 'max:200'], 'kill_ids.*' => ['integer']]);
        abort_unless(class_exists(\CryptaTech\Seat\SeatSrp\Models\KillMail::class), 404);
        $settler = Gate::allows('srp.settle');
        $me = (int) $request->user()->id;
        $filer = new SrpFiler;

        $filed = 0;
        $skipped = [];
        foreach (array_unique(array_map('intval', $request->input('kill_ids'))) as $killId) {
            $killmail = Killmail::query()->with('victim')->find($killId);
            $characterId = (int) ($killmail?->victim?->character_id ?? 0);
            $owner = (int) (RefreshToken::query()->where('character_id', $characterId)->value('user_id') ?? 0);
            if ($killmail === null || $owner === 0) {
                $skipped[] = "$killId (not one of ours)";

                continue;
            }
            if (! $settler && $owner !== $me) {
                $skipped[] = "$killId (someone else's)";

                continue;
            }
            $result = $filer->file($killmail, $owner);
            $result['ok'] ? $filed++ : $skipped[] = "$killId ({$result['message']})";
        }

        $message = "Filed SRP for $filed loss".($filed === 1 ? '' : 'es').'.'.($skipped ? ' Skipped: '.implode(', ', $skipped).'.' : '');

        return redirect()->route('devilsrage.srp.battle', array_filter(['link' => $request->input('link'), 'kills' => $request->input('kills')]))
            ->with($filed > 0 ? 'success' : 'warning', $message);
    }

    /**
     * @param  list<int>  $ids
     * @return array{ours: list<array<string, mixed>>, others: int}
     */
    private function losses(array $ids, User $viewer): array
    {
        if ($ids === []) {
            return ['ours' => [], 'others' => 0];
        }
        $victims = KillmailVictim::query()->whereIn('killmail_id', $ids)->with(['ship', 'character'])->get();
        $owners = RefreshToken::query()->whereIn('character_id', $victims->pluck('character_id')->filter())->pluck('user_id', 'character_id');
        $users = User::query()->whereIn('id', $owners->values()->unique())->get(['id', 'main_character_id'])->keyBy('id');
        $mainNames = CharacterInfo::query()->whereIn('character_id', $users->pluck('main_character_id')->filter())->pluck('name', 'character_id');
        $times = KillmailDetail::query()->whereIn('killmail_id', $ids)->pluck('killmail_time', 'killmail_id');
        $srp = class_exists(\CryptaTech\Seat\SeatSrp\Models\KillMail::class);

        $ours = [];
        $others = 0;
        foreach ($victims as $victim) {
            $owner = $owners[$victim->character_id] ?? null;
            if ($owner === null) {
                $others++;

                continue;
            }
            $user = $users[$owner] ?? null;
            $ours[] = [
                'kill_id' => (int) $victim->killmail_id,
                'time' => (string) ($times[$victim->killmail_id] ?? ''),
                'pilot' => $victim->character->name,
                'ship' => $victim->ship->typeName,
                'main' => $user ? ($mainNames[$user->main_character_id] ?? '—') : '—',
                'mine' => (int) $owner === (int) $viewer->id,
                'filed' => $srp && SrpFiler::filed((int) $victim->killmail_id),
            ];
        }
        usort($ours, fn (array $a, array $b): int => strcmp($a['time'], $b['time']));

        return ['ours' => $ours, 'others' => $others];
    }
}
