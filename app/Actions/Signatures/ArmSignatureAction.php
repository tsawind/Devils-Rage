<?php

declare(strict_types=1);

namespace App\Actions\Signatures;

use App\Events\Maps\MapNoticeEvent;
use App\Events\Signatures\SignatureUpdatedEvent;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Models\User;
use App\Support\Broadcasting\MapBroadcaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Patch 13: arm the hole you are about to jump.
 *
 * - Arming is personal: your next jump uses the hole YOU armed. The number it
 *   takes is shared (nobody else gets it).
 * - One arm per person per map: arming another hole releases the old one.
 * - A hole without a number takes the one the scanner's mapper picked (the
 *   next jump-order number in a combat chain, the planned one elsewhere).
 * - "Arm as N" with N armed by someone else (not jumped yet) swaps: you get
 *   N, they get your number, and they get a notice.
 * - Disarming, running out (15 minutes) or turning Combat off gives a number
 *   that arming took back; jumping the hole keeps it.
 */
final readonly class ArmSignatureAction
{
    public function __construct(private MapBroadcaster $mapBroadcaster) {}

    /**
     * @param  string  $alias  the number to arm the hole as
     * @param  string|null  $fallbackAlias  the number someone you swap with gets when your hole has none yet
     *
     * @throws Throwable
     */
    public function arm(User $user, Signature $signature, string $alias, ?string $fallbackAlias = null, bool $swap = false): Signature
    {
        $alias = trim($alias);
        if ($alias === '') {
            throw ValidationException::withMessages(['alias' => 'No number to arm the hole as.']);
        }

        return DB::transaction(function () use ($user, $signature, $alias, $fallbackAlias, $swap): Signature {
            $signature = Signature::query()->with('mapSolarsystem')->lockForUpdate()->findOrFail($signature->id);
            if ($signature->map_connection_id !== null) {
                throw ValidationException::withMessages(['signature' => 'That hole is already jumped.']);
            }
            if ($signature->isArmed() && $signature->armed_by_user_id !== $user->id) {
                throw ValidationException::withMessages(['signature' => sprintf('%s is armed by %s.', $signature->signature_id ?? 'That hole', $signature->armed_by_name ?? 'someone else')]);
            }

            $system = $signature->mapSolarsystem;
            $touched = [$system->id => $system];

            // One arm per person: release the hole you armed before (it keeps a number it had before arming).
            $mine = Signature::query()
                ->with('mapSolarsystem')
                ->where('armed_by_user_id', $user->id)
                ->whereKeyNot($signature->id)
                ->whereHas('mapSolarsystem', fn ($query) => $query->where('map_id', $system->map_id))
                ->lockForUpdate()
                ->get();
            foreach ($mine as $old) {
                $this->clearArm($old);
                $touched[$old->map_solarsystem_id] = $old->mapSolarsystem;
            }

            $previousAlias = $signature->alias;
            if ($previousAlias !== $alias) {
                $holder = Signature::query()
                    ->where('map_solarsystem_id', $signature->map_solarsystem_id)
                    ->whereKeyNot($signature->id)
                    ->where('alias', $alias)
                    ->lockForUpdate()
                    ->first();

                if ($holder instanceof Signature) {
                    $canSwap = $swap && $holder->map_connection_id === null && $holder->isArmed() && $holder->armed_by_user_id !== $user->id;
                    if (! $canSwap) {
                        throw ValidationException::withMessages([
                            'alias' => sprintf('%s is already used by %s.', $alias, $holder->signature_id ?? 'another signature'),
                        ]);
                    }

                    // Swap: they get your number (or the next free one), you get theirs.
                    $theirs = $previousAlias ?? $fallbackAlias;
                    if (blank($theirs)) {
                        throw ValidationException::withMessages(['alias' => 'No number to give in exchange.']);
                    }
                    // Free the number first, so the two never clash in between.
                    $signature->alias = null;
                    $signature->save();
                    $holder->alias = $theirs;
                    $holder->armed_claimed_alias = true;
                    $holder->save();

                    if ($holder->armed_by_user_id !== null) {
                        event(new MapNoticeEvent(
                            $holder->armed_by_user_id,
                            $system->map_id,
                            sprintf('%s took %s, you are now %s', $user->name, $alias, $theirs),
                            sprintf('Bookmark %s as %s in game. Click to copy the new name.', $holder->signature_id ?? 'your hole', $theirs),
                            $holder->id,
                        ));
                    }
                }
            }

            // Arming gave the hole its number when it had none (or changed it): disarming frees it again.
            $signature->armed_claimed_alias = $previousAlias !== $alias || ($signature->armed_by_user_id === $user->id && $signature->armed_claimed_alias);
            $signature->armed_by_user_id = $user->id;
            $signature->armed_by_name = $user->name;
            $signature->armed_at = now();
            $signature->alias = $alias;
            $signature->save();

            $this->broadcast($touched);

            return $signature;
        });
    }

    /**
     * Give up an arm: yours, or one that ran out. A number arming took is freed.
     *
     * @throws Throwable
     */
    public function disarm(Signature $signature): void
    {
        DB::transaction(function () use ($signature): void {
            $signature = Signature::query()->with('mapSolarsystem')->lockForUpdate()->findOrFail($signature->id);
            if ($signature->armed_by_user_id === null) {
                return;
            }
            $this->clearArm($signature);
            $this->broadcast([$signature->map_solarsystem_id => $signature->mapSolarsystem]);
        });
    }

    /**
     * Release everything a person armed on a map (Combat turned off).
     *
     * @throws Throwable
     */
    public function releaseFor(User $user, int $mapId): void
    {
        DB::transaction(function () use ($user, $mapId): void {
            $armed = Signature::query()
                ->with('mapSolarsystem')
                ->where('armed_by_user_id', $user->id)
                ->whereHas('mapSolarsystem', fn ($query) => $query->where('map_id', $mapId))
                ->lockForUpdate()
                ->get();
            $touched = [];
            foreach ($armed as $signature) {
                $this->clearArm($signature);
                $touched[$signature->map_solarsystem_id] = $signature->mapSolarsystem;
            }
            $this->broadcast($touched);
        });
    }

    /**
     * Release arms older than 15 minutes (run every minute by the scheduler).
     *
     * @throws Throwable
     */
    public function releaseExpired(): int
    {
        return DB::transaction(function (): int {
            $expired = Signature::query()
                ->with('mapSolarsystem')
                ->whereNotNull('armed_by_user_id')
                ->where(fn ($query) => $query->whereNull('armed_at')->orWhere('armed_at', '<', now()->subMinutes(Signature::ARM_MINUTES)))
                ->lockForUpdate()
                ->get();
            $touched = [];
            foreach ($expired as $signature) {
                $this->clearArm($signature);
                $touched[$signature->map_solarsystem_id] = $signature->mapSolarsystem;
            }
            $this->broadcast($touched);

            return $expired->count();
        });
    }

    private function clearArm(Signature $signature): void
    {
        if ($signature->armed_claimed_alias && $signature->map_connection_id === null) {
            $signature->alias = null;
        }
        $signature->armed_by_user_id = null;
        $signature->armed_by_name = null;
        $signature->armed_at = null;
        $signature->armed_claimed_alias = false;
        $signature->save();
    }

    /**
     * @param  array<int, MapSolarsystem>  $systems
     */
    private function broadcast(array $systems): void
    {
        $mapIds = [];
        foreach ($systems as $system) {
            $this->mapBroadcaster->signaturesChanged($system);
            $mapIds[$system->map_id] = true;
        }
        foreach (array_keys($mapIds) as $mapId) {
            broadcast(new SignatureUpdatedEvent($mapId));
        }
    }
}
