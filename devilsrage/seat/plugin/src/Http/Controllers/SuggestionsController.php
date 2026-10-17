<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use DevilsRage\Seat\Models\Suggestion;
use DevilsRage\Seat\Services\Leadership;
use DevilsRage\Seat\Services\PersonName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Seat\Web\Http\Controllers\Controller;

/**
 * Patch 39: the suggestions board. Everyone posts ideas, votes (one vote each, click again
 * to take it back) and comments; leaders set the status with a short note.
 */
class SuggestionsController extends Controller
{
    public function index(Request $request, Leadership $leadership): View
    {
        $userId = (int) auth()->id();
        $filter = array_key_exists((string) $request->query('status'), Suggestion::STATUSES) ? (string) $request->query('status') : null;

        $votes = DB::table(Suggestion::VOTES)->selectRaw('suggestion_id, COUNT(*) as total')->groupBy('suggestion_id')->pluck('total', 'suggestion_id');
        $mine = DB::table(Suggestion::VOTES)->where('user_id', $userId)->pluck('suggestion_id')->flip();

        $suggestions = Suggestion::query()
            ->with(['author.main_character', 'comments.author.main_character'])
            ->when($filter !== null, fn ($query) => $query->where('status', $filter), fn ($query) => $query->whereIn('status', ['open', 'planned']))
            ->get()
            ->sortByDesc(fn (Suggestion $suggestion): array => [(int) ($votes[$suggestion->id] ?? 0), $suggestion->created_at?->getTimestamp() ?? 0])
            ->values();

        return view('devilsrage::suggestions.index', [
            'suggestions' => $suggestions,
            'votes' => $votes,
            'mine' => $mine,
            'filter' => $filter,
            'statuses' => Suggestion::STATUSES,
            'isLeader' => $leadership->isLeader(auth()->user()),
            'nameOf' => fn ($user): string => $user === null ? 'Someone' : PersonName::of($user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:4000'],
        ]);

        Suggestion::query()->create(['user_id' => auth()->id(), 'title' => trim($data['title']), 'body' => $data['body'] ?? null]);

        return back()->with('success', 'Suggestion posted. Thanks!');
    }

    public function vote(Suggestion $suggestion): RedirectResponse
    {
        $match = ['suggestion_id' => $suggestion->id, 'user_id' => auth()->id()];
        if (DB::table(Suggestion::VOTES)->where($match)->exists()) {
            DB::table(Suggestion::VOTES)->where($match)->delete();
        } else {
            DB::table(Suggestion::VOTES)->insert($match + ['created_at' => now(), 'updated_at' => now()]);
        }

        return back();
    }

    public function comment(Request $request, Suggestion $suggestion): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $suggestion->comments()->create(['user_id' => auth()->id(), 'body' => trim($data['body'])]);

        return back();
    }

    public function status(Request $request, Suggestion $suggestion, Leadership $leadership): RedirectResponse
    {
        abort_unless($leadership->isLeader(auth()->user()), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Suggestion::STATUSES))],
            'status_note' => ['nullable', 'string', 'max:255'],
        ]);
        $suggestion->update(['status' => $data['status'], 'status_note' => $data['status_note'] ?? null]);

        return back()->with('success', 'Status saved.');
    }

    public function destroy(Suggestion $suggestion, Leadership $leadership): RedirectResponse
    {
        abort_unless((int) $suggestion->user_id === (int) auth()->id() || $leadership->isLeader(auth()->user()), 403);
        $suggestion->delete();

        return back()->with('success', 'Suggestion removed.');
    }
}
