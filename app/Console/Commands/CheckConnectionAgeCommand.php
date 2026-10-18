<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\MapConnections\UpdateMapConnectionAction;
use App\Data\MapConnectionData;
use App\Enums\LifetimeStatus;
use App\Enums\SolarsystemClass;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Support\Broadcasting\MapBroadcaster;
use App\Support\HoleExpiry;
use Illuminate\Console\Command;
use Throwable;

use function now;

final class CheckConnectionAgeCommand extends AppCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-connection-age';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the age of wormhole connections and updates their lifetime status';

    public function __construct(
        private readonly UpdateMapConnectionAction $updateMapConnectionAction,
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
        $connections = MapConnection::query()
            // A pipe whose system was removed has nothing to age (was an error every run).
            ->whereHas('fromMapSolarsystem')
            ->whereHas('toMapSolarsystem')
            ->connectsToWormhole()
            ->isNotTimeCritical()
            ->cursor()->map($this->calculateLifetimeStatusForConnection(...))->filter();

        $this->info("Updated lifetime status for {$connections->count()} connections.");

        $this->escalateCriticalToImminent();

        return self::SUCCESS;
    }

    /**
     * Patch 37: an EOL! hole whose hour has run out shows "Expired, closure imminent" in
     * game: it turns imminent by itself (and leaves the map an hour later). Jumped holes
     * go through the connection (its signatures follow), unjumped ones directly.
     *
     * @throws Throwable
     */
    private function escalateCriticalToImminent(): void
    {
        $due = now()->subMinutes(HoleExpiry::CRITICAL_TO_IMMINENT_MINUTES);

        MapConnection::query()
            ->where('lifetime', LifetimeStatus::Critical)
            ->whereNotNull('critical_since')
            ->where('critical_since', '<=', $due)
            ->get()
            ->each(fn (MapConnection $connection) => $this->updateMapConnectionAction->handle($connection, MapConnectionData::from([
                'lifetime' => LifetimeStatus::Imminent,
                'lifetime_updated_at' => now(),
            ])));

        $systems = [];
        Signature::query()
            ->whereNull('map_connection_id')
            ->where('lifetime', LifetimeStatus::Critical)
            ->whereNotNull('critical_since')
            ->where('critical_since', '<=', $due)
            ->get()
            ->each(function (Signature $signature) use (&$systems): void {
                $signature->update(['lifetime' => LifetimeStatus::Imminent, 'lifetime_updated_at' => now()]);
                $systems[$signature->map_solarsystem_id] = true;
            });

        foreach (array_keys($systems) as $system_id) {
            $system = MapSolarsystem::query()->find($system_id);
            if ($system instanceof MapSolarsystem) {
                $this->mapBroadcaster->signaturesChanged($system);
            }
        }
    }

    /**
     * @throws Throwable
     */
    private function calculateLifetimeStatusForConnection(MapConnection $connection): bool
    {
        $calculatedLifetimeStatus = $this->calculateLifetimeStatus($connection);

        if (! $this->shouldUpdateLifetime($connection, $calculatedLifetimeStatus)) {
            return false;
        }

        $this->updateMapConnectionAction->handle($connection, MapConnectionData::from([
            'lifetime' => $calculatedLifetimeStatus,
            'lifetime_updated_at' => now(),
        ]));

        return true;
    }

    private function shouldUpdateLifetime(MapConnection $connection, LifetimeStatus $calculatedStatus): bool
    {
        $currentSeverity = $this->getLifetimeSeverity($connection->lifetime);
        $calculatedSeverity = $this->getLifetimeSeverity($calculatedStatus);

        return $calculatedSeverity > $currentSeverity;
    }

    private function getLifetimeSeverity(LifetimeStatus $status): int
    {
        return match ($status) {
            LifetimeStatus::Healthy => 1,
            LifetimeStatus::EndOfLife => 2,
            LifetimeStatus::Critical => 3,
            LifetimeStatus::Imminent => 4,
        };
    }

    private function calculateLifetimeStatus(MapConnection $connection): LifetimeStatus
    {
        if ($connection->lifetime === LifetimeStatus::EndOfLife) {
            return $this->calculateLifetimeStatusForEolConnection($connection);
        }

        $createdAt = $connection->connected_at ?? $connection->created_at;
        $hoursAlive = now()->diffInHours($createdAt, absolute: true);

        $isC6Connection = $this->isC6toKSpaceConnection($connection);
        $isDrifterConnection = $this->isDrifterToKSpaceConnection($connection);

        if ($isDrifterConnection) {
            return match (true) {
                $hoursAlive >= 15 => LifetimeStatus::Critical, // <1h remaining
                $hoursAlive >= 12 => LifetimeStatus::EndOfLife, // <4h remaining
                default => LifetimeStatus::Healthy,
            };
        }

        if ($isC6Connection) {
            return match (true) {
                $hoursAlive >= 47 => LifetimeStatus::Critical, // <1h remaining
                $hoursAlive >= 44 => LifetimeStatus::EndOfLife, // <4h remaining
                default => LifetimeStatus::Healthy,
            };
        }

        return match (true) {
            $hoursAlive >= 23 => LifetimeStatus::Critical, // <1h remaining
            $hoursAlive >= 20 => LifetimeStatus::EndOfLife, // <4h remaining
            default => LifetimeStatus::Healthy,
        };
    }

    private function calculateLifetimeStatusForEolConnection(MapConnection $connection): LifetimeStatus
    {
        $marked_as_eol_at = $connection->lifetime_updated_at;
        $time_passed = now()->diffInHours($marked_as_eol_at, absolute: true);

        return match (true) {
            $time_passed >= 3 => LifetimeStatus::Critical, // <1h remaining
            default => LifetimeStatus::EndOfLife, // <4h remaining
        };
    }

    private function isC6toKSpaceConnection(MapConnection $connection): bool
    {
        return $connection->fromMapSolarsystem->wormholeSystem?->class === SolarsystemClass::C6
            && $connection->toMapSolarsystem->wormholeSystem === null
            || $connection->toMapSolarsystem->wormholeSystem?->class === SolarsystemClass::C6
            && $connection->fromMapSolarsystem->wormholeSystem === null;
    }

    private function isDrifterToKSpaceConnection(MapConnection $connection): bool
    {
        if ($connection->fromMapSolarsystem->wormholeSystem?->class?->isDrifter() === true
            && $connection->toMapSolarsystem->wormholeSystem === null) {
            return true;
        }

        return $connection->toMapSolarsystem->wormholeSystem?->class?->isDrifter() === true
        && $connection->fromMapSolarsystem->wormholeSystem === null;
    }
}
