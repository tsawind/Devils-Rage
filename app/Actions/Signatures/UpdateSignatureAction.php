<?php

declare(strict_types=1);

namespace App\Actions\Signatures;

use App\Actions\MapConnections\SyncConnectionShipSizeAction;
use App\Actions\MapSolarsystem\UpdateMapSolarsystemAction;
use App\Data\SignatureData;
use App\Enums\LifetimeStatus;
use App\Enums\MassStatus;
use App\Events\Signatures\SignatureUpdatedEvent;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Models\SignatureType;
use App\Support\Broadcasting\MapBroadcaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;
use Throwable;

final readonly class UpdateSignatureAction
{
    public function __construct(
        private MapBroadcaster $mapBroadcaster,
        private SyncConnectionShipSizeAction $syncConnectionShipSizeAction,
        private UpdateMapSolarsystemAction $updateMapSolarsystemAction,
    ) {}

    /**
     * @throws Throwable
     */
    public function handle(Signature $signature, SignatureData $data): Signature
    {
        return DB::transaction(function () use ($signature, $data): Signature {
            $this->guardChainNumbering($signature, $data);
            $previousAlias = $signature->alias;

            $updateData = $data->toArray();

            // Update wormhole_id if signature_type_id changed, resetting it when cleared
            if (! $data->signature_type_id instanceof Optional) {
                $signatureType = $data->signature_type_id ? SignatureType::query()->find($data->signature_type_id) : null;
                $updateData['wormhole_id'] = $signatureType?->wormhole?->id;
            }

            $signature->update($updateData);

            $this->syncConnectedSystemAlias($signature, $data, $previousAlias);
            $this->syncMassAndLifetime($signature, $data);
            $this->syncConnectionShipSizeAction->handle($signature);

            broadcast(new SignatureUpdatedEvent($signature->mapSolarsystem->map_id))->toOthers();

            $this->mapBroadcaster->signaturesChanged($signature->mapSolarsystem);

            return $signature;
        });
    }

    /**
     * A chain number may only be used once per system, and only one hole per
     * system may be marked as the static.
     *
     * @throws ValidationException
     */
    private function guardChainNumbering(Signature $signature, SignatureData $data): void
    {
        if (! $data->alias instanceof Optional && filled($data->alias)) {
            $clash = Signature::query()
                ->where('map_solarsystem_id', $signature->map_solarsystem_id)
                ->whereKeyNot($signature->id)
                ->where('alias', $data->alias)
                ->first();

            if ($clash instanceof Signature) {
                throw ValidationException::withMessages([
                    'alias' => sprintf('Number %s is already used by signature %s.', $data->alias, $clash->signature_id ?? 'without an ID'),
                ]);
            }
        }

        if (! $data->is_static instanceof Optional && $data->is_static) {
            $otherStatic = Signature::query()
                ->where('map_solarsystem_id', $signature->map_solarsystem_id)
                ->whereKeyNot($signature->id)
                ->where('is_static', true)
                ->first();

            if ($otherStatic instanceof Signature) {
                throw ValidationException::withMessages([
                    'is_static' => sprintf('Signature %s is already marked as the static.', $otherStatic->signature_id ?? 'without an ID'),
                ]);
            }
        }
    }

    /**
     * When a jumped hole's number changes (e.g. it was marked as the static),
     * the system it leads to follows, unless someone renamed that system by hand.
     */
    private function syncConnectedSystemAlias(Signature $signature, SignatureData $data, ?string $previousAlias): void
    {
        if ($data->alias instanceof Optional || blank($data->alias) || $data->alias === $previousAlias) {
            return;
        }

        $connection = $signature->mapConnection;
        if ($connection === null) {
            return;
        }

        $otherId = $connection->from_map_solarsystem_id === $signature->map_solarsystem_id
            ? $connection->to_map_solarsystem_id
            : $connection->from_map_solarsystem_id;

        $other = MapSolarsystem::query()->find($otherId);
        if (! $other instanceof MapSolarsystem) {
            return;
        }

        if ($other->alias === null || $other->alias === $previousAlias) {
            $this->updateMapSolarsystemAction->handle($other, ['alias' => $data->alias]);
        }
    }

    private function syncMassAndLifetime(Signature $signature, SignatureData $data): void
    {
        if ($signature->mapConnection === null) {
            return;
        }

        $lifetime = $this->getNewLifetimeValue($signature, $data);
        $mass = $this->getNewMassStatus($signature, $data);

        $connectionUpdateData = ['mass_status' => $mass];
        $signatureUpdateData = ['mass_status' => $mass];

        // Only update lifetime and timestamp if lifetime actually changed
        if ($signature->mapConnection->lifetime !== $lifetime) {
            $connectionUpdateData['lifetime'] = $lifetime;
            $connectionUpdateData['lifetime_updated_at'] = now();
        }

        if ($signature->lifetime !== $lifetime) {
            $signatureUpdateData['lifetime'] = $lifetime;
            $signatureUpdateData['lifetime_updated_at'] = now();
        }

        $signature->mapConnection->update($connectionUpdateData);
        $signature->update($signatureUpdateData);
    }

    private function getMassStatusSeverity(MassStatus $status): int
    {
        return match ($status) {
            MassStatus::Fresh => 1,
            MassStatus::Reduced => 2,
            MassStatus::Critical => 3,
            MassStatus::Unknown => 0,
        };
    }

    private function getNewMassStatus(Signature $signature, SignatureData $data): ?MassStatus
    {
        if (! $data->mass_status instanceof Optional) {
            return $signature->mass_status;
        }

        $sig_mass_severity = $signature->mass_status ? $this->getMassStatusSeverity($signature->mass_status) : 0;
        $conn_mass_severity = $signature->mapConnection->mass_status ? $this->getMassStatusSeverity($signature->mapConnection->mass_status) : 0;

        return match (true) {
            $sig_mass_severity <= $conn_mass_severity => $signature->mapConnection->mass_status,
            default => $signature->mass_status,
        };
    }

    private function getLifetimeSeverity(LifetimeStatus $status): int
    {
        return match ($status) {
            LifetimeStatus::Healthy => 1,
            LifetimeStatus::EndOfLife => 2,
            LifetimeStatus::Critical => 3,
        };
    }

    private function getNewLifetimeValue(Signature $signature, SignatureData $data): LifetimeStatus
    {
        if (! $data->lifetime instanceof Optional) {
            return $data->lifetime;
        }

        $connection_lifetime_severity = $this->getLifetimeSeverity($signature->mapConnection->lifetime);
        $signature_lifetime_severity = $this->getLifetimeSeverity($signature->lifetime);

        return match (true) {
            $signature_lifetime_severity >= $connection_lifetime_severity => $signature->lifetime,
            default => $signature->mapConnection->lifetime,
        };
    }
}
