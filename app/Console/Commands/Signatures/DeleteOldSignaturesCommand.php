<?php

declare(strict_types=1);

namespace App\Console\Commands\Signatures;

use App\Actions\MapSolarsystem\HideMapSolarsystemsAction;
use App\Actions\MapSolarsystem\HideOldChainsAction;
use App\Actions\Signatures\DeleteSignatureAction;
use App\Console\Commands\AppCommand;
use App\Enums\ConnectionType;
use App\Enums\SignatureCategory;
use App\Models\Map;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Models\Wormhole;
use App\Support\Broadcasting\MapBroadcaster;
use App\Support\ChainMemory;
use App\Support\HoleExpiry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Old signatures and chain memory (patch 36), every 10 minutes:
 * - wormhole signatures (and pipes without one) go by their hole's life, patch 37
 *   ({@see HoleExpiry}): type + 3 h, 27 h when not known, EOL 5½ h / EOL! 2 h after
 *   the first mark; a system left with no pipe hides;
 * - other signatures are kept 30 days;
 * - systems hidden longer than 27 h lose their name, and are deleted for good
 *   once they have no signatures left;
 * - old (stamped) chains that no longer hang off the map hide once no pilot,
 *   pin or other exit keeps them.
 */
final class DeleteOldSignaturesCommand extends AppCommand
{
    public const int SIGNATURE_LIFETIME_DAYS = ChainMemory::SIGNATURE_LIFETIME_DAYS;

    public const int WORMHOLE_SIGNATURE_LIFETIME_HOURS = ChainMemory::WORMHOLE_SIGNATURE_LIFETIME_HOURS;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:delete-old-signatures';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deletes old signatures and keeps the chain memory (hidden systems) tidy';

