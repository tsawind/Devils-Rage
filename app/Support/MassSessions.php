<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Character;
use App\Models\MapConnection;
use App\Models\MassSession;
use App\Models\Solarsystem;

/**
 * Patch 37: the open "⚖ Adding mass…" sessions, for the pills (one query per request) and
 * for the jump log following a plan.
 */
final class MassSessions
{
    /**
     * Open sessions by connection id, with when they were read (kept for a moment so a map's
     * worth of pipes costs one query; long-running workers re-read them after that).
     *
     * @var array{at: float, open: array<int, array{id: int, by: string|null, goal: string}>}|null
     */
    private static ?array $cache = null;

    /**
     * @return array{id: int, by: string|null, goal: string}|null
     */
    public static function forConnection(int $mapId, int $connectionId): ?array
    {
        if (self::$cache === null || microtime(true) - self::$cache['at'] > 2.0) {
            self::$cache = [
                'at' => microtime(true),
                'open' => MassSession::query()
                    ->whereNull('ended_at')
                    ->orderBy('id')
                    ->get(['id', 'map_connection_id', 'started_by_name', 'goal'])
                    ->mapWithKeys(fn (MassSession $session): array => [$session->map_connection_id => ['id' => $session->id, 'by' => $session->started_by_name, 'goal' => $session->goal]])
                    ->all(),
            ];
        }

        return self::$cache['open'][$connectionId] ?? null;
    }

    /**
     * A session started, changed or ended: read them again.
     */
    public static function forget(): void
    {
        self::$cache = null;
    }

    /**
     * A jump through a hole with a running plan: the first step still open for this pilot in
     * that direction is done, and the jump logs that step's mass (the plan's hot / cold /
     * thread choice: no switch needed while multiboxing). Null when no plan step matches.
     */
    public static function followPlan(MapConnection $connection, int $characterId, int $fromSolarsystemId): ?int
    {
        $session = MassSession::query()
            ->where('map_connection_id', $connection->id)
            ->whereNull('ended_at')
            ->whereNotNull('plan')
            ->latest('id')
            ->first();
        if (! $session instanceof MassSession || $session->home_map_solarsystem_id === null) {
            return null;
        }

        $homeSolarsystemId = $connection->from_map_solarsystem_id === $session->home_map_solarsystem_id
            ? $connection->fromMapSolarsystem?->solarsystem_id
            : $connection->toMapSolarsystem?->solarsystem_id;
        $direction = $homeSolarsystemId === $fromSolarsystemId ? 'out' : 'home';

        $plan = $session->plan ?? ['steps' => []];
        $steps = $plan['steps'] ?? [];
        foreach ($steps as $index => $step) {
            if (($step['done'] ?? false) || (int) ($step['character_id'] ?? 0) !== $characterId || ($step['direction'] ?? '') !== $direction) {
                continue;
            }
            $steps[$index]['done'] = true;
            $steps[$index]['done_at'] = now()->toISOString();
            $session->forceFill(['plan' => [...$plan, 'steps' => $steps]])->save();

            return (int) ($step['mass'] ?? 0);
        }

        return null;
    }

    public static function characterName(int $characterId): ?string
    {
        $name = Character::query()->whereKey($characterId)->value('name');

        return is_string($name) ? $name : null;
    }

    public static function solarsystemName(int $id): ?string
    {
        $name = Solarsystem::query()->whereKey($id)->value('name');

        return is_string($name) ? $name : null;
    }
}
