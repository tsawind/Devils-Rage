<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use DevilsRage\Seat\Models\Reminder;
use DevilsRage\Seat\Services\Corporation;
use DevilsRage\Seat\Services\DiscordDm;
use DevilsRage\Seat\Services\GettingStarted;
use DevilsRage\Seat\Services\Leadership;
use DevilsRage\Seat\Services\PersonName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Seat\Eveapi\Models\RefreshToken;
use Seat\Web\Http\Controllers\Controller;
use Seat\Web\Models\User;
use Warlof\Seat\Connector\Models\User as ConnectorUser;

/**
 * Patch 39: one-time reminders (leaders). Three lists:
 *  - SeAT pilots still missing getting-started items: a DM to their linked Discord;
 *  - Discord members with the Devil role who aren't on SeAT: a DM from the bot;
 *  - corp characters on neither list (shown only; nobody to message).
 * Each pilot / member is sent it once; every send is logged.
 */
class RemindersController extends Controller
{
    /** Discord role (name contains this) that marks corp members. */
    private const MEMBER_ROLE = 'devil';

    public const SEAT_TEXT = "Hi {name}! A one-time nudge from Devil's Rage: on SeAT you still need to:\n{missing}\nStart here: {link}";

    public const DISCORD_TEXT = "Hi {name}! You're a Devil on our Discord but not on SeAT yet. Sign in with EVE at {link}, add your characters and link your Discord under Connector. Takes 5 minutes.";

    public function index(Leadership $leadership, GettingStarted $gettingStarted, DiscordDm $dm, Corporation $corporation): View
    {
        abort_unless($leadership->isLeader(auth()->user()), 403);

        $log = Reminder::query()->get()->keyBy(fn (Reminder $reminder): string => $reminder->kind.':'.$reminder->target);
        $linked = ConnectorUser::query()->where('connector_type', 'discord')->pluck('connector_id', 'user_id');

        $pilots = [];
        foreach (User::query()->with('main_character')->where('active', true)->get() as $user) {
            $missing = $gettingStarted->missing($user);
            if ($missing === []) {
                continue;
            }
            $pilots[] = [
                'id' => (int) $user->id,
                'name' => PersonName::of($user),
                'missing' => $missing,
                'discord' => isset($linked[$user->id]),
                'log' => $log->get('seat:'.$user->id),
            ];
        }

        $members = Cache::remember('devilsrage.reminders.discord', 600, fn (): ?array => $dm->membersWithRole(self::MEMBER_ROLE));
        $linkedIds = array_flip(array_map('strval', $linked->values()->all()));
        $discordOnly = $members === null ? null : array_values(array_map(
            fn (array $member): array => $member + ['log' => $log->get('discord:'.$member['id'])],
            array_filter($members, fn (array $member): bool => $member['id'] !== '' && ! isset($linkedIds[$member['id']])),
        ));

        return view('devilsrage::reminders.index', [
            'pilots' => $pilots,
            'discordOnly' => $discordOnly,
            'neither' => $this->neither($corporation, $members ?? []),
            'seatText' => self::SEAT_TEXT,
            'discordText' => self::DISCORD_TEXT,
        ]);
    }

    public function send(Request $request, Leadership $leadership, GettingStarted $gettingStarted, DiscordDm $dm): RedirectResponse
    {
        abort_unless($leadership->isLeader(auth()->user()), 403);

        $data = $request->validate([
            'kind' => ['required', 'in:seat,discord'],
            'targets' => ['required', 'array', 'max:200'],
            'targets.*' => ['string', 'max:32'],
            'text' => ['required', 'string', 'max:1500'],
        ]);

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        $link = url('/');
        foreach (array_unique($data['targets']) as $target) {
            if (Reminder::query()->where('kind', $data['kind'])->where('target', $target)->exists()) {
                $skipped++;

                continue;
            }

            if ($data['kind'] === 'seat') {
                $user = User::query()->with('main_character')->find((int) $target);
                if ($user === null) {
                    continue;
                }
                $missing = $gettingStarted->missing($user);
                $name = PersonName::of($user);
                $text = strtr($data['text'], ['{name}' => $name, '{missing}' => implode("\n", array_map(fn (string $item): string => '• '.$item, $missing)), '{link}' => route('devilsrage.home')]);
                $ok = $dm->send((int) $user->id, $text);
            } else {
                $missing = ['Join SeAT'];
                $name = (string) $request->input('names.'.$target, '');
                $text = strtr($data['text'], ['{name}' => $name !== '' ? $name : 'there', '{missing}' => '', '{link}' => $link]);
                $ok = $dm->sendTo($target, $text);
            }

            Reminder::query()->create([
                'kind' => $data['kind'],
                'target' => $target,
                'name' => mb_substr($name, 0, 64),
                'missing' => $missing,
                'sent' => $ok,
                'error' => $ok ? null : 'Discord did not take the message (DMs closed, or the bot has no access).',
                'sent_by' => auth()->id(),
            ]);
            $ok ? $sent++ : $failed++;
        }

        return back()->with('success', "Reminders: {$sent} sent, {$failed} failed, {$skipped} already had one.");
    }

    /**
     * Corp characters (from SeAT's member tracking) with no SeAT login and no Discord
     * member whose name contains theirs.
     *
     * @param  list<array{id: string, name: string}>  $members
     * @return list<string>|null null when SeAT has no member tracking for the corp
     */
    private function neither(Corporation $corporation, array $members): ?array
    {
        $tracking = '\\Seat\\Eveapi\\Models\\Corporation\\CorporationMemberTracking';
        $names = '\\Seat\\Eveapi\\Models\\Universe\\UniverseName';
        $corporationId = $corporation->mainId();
        if ($corporationId === null || ! class_exists($tracking) || ! class_exists($names)) {
            return null;
        }

        $ids = $tracking::query()->where('corporation_id', $corporationId)->pluck('character_id')->map(fn ($id): int => (int) $id)->all();
        if ($ids === []) {
            return null;
        }
        $onSeat = RefreshToken::withTrashed()->whereIn('character_id', $ids)->pluck('character_id')->map(fn ($id): int => (int) $id)->flip();
        $discordNames = array_map(fn (array $member): string => mb_strtolower($member['name']), $members);

        return $names::query()
            ->whereIn('entity_id', array_values(array_filter($ids, fn (int $id): bool => ! isset($onSeat[$id]))))
            ->orderBy('name')
            ->pluck('name')
            ->map(fn ($name): string => (string) $name)
            ->reject(function (string $name) use ($discordNames): bool {
                $lower = mb_strtolower($name);
                foreach ($discordNames as $discord) {
                    if ($discord !== '' && str_contains($discord, $lower)) {
                        return true;
                    }
                }

                return false;
            })
            ->values()
            ->all();
    }
}
