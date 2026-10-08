<?php

declare(strict_types=1);

namespace App\Actions\MapRageRoll;

use App\Actions\MapSolarsystem\HideOldChainsAction;
use App\Actions\Signatures\DeleteSignatureAction;
use App\Enums\SignatureCategory;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Support\Broadcasting\MapBroadcaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Patch 35: "New Alpha?" → Yes. The old static's chain keeps its names with a
 * stamp of when it was first mapped, in EVE time ("A" → "A@1958", "A1" →
 * "A1@1958"), so its slot is free at once; the old static's signature (and its
 * pipe) is deleted and the old chain hides (patch 36, unless a pilot is still
 * in it, it is pinned or it has another way out), and the new signature becomes the static with the old name
 * and, when known, the old static's hole type.
 */
final readonly class NewStaticAction
{
    public function __construct(
        private DeleteSignatureAction $deleteSignatureAction,
        private MapBroadcaster $mapBroadcaster,
        private HideOldChainsAction $hideOldChainsAction,
    ) {}

    public static function isInChain(string $alias, string $base): bool
    {
        $name = mb_strtoupper(mb_trim($alias));
        if ($name === '' || str_contains($name, '@') || preg_match('/^[A-Z0-9]+$/', $name) !== 1) {
            return false;
        }

        // Chain numbers below a hole always carry a digit ("A1", "A0B"); plain names like "Daisy" don't.
        return $name === $base || (str_starts_with($name, $base) && mb_strlen($name) > mb_strlen($base) && preg_match('/\d/', $name) === 1);
    }

    public static function stamped(string $alias, string $stamp): string
    {
        return mb_strtoupper(mb_trim($alias)).'@'.$stamp;
    }

    /**
     * @return array{alias: string|null, stamp: string|null, stamped: int, hidden: int}
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function handle(MapSolarsystem $rolling, Signature $old, Signature $new): array
    {
        if ($old->map_solarsystem_id !== $rolling->id || $new->map_solarsystem_id !== $rolling->id) {
            throw ValidationException::withMessages(['signature_id' => 'Both signatures must be in the system being rolled.']);
        }
        if ($old->id === $new->id) {
            throw ValidationException::withMessages(['signature_id' => 'Pick a different signature for the new static.']);
        }
        if ($new->signatureCategory?->code !== SignatureCategory::Wormhole && $new->signature_category_id !== null) {
            throw ValidationException::withMessages(['signature_id' => 'The new static has to be a wormhole signature.']);
        }

        $result = DB::transaction(function () use ($rolling, $old, $new): array {
            $old_system = $this->farSide($rolling, $old);
            $base = mb_strtoupper(mb_trim((string) ($old->alias ?? $old_system->alias ?? '')));
            $stamped = 0;
            $stamp = null;

            if ($old_system !== null && $base !== '' && ! str_contains($base, '@')) {
                $stamp = $old_system->created_at->utc()->format('Hi');
                $stamped = $this->stampChain($rolling, $base, $stamp);
            }

            $wormhole_id = $old->wormhole_id;
            $this->deleteSignatureAction->handle($old, without_events: true);

            $new->refresh();
            $new->update([
                'is_static' => true,
                'is_wandering' => false,
                'alias' => $base !== '' && ! str_contains($base, '@') ? $base : $new->alias,
                'wormhole_id' => $new->wormhole_id ?? $wormhole_id,
            ]);

            return ['alias' => $base !== '' ? $base : null, 'stamp' => $stamp, 'stamped' => $stamped, 'old_system_id' => $old_system?->id];
        });

        // Patch 36: the old chain hides, unless a pilot is still in it, it is pinned or it has another way out.
        $hidden = $result['old_system_id'] === null
            ? []
            : $this->hideOldChainsAction->handle($rolling->map, [(int) $result['old_system_id']]);

        $this->mapBroadcaster->resync($rolling->map_id);

        return ['alias' => $result['alias'], 'stamp' => $result['stamp'], 'stamped' => $result['stamped'], 'hidden' => count($hidden)];
    }

    /**
     * The system on the other side of the old static's pipe, if it was jumped.
     */
    private function farSide(MapSolarsystem $rolling, Signature $old): ?MapSolarsystem
    {
        if ($old->map_connection_id === null) {
            return null;
        }

        $connection = MapConnection::query()->find($old->map_connection_id);
        if ($connection === null) {
            return null;
        }

        $far_id = $connection->from_map_solarsystem_id === $rolling->id
            ? $connection->to_map_solarsystem_id
            : $connection->from_map_solarsystem_id;

        return MapSolarsystem::query()->find($far_id);
    }

    /**
     * Stamp every system named `base` or further down it (A, A1, A12…), and the
     * numbers locked on their signatures. Plain names that only share a first
     * letter ("Daisy") and names already stamped are left alone.
     */
    private function stampChain(MapSolarsystem $rolling, string $base, string $stamp): int
    {
        $systems = MapSolarsystem::query()
            ->where('map_id', $rolling->map_id)
            ->whereKeyNot($rolling->id)
            ->whereNotNull('alias')
            ->get()
            ->filter(fn (MapSolarsystem $system): bool => self::isInChain((string) $system->alias, $base));

        foreach ($systems as $system) {
            $system->update(['alias' => self::stamped((string) $system->alias, $stamp)]);
        }

        Signature::query()
            ->whereIn('map_solarsystem_id', $systems->pluck('id'))
            ->whereNotNull('alias')
            ->get()
            ->filter(fn (Signature $signature): bool => ! str_contains((string) $signature->alias, '@'))
            ->each(fn (Signature $signature) => $signature->update(['alias' => self::stamped((string) $signature->alias, $stamp)]));

        return $systems->count();
    }
}
