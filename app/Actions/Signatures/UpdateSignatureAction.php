<?php

declare(strict_types=1);

namespace App\Actions\Signatures;

use App\Actions\MapConnections\BroadcastMapConnectionAction;
use App\Actions\MapConnections\InferMassStatusFromJumpsAction;
use App\Actions\MapConnections\SyncConnectionShipSizeAction;
use App\Actions\MapSolarsystem\UpdateMapSolarsystemAction;
use App\Data\SignatureData;
use App\Enums\LifetimeStatus;
use App\Enums\MassStatus;
use App\Events\Signatures\SignatureUpdatedEvent;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Models\SignatureType;
use App\Models\WormholeStatic;
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
        private FillFarSideK162Action $fillFarSideK162Action,
        private BroadcastMapConnectionAction $broadcastMapConnectionAction,
        private InferMassStatusFromJumpsAction $inferMassStatusFromJumpsAction,
    ) {}

    /**
     * @throws Throwable
     */
    public function handle(Signature $signature, SignatureData $data): Signature
    {
        return DB::transaction(function () use ($signature, $data): Signature {
            $this->lockOthers($signature, $data);
            $this->guardChainNumbering($signature, $data);
            $previousAlias = $signature->alias;
            $previousConnectionId = $signature->map_connection_id;

            $updateData = $data->toArray();
            // Not columns: whether the linked system follows a confirmed rename; numbers to lock elsewhere.
            unset($updateData['rename_system'], $updateData['lock_others']);

            // Update wormhole_id if signature_type_id changed, resetting it when cleared
            if (! $data->signature_type_id instanceof Optional) {
                $signatureType = $data->signature_type_id ? SignatureType::query()->find($data->signature_type_id) : null;
                $updateData['wormhole_id'] = $signatureType?->wormhole?->id;
            }

            $signature->update($updateData);

            $this->syncConnectedSystemAlias($signature, $data, $previousAlias);
            $this->syncMassAndLifetime($signature, $data);
            $this->syncConnectionShipSizeAction->handle($signature);
            // Patch 33: a newly known type can turn the logged jumps into a status.
            if (! $data->signature_type_id instanceof Optional && $signature->mapConnection !== null) {
                $this->inferMassStatusFromJumpsAction->handle($signature->mapConnection);
            }

            // Linking a hole to a connection, or typing it, may reveal the far side as a K162.
            if (! $data->map_connection_id instanceof Optional || ! $data->signature_type_id instanceof Optional) {
                $this->fillFarSideK162Action->handle($signature->map_connection_id);
            }

            broadcast(new SignatureUpdatedEvent($signature->mapSolarsystem->map_id))->toOthers();

            $this->mapBroadcaster->signaturesChanged($signature->mapSolarsystem);
            $this->broadcastConnections([$previousConnectionId, $signature->map_connection_id]);

            return $signature;
        });
    }

    /**
     * Patch 14: a rename here would shift the planned numbers of the other
     * unjumped holes in this system: lock them to the numbers they show now.
     * Only holes without a number of their own, and only numbers not in use.
     */
    private function lockOthers(Signature $signature, SignatureData $data): void
    {
        if ($data->lock_others instanceof Optional || $data->lock_others === []) {
            return;
        }

        $others = Signature::query()
            ->where('map_solarsystem_id', $signature->map_solarsystem_id)
            ->whereKeyNot($signature->id)
            ->whereIn('id', array_map(intval(...), array_keys($data->lock_others)))
            ->whereNull('alias')
            ->whereNull('map_connection_id')
            ->get();

        foreach ($others as $other) {
            $alias = mb_trim((string) ($data->lock_others[$other->id] ?? $data->lock_others[(string) $other->id] ?? ''));
            if ($alias === '') {
                continue;
            }
            $taken = Signature::query()
                ->where('map_solarsystem_id', $signature->map_solarsystem_id)
                ->where('alias', $alias)
                ->exists();
            if (! $taken) {
                $other->update(['alias' => $alias]);
            }
        }
    }

    /**
     * A chain number may only be used once per system, and a system has no more
     * holes marked static than it has statics.
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
            $this->guardStaticCount($signature, $data);
        }
    }

    /**
     * A system with two or three statics (Golf: C247 and H900) may have that many
     * holes marked as static, but never two of the same static type.
     *
     * @throws ValidationException
     */
    private function guardStaticCount(Signature $signature, SignatureData $data): void
    {
        $otherStatics = Signature::query()
            ->where('map_solarsystem_id', $signature->map_solarsystem_id)
            ->whereKeyNot($signature->id)
            ->where('is_static', true)
            ->get();

        if ($otherStatics->isEmpty()) {
            return;
        }

        $staticCount = max(1, WormholeStatic::query()
            ->where('wormhole_system_id', $signature->mapSolarsystem()->value('solarsystem_id'))
            ->count());

        $wormholeId = $data->signature_type_id instanceof Optional
            ? $signature->wormhole_id
            : ($data->signature_type_id ? SignatureType::query()->find($data->signature_type_id)?->wormhole?->id : null);
        $sameType = $wormholeId === null
            ? null
            : $otherStatics->first(fn (Signature $other): bool => $other->wormhole_id === $wormholeId);

        $clash = $sameType ?? ($otherStatics->count() >= $staticCount ? $otherStatics->first() : null);

        if ($clash instanceof Signature) {
            throw ValidationException::withMessages([
                'is_static' => sprintf('Signature %s is already marked as the static.', $clash->signature_id ?? 'without an ID'),
            ]);
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

        $confirmed = ! $data->rename_system instanceof Optional && $data->rename_system;
        if ($confirmed || $other->alias === null || $other->alias === $previousAlias) {
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

    /**
     * Patch 20: a jumped hole's type, static mark, mass or life lives on its
     * connection too (pipe size, "Static" tag, ≈ guess). Re-send those
     * connections so everyone else's map updates without a refresh.
     *
     * @param  array<int, int|null>  $connectionIds
     */
    private function broadcastConnections(array $connectionIds): void
    {
        foreach (array_unique(array_filter($connectionIds)) as $connectionId) {
            $connection = MapConnection::query()->find($connectionId);
            if ($connection instanceof MapConnection) {
                $this->broadcastMapConnectionAction->handle($connection);
            }
        }
    }
}
