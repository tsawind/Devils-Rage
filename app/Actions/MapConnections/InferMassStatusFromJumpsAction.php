<?php

declare(strict_types=1);

namespace App\Actions\MapConnections;

use App\Enums\MassStatus;
use App\Models\MapConnection;
use App\Models\Wormhole;

/**
 * Patch 33: logged jumps can prove a hole's status. A hole spawns with its type's mass
 * ±10%, so once the logged mass passes half of the biggest it can be it IS reduced, past
 * 90% of it it IS critical, and past all of it it must have rolled (V753, 3.3 B: 1,815 M,
 * 3,267 M, 3,630 M). Logged mass is base ship mass, so the real hole is at least that far
 * gone. Only ever makes the status worse; a hand-set status is never improved.
 */
final readonly class InferMassStatusFromJumpsAction
{
    public const float REDUCED_SHARE = 0.55;

    public const float CRITICAL_SHARE = 0.99;

    public const float ROLLED_SHARE = 1.1;

    /** Returns true when it changed the connection. */
    public function handle(MapConnection $connection): bool
    {
        $total = $this->totalMass($connection);
        if ($total === null) {
            return false;
        }

        $logged = (int) $connection->jumps()->sum('mass');
        $changes = [];

        $proven = match (true) {
            $logged >= $total * self::CRITICAL_SHARE => MassStatus::Critical,
            $logged >= $total * self::REDUCED_SHARE => MassStatus::Reduced,
            default => null,
        };
        $current = $connection->mass_status instanceof MassStatus ? $connection->mass_status : MassStatus::tryFrom((string) $connection->mass_status);
        if ($proven instanceof MassStatus && $this->severity($proven) > $this->severity($current)) {
            $changes['mass_status'] = $proven;
            $changes['mass_status_from_log'] = true;
        }

        $rolled = $logged >= $total * self::ROLLED_SHARE;
        if ($rolled !== (bool) $connection->should_have_rolled) {
            $changes['should_have_rolled'] = $rolled;
        }

        if ($changes === []) {
            return false;
        }

        $connection->update($changes);
        if (isset($changes['mass_status'])) {
            $connection->signatures()->update(['mass_status' => $changes['mass_status']]);
        }

        return true;
    }

    private function totalMass(MapConnection $connection): ?float
    {
        $typed = $connection->signatures()
            ->whereNotNull('wormhole_id')
            ->with('wormhole:id,name,total_mass')
            ->get()
            ->map(fn ($signature): ?Wormhole => $signature->wormhole)
            ->first(fn (?Wormhole $wormhole): bool => $wormhole instanceof Wormhole && ! str_starts_with(mb_strtoupper($wormhole->name), 'K162'));
        $wormhole = $typed ?? ($connection->wormhole_id !== null ? Wormhole::query()->find($connection->wormhole_id) : null);
        $total = $wormhole?->total_mass;

        return $total !== null && $total > 0 ? (float) $total : null;
    }

    private function severity(?MassStatus $status): int
    {
        return match ($status) {
            MassStatus::Critical => 3,
            MassStatus::Reduced => 2,
            default => 1,
        };
    }
}
