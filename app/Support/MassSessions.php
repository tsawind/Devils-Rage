<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Character;
use App\Models\MapConnection;
use App\Models\MassObservation;
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
            ? $connection->fromMapSolarsystem->solarsystem_id
            : $connection->toMapSolarsystem->solarsystem_id;
        $direction = $homeSolarsystemId === $fromSolarsystemId ? 'out' : 'home';

        $plan = $session->plan ?? ['steps' => []];
        $steps = $plan['steps'];
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

    /**
     * Patch 52: while a session runs with "only our jumps", the hole size its marks pin down is
     * kept on the pipe (everyone's map and the details box use it). Marks that don't add up, or
     * "only our jumps" off: the session's range is dropped (a kept one from before stays).
     */
    public static function keepFromSession(MassSession $session): void
    {
        $connection = MapConnection::query()->find($session->map_connection_id);
        if (! $connection instanceof MapConnection) {
            return;
        }
        $window = null;
        if ($session->ended_at === null && $session->only_our_jumps && ($session->nominal_mass ?? 0) > 0) {
            $observations = MassObservation::query()
                ->where('map_connection_id', $connection->id)
                ->orderBy('id')
                ->get(['status', 'logged_mass', 'flip_mass'])
                ->map(fn (MassObservation $row): array => ['status' => (string) $row->status, 'logged' => (int) $row->logged_mass, 'flip' => $row->flip_mass === null ? null : (int) $row->flip_mass]);
            $window = $observations->isEmpty() ? null : HoleWindow::size((float) $session->nominal_mass, $observations);
        }

        if ($window !== null && $window['consistent']) {
            $connection->forceFill([
                'kept_size_low' => (int) floor($window['low']),
                'kept_size_high' => (int) ceil($window['high']),
                'kept_size_from' => 'session',
                'kept_size_at' => now(),
                'kept_ack_at' => null,
            ])->save();
        } elseif ($connection->kept_size_from === 'session') {
            self::forgetKept($connection);
        }
    }

    /**
     * Patch 52: how a session ends. "top": done massing, only the most that can be left stays;
     * "watch": done and watching, the whole range stays and every jump is logged from here;
     * "forget": back to the status bands.
     */
    public static function endKept(MapConnection $connection, string $how, ?string $byName): void
    {
        if ($how === 'forget' || $connection->kept_size_high === null) {
            self::forgetKept($connection);

            return;
        }
        $connection->forceFill([
            'kept_size_low' => $how === 'watch' ? $connection->kept_size_low : null,
            'kept_size_from' => 'kept',
            ...($how === 'watch' ? [
                'mass_watched_at' => $connection->mass_watched_at ?? now(),
                'mass_watched_by_name' => $connection->mass_watched_by_name ?? $byName,
                'mass_watch_open' => true,
                'mass_watch_touched_at' => now(),
                'mass_counting_since' => $connection->mass_counting_since ?? now(),
            ] : []),
        ])->save();
    }

    public static function forgetKept(MapConnection $connection): void
    {
        $connection->forceFill(['kept_size_low' => null, 'kept_size_high' => null, 'kept_size_from' => null, 'kept_size_at' => null, 'kept_ack_at' => null])->save();
    }
}
