<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seat;

use App\Http\Controllers\Controller;
use App\Models\EsiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

/**
 * Patch 49: SeAT's "My accounts" page shows whether each character is logged in to the mapper,
 * and its "add to the mapper" button comes here and goes straight back to SeAT afterwards.
 */
final class SeatCharactersController extends Controller
{
    /** At most this many characters per question (a director has a few dozen). */
    private const int MAX_IDS = 300;

    /**
     * Which of these characters the mapper has a working login for. Asked by SeAT only
     * (the shared bot key in X-DR-Key); answers yes/no per id, nothing else.
     */
    public function index(Request $request): JsonResponse
    {
        $key = (string) config('services.seat_bot.key');
        abort_if(mb_strlen($key) < 32, 404);
        abort_unless(hash_equals($key, (string) $request->header('X-DR-Key', '')), 401);

        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn (string $id): int => (int) mb_trim($id))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->take(self::MAX_IDS)
            ->values();

        $loggedIn = EsiToken::query()
            ->whereIn('character_id', $ids)
            ->whereNotNull('refresh_token')
            ->pluck('character_id')
            ->map(fn ($id): int => (int) $id)
            ->flip();

        return response()->json([
            'characters' => $ids->mapWithKeys(fn (int $id): array => [(string) $id => $loggedIn->has($id)])->all(),
        ]);
    }

    /**
     * Add a character to your mapper login, then go back to the SeAT page you came from.
     * Only addresses on our SeAT are taken as the way back.
     */
    public function create(Request $request): RedirectResponse
    {
        $return = (string) $request->query('return', '');
        if ($this->isSeatAddress($return)) {
            Session::put('redirect_to', $return);
        }

        return to_route('eve.show', ['add_to_account' => 1]);
    }

    private function isSeatAddress(string $url): bool
    {
        $seatHost = parse_url((string) config('services.seat_bot.url'), PHP_URL_HOST);
        $parts = parse_url($url);

        return is_string($seatHost) && $seatHost !== ''
            && is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && mb_strtolower($parts['host'] ?? '') === mb_strtolower($seatHost)
            && ! isset($parts['user'], $parts['pass']);
    }
}
