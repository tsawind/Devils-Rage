<?php

declare(strict_types=1);

namespace App\Support\Undo;

use App\Models\Map;
use App\Models\MapConnection;
use App\Models\MapConnectionJump;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Support\Broadcasting\MapBroadcaster;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Patch 21: the global Undo. Before something is deleted (a system, a selection,
 * a chain, a pipe) or a paste changes a system's signatures, the rows involved are
 * saved here under a token the browser sent; Undo puts those rows back as they were
 * (creating what is gone, resetting what is still there). Kept for a few hours,
 * and only the person who made the change can put it back.
 *
 * @phpstan-type TSnapshot array{
 *     map_id: int,
 *     user_id: int,
 *     taken_at: string,
 *     systems: list<array<string, mixed>>,
 *     connections: list<array<string, mixed>>,
 *     signatures: list<array<string, mixed>>,
 *     jumps: list<array<string, mixed>>,
 *     prune_system_ids: list<int>,
 * }
 */
final readonly class MapUndoSnapshots
{
    private const string PREFIX = 'map-undo:';

    private const int KEEP_HOURS = 3;

    public function __construct(private MapBroadcaster $mapBroadcaster) {}

    /** The undo token a request carries (a UUID), or null. */
    public static function tokenFrom(Request $request, string $key = 'undo_token'): ?string
    {
        $token = $request->input($key);

        return is_string($token) && Str::isUuid($token) ? $token : null;
    }

    /**
     * Save the systems (with their pipes, signatures and logged jumps), the pipes
     * (with the signatures linked to them) and the signatures of `signatureSystemIds`.
     * With `prune`, Undo also removes signatures added to those systems after this.
     *
     * @param  list<int>  $systemIds
     * @param  list<int>  $connectionIds
     * @param  list<int>  $signatureSystemIds
     */
    public function capture(Map $map, int $userId, string $token, array $systemIds = [], array $connectionIds = [], array $signatureSystemIds = [], bool $prune = false): void
    {
        $systems = MapSolarsystem::query()->where('map_id', $map->id)->whereIn('id', $systemIds)->get();
        $connections = MapConnection::query()
            ->where('map_id', $map->id)
            ->where(fn (Builder $query) => $query
                ->whereIn('id', $connectionIds)
                ->orWhereIn('from_map_solarsystem_id', $systemIds)
                ->orWhereIn('to_map_solarsystem_id', $systemIds))
            ->get();
        $connectionIdList = $connections->pluck('id')->all();
        $signatures = Signature::query()
            ->whereHas('mapSolarsystem', fn (Builder $query) => $query->where('map_id', $map->id))
            ->where(fn (Builder $query) => $query
                ->whereIn('map_solarsystem_id', [...$systemIds, ...$signatureSystemIds])
                ->orWhereIn('map_connection_id', $connectionIdList))
            ->get();
        $jumps = MapConnectionJump::query()->whereIn('map_connection_id', $connectionIdList)->get();

        Cache::put(self::PREFIX.$token, [
            'map_id' => $map->id,
            'user_id' => $userId,
            'taken_at' => CarbonImmutable::now()->toIso8601String(),
            'systems' => $systems->map(fn (MapSolarsystem $row): array => $row->getAttributes())->values()->all(),
            'connections' => $connections->map(fn (MapConnection $row): array => $row->getAttributes())->values()->all(),
            'signatures' => $signatures->map(fn (Signature $row): array => $row->getAttributes())->values()->all(),
            'jumps' => $jumps->map(fn (MapConnectionJump $row): array => $row->getAttributes())->values()->all(),
            'prune_system_ids' => $prune ? array_map(intval(...), $signatureSystemIds) : [],
        ], CarbonImmutable::now()->addHours(self::KEEP_HOURS));
    }

    /**
     * The saved change, if it exists and belongs to this person.
     *
     * @return TSnapshot|null
     */
    public function find(string $token, int $userId): ?array
    {
        /** @var TSnapshot|null $snapshot */
        $snapshot = Cache::get(self::PREFIX.$token);

        return is_array($snapshot) && (int) $snapshot['user_id'] === $userId ? $snapshot : null;
    }

    /**
     * Save the same rows as they are now under `token` (so a restore can itself be undone).
     *
     * @param  TSnapshot  $snapshot
     */
    public function captureLike(array $snapshot, Map $map, int $userId, string $token): void
    {
        $this->capture(
            $map,
            $userId,
            $token,
            array_map(fn (array $row): int => (int) $row['id'], $snapshot['systems']),
            array_map(fn (array $row): int => (int) $row['id'], $snapshot['connections']),
            array_values(array_unique(array_map(fn (array $row): int => (int) $row['map_solarsystem_id'], $snapshot['signatures']))),
            $snapshot['prune_system_ids'] !== [],
        );
    }

    /**
     * Put the saved rows back as they were.
     *
     * @param  TSnapshot  $snapshot
     * @return array{systems: int, connections: int, signatures: int}
     *
     * @throws Throwable
     */
    public function restore(array $snapshot, Map $map): array
    {
        $counts = DB::transaction(function () use ($snapshot, $map): array {
            $counts = ['systems' => 0, 'connections' => 0, 'signatures' => 0];

            /** @var array<int, int> $systemIds old id → id now */
            $systemIds = [];
            foreach ($snapshot['systems'] as $row) {
                $existing = MapSolarsystem::query()->find($row['id'])
                    ?? MapSolarsystem::query()->where('map_id', $map->id)->where('solarsystem_id', $row['solarsystem_id'])->first();
                if ($existing instanceof MapSolarsystem && (int) $existing->map_id === (int) $map->id) {
                    // Still (or again) on the map: only reset what the change touched, never move it to another row.
                    $this->resetRow($existing, array_diff_key($row, ['id' => true]));
                    $systemIds[(int) $row['id']] = $existing->id;

                    continue;
                }
                $this->insertRow(new MapSolarsystem, $row);
                $systemIds[(int) $row['id']] = (int) $row['id'];
                $counts['systems']++;
            }
            $systemId = fn (int $id): ?int => $systemIds[$id] ?? (MapSolarsystem::query()->where('map_id', $map->id)->whereKey($id)->exists() ? $id : null);

            /** @var array<int, int> $connectionIds */
            $connectionIds = [];
            foreach ($snapshot['connections'] as $row) {
                $from = $systemId((int) $row['from_map_solarsystem_id']);
                $to = $systemId((int) $row['to_map_solarsystem_id']);
                if ($from === null || $to === null) {
                    continue;
                }
                $row = [...$row, 'from_map_solarsystem_id' => $from, 'to_map_solarsystem_id' => $to];
                $existing = MapConnection::query()->find($row['id'])
                    ?? MapConnection::query()->where('map_id', $map->id)
                        ->where(fn (Builder $query) => $query
                            ->where(fn (Builder $pair) => $pair->where('from_map_solarsystem_id', $from)->where('to_map_solarsystem_id', $to))
                            ->orWhere(fn (Builder $pair) => $pair->where('from_map_solarsystem_id', $to)->where('to_map_solarsystem_id', $from)))
                        ->first();
                if ($existing instanceof MapConnection && (int) $existing->map_id === (int) $map->id) {
                    $this->resetRow($existing, array_diff_key($row, ['id' => true, 'from_map_solarsystem_id' => true, 'to_map_solarsystem_id' => true]));
                    $connectionIds[(int) $row['id']] = $existing->id;

                    continue;
                }
                $this->insertRow(new MapConnection, $row);
                $connectionIds[(int) $row['id']] = (int) $row['id'];
                $counts['connections']++;
            }

            $keptSignatureIds = [];
            foreach ($snapshot['signatures'] as $row) {
                $system = $systemId((int) $row['map_solarsystem_id']);
                if ($system === null) {
                    continue;
                }
                $connection = $row['map_connection_id'] === null ? null : ($connectionIds[(int) $row['map_connection_id']] ?? (MapConnection::query()->whereKey($row['map_connection_id'])->exists() ? (int) $row['map_connection_id'] : null));
                $row = [...$row, 'map_solarsystem_id' => $system, 'map_connection_id' => $connection];
                $keptSignatureIds[] = (int) $row['id'];
                $existing = Signature::query()->find($row['id']);
                if ($existing instanceof Signature) {
                    $this->resetRow($existing, array_diff_key($row, ['id' => true]));

                    continue;
                }
                $this->insertRow(new Signature, $row);
                $counts['signatures']++;
            }

            foreach ($snapshot['jumps'] as $row) {
                $connection = $connectionIds[(int) $row['map_connection_id']] ?? null;
                if ($connection === null || MapConnectionJump::query()->whereKey($row['id'])->exists()) {
                    continue;
                }
                $this->insertRow(new MapConnectionJump, [...$row, 'map_connection_id' => $connection]);
            }

            // Undoing a paste: what the paste added goes again (signatures added since by others stay).
            $pruneIds = array_values(array_filter(array_map(fn (int $id): ?int => $systemId($id), $snapshot['prune_system_ids'])));
            if ($pruneIds !== []) {
                Signature::query()
                    ->whereIn('map_solarsystem_id', $pruneIds)
                    ->whereNotIn('id', $keptSignatureIds)
                    ->where('created_at', '>=', CarbonImmutable::parse($snapshot['taken_at']))
                    ->get()
                    ->each(function (Signature $signature): void {
                        $signature->deleteQuietly();
                    });
            }

            return $counts;
        });

        // Many rows at once: every open map reloads.
        $this->mapBroadcaster->resync($map->id);

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertRow(Model $model, array $row): void
    {
        $model->setRawAttributes($row);
        $model->exists = false;
        $model->timestamps = false;
        $model->saveQuietly();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resetRow(Model $model, array $row): void
    {
        $model->setRawAttributes([...$model->getAttributes(), ...$row]);
        $model->timestamps = false;
        $model->saveQuietly();
    }
}