    public function __construct(
        private readonly DeleteSignatureAction $deleteSignatureAction,
        private readonly HideMapSolarsystemsAction $hideMapSolarsystemsAction,
        private readonly HideOldChainsAction $hideOldChainsAction,
        private readonly MapBroadcaster $mapBroadcaster,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @throws Throwable
     */
    public function handle(): int
    {
        $this->deleteWormholeSignatures();
        $this->deleteOldPipesWithoutSignatures();
        $this->deleteOtherSignatures();
        $this->forgetOldHiddenNames();
        $this->deleteEmptyHiddenSystems();
        $this->hideOldChains();

        return self::SUCCESS;
    }

    /**
     * Patch 37: wormhole signatures go by their hole's life ({@see HoleExpiry}):
     * type + 3 h (27 h when not known; a K162 goes by its far side's type),
     * EOL 5½ h and EOL! 2 h after the first mark, whichever is first.
     *
     * @throws Throwable
     */
    private function deleteWormholeSignatures(): void
    {
        $signatures = Signature::query()
            ->whereRelation('signatureCategory', 'code', SignatureCategory::Wormhole)
            ->get();

        $connection_ids = $signatures->pluck('map_connection_id')->filter()->unique()->values()->all();
        $connections = MapConnection::query()->whereIn('id', $connection_ids)->get()->keyBy('id');
        $lifetimes = $this->wormholeLifetimes([
            ...$signatures->pluck('wormhole_id')->all(),
            ...$connections->pluck('wormhole_id')->all(),
        ]);

        // The far side's type tells a K162's life.
        $by_connection = [];
        foreach ($signatures as $signature) {
            $life = $lifetimes[(int) $signature->wormhole_id] ?? null;
            if ($signature->map_connection_id !== null && $life !== null) {
                $by_connection[(int) $signature->map_connection_id] = $life;
            }
        }

        $now = CarbonImmutable::now();
        $old = $signatures->filter(function (Signature $signature) use ($connections, $lifetimes, $by_connection, $now): bool {
            $connection = $signature->map_connection_id === null ? null : $connections->get($signature->map_connection_id);
            $life = $lifetimes[(int) $signature->wormhole_id]
                ?? ($signature->map_connection_id === null ? null : $by_connection[(int) $signature->map_connection_id] ?? null)
                ?? ($connection instanceof MapConnection ? $lifetimes[(int) $connection->wormhole_id] ?? null : null);

            return HoleExpiry::deadline(
                CarbonImmutable::parse($signature->created_at),
                $life,
                HoleExpiry::earliest($signature->eol_since, $connection?->eol_since),
                HoleExpiry::earliest($signature->critical_since, $connection?->critical_since),
                HoleExpiry::earliest($signature->imminent_since, $connection?->imminent_since),
            )->lessThan($now);
        });

        $this->deleteSignatures($old->values()->all(), 'wormhole');
    }

    /**
     * Patch 37: a wormhole pipe with no signature (found by jumping) leaves the
     * map by the same rules, from when it was found.
     *
     * @throws Throwable
     */
    private function deleteOldPipesWithoutSignatures(): void
    {
        $connections = MapConnection::query()
            ->where('type', ConnectionType::Wormhole)
            ->whereDoesntHave('signatures')
            ->get();
        $lifetimes = $this->wormholeLifetimes($connections->pluck('wormhole_id')->all());
        $now = CarbonImmutable::now();

        $old = $connections->filter(fn (MapConnection $connection): bool => HoleExpiry::deadline(
            CarbonImmutable::parse($connection->connected_at ?? $connection->created_at),
            $lifetimes[(int) $connection->wormhole_id] ?? null,
            $connection->eol_since,
            $connection->critical_since,
            $connection->imminent_since,
        )->lessThan($now));

        if ($old->isEmpty()) {
            return;
        }

        $touched = [];
        foreach ($old->groupBy('map_id') as $map_id => $pipes) {
            $ids = $pipes->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            foreach ($pipes as $pipe) {
                $touched[(int) $pipe->from_map_solarsystem_id] = (int) $map_id;
                $touched[(int) $pipe->to_map_solarsystem_id] = (int) $map_id;
            }
            MapConnection::query()->whereKey($ids)->delete();
            $this->mapBroadcaster->connectionsRemoved((int) $map_id, $ids);
        }

        $this->hideSystemsWithoutPipes($touched);
        $this->info(sprintf('Deleted %d old pipe(s) without signatures.', $old->count()));
    }

    /**
     * @param  list<mixed>  $wormhole_ids
     * @return array<int, int> wormhole id → life in seconds (K162 left out: it tells nothing)
     */
    private function wormholeLifetimes(array $wormhole_ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (mixed $id): int => (int) $id, $wormhole_ids))));
        if ($ids === []) {
            return [];
        }

        return Wormhole::query()
            ->whereIn('id', $ids)
            ->where('name', '!=', 'K162')
            ->whereNotNull('maximum_lifetime')
            ->pluck('maximum_lifetime', 'id')
            ->map(fn (mixed $seconds): int => (int) $seconds)
            ->all();
    }

    /**
     * @throws Throwable
     */
    private function deleteOtherSignatures(): void
    {
        $old_signatures = Signature::query()
            ->where('created_at', '<', now()->subDays(self::SIGNATURE_LIFETIME_DAYS))
            ->get();

        $this->deleteSignatures($old_signatures->all(), 'other');
    }

    /**
     * Delete signatures (and the pipes they hold). Systems on the map that are
     * left with no pipe at all hide; signatures of hidden systems just go.
     *
     * @param  list<Signature>  $signatures
     *
     * @throws Throwable
     */
    private function deleteSignatures(array $signatures, string $kind): void
    {
        if ($signatures === []) {
            $this->info(sprintf('No old %s signatures found to delete.', $kind));

            return;
        }

        $visible_system_ids = MapSolarsystem::query()
            ->whereIn('id', array_map(fn (Signature $signature): int => $signature->map_solarsystem_id, $signatures))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->flip()
            ->all();

        $touched = [];
        DB::transaction(function () use ($signatures, $visible_system_ids, &$touched): void {
            foreach ($signatures as $signature) {
                // A pipe deleted earlier in this run takes its other signatures with it.
                if (! Signature::query()->whereKey($signature->id)->exists()) {
                    continue;
                }

                if (! isset($visible_system_ids[$signature->map_solarsystem_id])) {
                    $signature->deleteQuietly();

                    continue;
                }

                $connection = $signature->map_connection_id === null ? null : MapConnection::query()->find($signature->map_connection_id);
                if ($connection instanceof MapConnection) {
                    $touched[(int) $connection->from_map_solarsystem_id] = (int) $connection->map_id;
                    $touched[(int) $connection->to_map_solarsystem_id] = (int) $connection->map_id;
                }

                $this->deleteSignatureAction->handle($signature, without_events: true);
            }
        });

        $this->hideSystemsWithoutPipes($touched);

        $this->info(sprintf('Deleted %d old %s signatures successfully.', count($signatures), $kind));
    }

    /**
     * Patch 36: when the cleanup removes the last pipe to a system, it hides.
     *
     * @param  array<int, int>  $touched  map solarsystem id → map id
     *
     * @throws Throwable
     */
    private function hideSystemsWithoutPipes(array $touched): void
    {
        $by_map = [];
        foreach ($touched as $system_id => $map_id) {
            $by_map[$map_id][] = $system_id;
        }

        foreach ($by_map as $map_id => $system_ids) {
            $map = Map::query()->find($map_id);
            if (! $map instanceof Map) {
                continue;
            }

            $pipeless = MapSolarsystem::query()
                ->where('map_id', $map_id)
                ->whereIn('id', $system_ids)
                ->get()
                ->reject(fn (MapSolarsystem $system): bool => $system->mapConnections()->exists())
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all();

            $hidden = $this->hideMapSolarsystemsAction->handle($map, $pipeless, automatic: true);
            if ($hidden !== []) {
                $this->info(sprintf('Hid %d system(s) left without pipes on map %d.', count($hidden), $map_id));
            }
        }
    }

    /**
     * After 27 h hidden a system's name is gone (its locked hole numbers too):
     * a later return gets a fresh name.
     */
    private function forgetOldHiddenNames(): void
    {
        MapSolarsystem::withHidden()
            ->whereNotNull('hidden_at')
            ->where('hidden_at', '<', now()->subHours(ChainMemory::HIDDEN_NAME_KEPT_HOURS))
            ->whereNotNull('alias')
            ->get()
            ->each(function (MapSolarsystem $system): void {
                ChainMemory::forgetSignatureNames($system);
                $system->forceFill(['alias' => null])->saveQuietly();
            });
    }

    /**
     * A hidden system with no signatures left (and past its 27 h) is deleted for good.
     * Its notes, occupier and status live on in its details.
     */
    private function deleteEmptyHiddenSystems(): void
    {
        $deleted = MapSolarsystem::withHidden()
            ->whereNotNull('hidden_at')
            ->where('hidden_at', '<', now()->subHours(ChainMemory::HIDDEN_NAME_KEPT_HOURS))
            ->whereDoesntHave('signatures')
            ->delete();

        if ($deleted > 0) {
            $this->info(sprintf('Deleted %d hidden system(s) with no signatures left.', $deleted));
        }
    }

    /**
     * Old (stamped) chains no longer hanging off the map hide once nothing keeps them.
     *
     * @throws Throwable
     */
    private function hideOldChains(): void
    {
        $map_ids = MapSolarsystem::query()
            ->where('alias', 'like', '%'.ChainMemory::STAMP.'%')
            ->distinct()
            ->pluck('map_id');

        Map::query()
            ->whereIn('id', $map_ids)
            ->get()
            ->each(function (Map $map): void {
                $hidden = $this->hideOldChainsAction->handle($map);
                if ($hidden !== []) {
                    $this->info(sprintf('Hid %d system(s) of old chains on map %d.', count($hidden), $map->id));
                }
            });
    }
}
