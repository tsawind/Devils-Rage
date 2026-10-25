<?php

declare(strict_types=1);

namespace App\Actions\Tracking;

use App\Actions\MapConnections\RecordMapConnectionJumpAction;
use App\Jobs\Characters\DispatchCharacterStatusEvents;
use App\Models\CharacterStatus;
use App\Support\LocationTiming;
use DateTimeInterface;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use NicolasKion\Esi\Connector;
use NicolasKion\Esi\Enums\EsiScope;
use Throwable;

/**
 * Patch 54: every online character's ESI location, asked all at once (one round is about one
 * ESI call long, however many pilots are online), instead of one character after another in the
 * queue. A character in a new system is moved, told to everyone's map and its jump logged right
 * away. The per-character jobs still run after it (ship, station, token refresh) as before; they
 * find the system already up to date. Characters whose token needs refreshing are left to them.
 */
final readonly class ProbeLocationsAction
{
    /** One slow ESI answer must not hold the round up. */
    private const int TIMEOUT_SECONDS = 4;

    public function __construct(private RecordMapConnectionJumpAction $recordJumpAction) {}

    /**
     * @param  Collection<int, CharacterStatus>  $statuses
     * @return int how many characters moved
     */
    public function handle(Collection $statuses): int
    {
        $startedAt = now();
        $ready = [];
        foreach ($statuses as $status) {
            $token = $status->character->getEsiTokenWithScope(EsiScope::ReadLocations);
            if ($token === null || $token->isExpired()) {
                continue;
            }
            $ready[(string) $status->id] = ['status' => $status, 'token' => $token->getAccessToken()];
        }
        if ($ready === []) {
            return 0;
        }

        try {
            $responses = Http::pool(fn (Pool $pool): array => array_map(
                fn (string $key): mixed => $pool->as($key)
                    ->withHeaders([
                        'User-Agent' => (string) config('esi.user_agent'),
                        'X-Compatibility-Date' => Connector::COMPATIBILITY_DATE,
                    ])
                    ->baseUrl((string) config('esi.base_url'))
                    ->withToken($ready[$key]['token'])
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->get(sprintf('/characters/%d/location/', $ready[$key]['status']->character_id)),
                array_keys($ready),
            ));
        } catch (Throwable $exception) {
            Log::warning('Parallel location check failed', ['exception' => $exception->getMessage()]);

            return 0;
        }

        $moved = 0;
        foreach ($ready as $key => ['status' => $status]) {
            $response = $responses[$key] ?? null;
            if (! $response instanceof Response || ! $response->successful()) {
                continue;
            }
            $systemId = (int) $response->json('solar_system_id');
            if ($systemId > 0 && $this->move($status, $systemId, $response, $startedAt)) {
                $moved++;
            }
        }

        return $moved;
    }

    private function move(CharacterStatus $status, int $systemId, Response $response, DateTimeInterface $startedAt): bool
    {
        $status->refresh();
        $previous = $status->solarsystem_id;
        if ($previous === $systemId) {
            return false;
        }
        // The desktop app read a jump from the chat log moments ago: ESI may still lag behind it.
        if ($status->app_location_at?->gt(now()->subSeconds(ReportAppLocationAction::TRUSTED_FOR_SECONDS))) {
            return false;
        }

        $status->update([
            'solarsystem_id' => $systemId,
            'station_id' => $response->json('station_id'),
            'structure_id' => $response->json('structure_id'),
        ]);
        DispatchCharacterStatusEvents::broadcastFor([$status->character_id]);
        LocationTiming::moved($status->character_id, $startedAt);

        try {
            $this->recordJumpAction->handle($status->character_id, $previous, $systemId, $status->ship_type_id, $status->ship_name);
        } catch (Throwable $exception) {
            Log::warning(sprintf('Failed to record connection jump for character %d', $status->character_id), ['exception' => $exception]);
        }

        return true;
    }
}
