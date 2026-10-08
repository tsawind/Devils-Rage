<?php

declare(strict_types=1);

namespace App\Console\Commands\Signatures;

use App\Actions\MapSolarsystem\HideMapSolarsystemsAction;
use App\Actions\MapSolarsystem\HideOldChainsAction;
use App\Actions\Signatures\DeleteSignatureAction;
use App\Console\Commands\AppCommand;
use App\Enums\SignatureCategory;
use App\Models\Map;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Support\ChainMemory;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Old signatures and chain memory (patch 36), every 10 minutes:
 * - wormhole signatures go 27 h after they were created (their pipes with them);
 *   a system left with no pipe hides;
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
        $this->deleteOtherSignatures();
        $this->forgetOldHiddenNames();
        $this->deleteEmptyHiddenSystems();
        $this->hideOldChains();

        return self::SUCCESS;
    }

    /**
     * @throws Throwable
     */
    private function deleteWormholeSignatures(): void
    {
        $old_signatures = Signature::query()
            ->whereRelation('signatureCategory', 'code', SignatureCategory::Wormhole)
            ->where('created_at', '<', now()->subHours(self::WORMHOLE_SIGNATURE_LIFETIME_HOURS))
            ->get();

        $this->deleteSignatures($old_signatures->all(), 'wormhole');
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
            ->where('alias', 'like', '%@%')
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
