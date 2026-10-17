<?php

declare(strict_types=1);

namespace App\Actions\MapSolarsystem;

use App\Actions\MapConnections\BroadcastMapConnectionAction;
use App\Events\Signatures\SignatureUpdatedEvent;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Support\Broadcasting\MapBroadcaster;
use App\Support\ChainMemory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Patch 39: floating names. A system has one main name; the other names it is reached by
 * float above its card ("also A2", "was A1~1917"). The mapper never renames on its own:
 * - switch: a loop's popup "Switch to A2": the system takes the hole's number; its old main
 *   name floats on (through the hole that carries it, or as a "was" name).
 * - drop: ✕ on a floating name: a loop's hole goes back to the main name, a "was" name is forgotten.
 * - adopt: an old chain's row "Done": the system takes the new hole's number, the old
 *   stamped name becomes "was".
 * - keep: an old chain's row "Keep old": the old name stays, the rows go away.
 * - hole: an old chain's hole row "Done": the hole and the system beyond take the new name,
 *   the old one becomes "was" there (its way back is still to re-bookmark).
 * - rename: a double-click rename whose name is taken: swap, give the other the next number,
 *   or keep it as a floating name.
 * Main names are unique on the map; floating names never hold a number.
 */
final readonly class MapNamesAction
{
    public function __construct(
        private UpdateMapSolarsystemAction $updateMapSolarsystemAction,
        private BroadcastMapConnectionAction $broadcastMapConnectionAction,
        private MapBroadcaster $mapBroadcaster,
    ) {}

    /**
     * Switch a system's main name to the number of the hole `$signature` (on the far side) leads in by.
     *
     * @throws Throwable
     */
    public function switchTo(MapSolarsystem $system, Signature $signature): void
    {
        DB::transaction(function () use ($system, $signature): void {
            $connection = $this->holeInto($system, $signature);
            $name = mb_trim((string) $signature->alias);
            if ($name === '') {
                throw ValidationException::withMessages(['alias' => 'That hole has no number to switch to.']);
            }
            $this->guardMainName($system, $name);

            $old = (string) $system->alias;
            $heldByHole = $old !== '' && Signature::query()
                ->where('map_connection_id', '!=', $connection->id)
                ->whereIn('map_connection_id', $this->connectionIds($system))
                ->where('map_solarsystem_id', '!=', $system->id)
                ->where('alias', $old)
                ->exists();

            $this->updateMapSolarsystemAction->handle($system, [
                'alias' => $name,
                'old_aliases' => $heldByHole || $old === '' ? $this->withoutName($system->old_aliases, $name) : $this->withName($this->withoutName($system->old_aliases, $name), $old),
            ]);
        });
    }

    /**
     * ✕ on a floating name: a loop's hole name (`$signature`) or an earlier name (`$name`).
     *
     * @throws Throwable
     */
    public function drop(MapSolarsystem $system, ?Signature $signature, ?string $name): void
    {
        DB::transaction(function () use ($system, $signature, $name): void {
            if ($signature instanceof Signature) {
                $connection = $this->holeInto($system, $signature);
                $signature->update(['alias' => null]);
                $this->afterHoleChange($signature, $connection);

                return;
            }

            $this->updateMapSolarsystemAction->handle($system, ['old_aliases' => $this->withoutName($system->old_aliases, (string) $name)]);
        });
    }

    /**
     * An old chain's row "Done": the system takes the new hole's number, the old name is "was".
     *
     * @throws Throwable
     */
    public function adopt(MapSolarsystem $system, Signature $signature): void
    {
        DB::transaction(function () use ($system, $signature): void {
            $this->holeInto($system, $signature);
            $name = mb_trim((string) $signature->alias);
            if ($name === '' || ChainMemory::isStamped($name)) {
                throw ValidationException::withMessages(['alias' => 'That hole has no new number yet.']);
            }
            $this->guardMainName($system, $name);
            $old = (string) $system->alias;

            $this->updateMapSolarsystemAction->handle($system, [
                'alias' => $name,
                'old_aliases' => $old === '' ? $this->withoutName($system->old_aliases, $name) : $this->withName($this->withoutName($system->old_aliases, $name), $old),
                'name_kept' => false,
            ]);
        });
    }

    /**
     * An old chain's row "Keep old": no rename rows for this system any more.
     */
    public function keep(MapSolarsystem $system): void
    {
        $this->updateMapSolarsystemAction->handle($system, ['name_kept' => true]);
    }

    /**
     * An old chain's hole row "Done": the hole (`$signature`, in `$system`) and the system it
     * leads to take `$name`; that system's old name becomes "was", its way back waits.
     *
     * @throws Throwable
     */
    public function renameHole(MapSolarsystem $system, Signature $signature, string $name): void
    {
        $name = mb_trim($name);
        if ($name === '' || ChainMemory::isStamped($name)) {
            throw ValidationException::withMessages(['alias' => 'No new name given.']);
        }

        DB::transaction(function () use ($system, $signature, $name): void {
            if ((int) $signature->map_solarsystem_id !== (int) $system->id) {
                throw ValidationException::withMessages(['alias' => 'That hole is not in this system.']);
            }
            $taken = Signature::query()
                ->where('map_solarsystem_id', $system->id)
                ->whereKeyNot($signature->id)
                ->where('alias', $name)
                ->exists();
            if ($taken) {
                throw ValidationException::withMessages(['alias' => sprintf('%s is already used by a hole here.', $name)]);
            }

            $connection = $signature->map_connection_id !== null ? MapConnection::query()->find($signature->map_connection_id) : null;
            $far = $connection instanceof MapConnection
                ? MapSolarsystem::query()->find((int) $connection->from_map_solarsystem_id === (int) $system->id ? $connection->to_map_solarsystem_id : $connection->from_map_solarsystem_id)
                : null;

            if ($far instanceof MapSolarsystem && ChainMemory::isStamped($far->alias)) {
                $this->guardMainName($far, $name);
                $this->updateMapSolarsystemAction->handle($far, [
                    'alias' => $name,
                    'old_aliases' => $this->withName($this->withoutName($far->old_aliases, $name), (string) $far->alias),
                    'cleanup_return_pending' => true,
                    'name_kept' => false,
                ]);
            }

            $signature->update(['alias' => $name]);
            $this->afterHoleChange($signature, $connection);
        });
    }

    /**
     * Double-click rename of a hole (in `$system`) and the system it leads to, when the name is
     * taken: swap with whoever has it, give them `$next`, or keep it as a floating name (the
     * system it leads to keeps its main name). `plain` refuses a taken name.
     *
     * @throws Throwable
     */
    public function renameTo(MapSolarsystem $system, Signature $signature, string $name, string $mode, ?string $next): void
    {
        $name = mb_trim($name);
        if ($name === '' || str_contains($name, ChainMemory::STAMP) || str_contains($name, '@')) {
            throw ValidationException::withMessages(['alias' => 'That name can\'t be used.']);
        }

        DB::transaction(function () use ($system, $signature, $name, $mode, $next): void {
            if ((int) $signature->map_solarsystem_id !== (int) $system->id) {
                throw ValidationException::withMessages(['alias' => 'That hole is not in this system.']);
            }
            $connection = $signature->map_connection_id !== null ? MapConnection::query()->find($signature->map_connection_id) : null;
            $target = $connection instanceof MapConnection
                ? MapSolarsystem::query()->find((int) $connection->from_map_solarsystem_id === (int) $system->id ? $connection->to_map_solarsystem_id : $connection->from_map_solarsystem_id)
                : null;
            $old = (string) ($signature->alias ?? $target?->alias ?? '');

            $holderHole = Signature::query()->where('map_solarsystem_id', $system->id)->whereKeyNot($signature->id)->where('alias', $name)->first();
            $holderSystem = MapSolarsystem::query()->where('map_id', $system->map_id)->where('alias', $name)
                ->when($target instanceof MapSolarsystem, fn ($query) => $query->whereKeyNot($target->id))
                ->first();
            $taken = $holderHole instanceof Signature || $holderSystem instanceof MapSolarsystem;

            if ($taken && $mode === 'plain') {
                throw ValidationException::withMessages(['alias' => sprintf('%s is already used on the map.', $name)]);
            }
            if ($mode === 'float' && ! $target instanceof MapSolarsystem) {
                throw ValidationException::withMessages(['alias' => 'Only a jumped hole can keep a floating name.']);
            }
            $give = match ($mode) {
                'swap' => $old,
                'bump' => mb_trim((string) $next),
                default => null,
            };
            if ($taken && $give !== null) {
                if ($give === '') {
                    throw ValidationException::withMessages(['alias' => 'No name to give the other one.']);
                }
                $this->renameHolder($holderHole, $holderSystem, $name, $give);
            }

            $signature->update(['alias' => $name]);
            if ($target instanceof MapSolarsystem && $mode !== 'float' && ! ChainMemory::isStamped($target->alias)) {
                $this->updateMapSolarsystemAction->handle($target, ['alias' => $name]);
            }
            $this->afterHoleChange($signature, $connection);
        });
    }

    /**
     * Give the hole / system that has `$name` the name `$give` (and the hole leading into that system).
     */
    private function renameHolder(?Signature $holderHole, ?MapSolarsystem $holderSystem, string $name, string $give): void
    {
        if ($holderHole instanceof Signature) {
            $holderHole->update(['alias' => $give]);
            $this->afterHoleChange($holderHole, $holderHole->map_connection_id !== null ? MapConnection::query()->find($holderHole->map_connection_id) : null);
        }
        if (! $holderSystem instanceof MapSolarsystem) {
            return;
        }
        $this->updateMapSolarsystemAction->handle($holderSystem, ['alias' => $give]);
        $holes = Signature::query()
            ->whereIn('map_connection_id', $this->connectionIds($holderSystem))
            ->where('map_solarsystem_id', '!=', $holderSystem->id)
            ->where('alias', $name)
            ->get();
        foreach ($holes as $hole) {
            $hole->update(['alias' => $give]);
            $this->afterHoleChange($hole, $hole->map_connection_id !== null ? MapConnection::query()->find($hole->map_connection_id) : null);
        }
    }

    /**
     * The connection `$signature` (on the far side) leads into `$system` by.
     *
     * @throws ValidationException
     */
    private function holeInto(MapSolarsystem $system, Signature $signature): MapConnection
    {
        $connection = $signature->map_connection_id !== null ? MapConnection::query()->find($signature->map_connection_id) : null;
        $touches = $connection instanceof MapConnection
            && in_array((int) $system->id, [(int) $connection->from_map_solarsystem_id, (int) $connection->to_map_solarsystem_id], true)
            && (int) $signature->map_solarsystem_id !== (int) $system->id;
        if (! $connection instanceof MapConnection || ! $touches) {
            throw ValidationException::withMessages(['alias' => 'That hole does not lead into this system.']);
        }

        return $connection;
    }

    /**
     * Main names are unique on the map (floating names never block one).
     *
     * @throws ValidationException
     */
    private function guardMainName(MapSolarsystem $system, string $name): void
    {
        $clash = MapSolarsystem::query()
            ->where('map_id', $system->map_id)
            ->whereKeyNot($system->id)
            ->where('alias', $name)
            ->first();
        if ($clash instanceof MapSolarsystem) {
            throw ValidationException::withMessages(['alias' => sprintf('%s is already the name of another system on the map.', $name)]);
        }
    }

    /**
     * @return list<int>
     */
    private function connectionIds(MapSolarsystem $system): array
    {
        return MapConnection::query()
            ->where(fn ($query) => $query->where('from_map_solarsystem_id', $system->id)->orWhere('to_map_solarsystem_id', $system->id))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<string>|null  $names
     * @return list<string>
     */
    private function withName(?array $names, string $name): array
    {
        $name = mb_trim($name);

        return $name === '' ? array_values($names ?? []) : [...$this->withoutName($names, $name), $name];
    }

    /**
     * @param  list<string>|null  $names
     * @return list<string>
     */
    private function withoutName(?array $names, string $name): array
    {
        $upper = mb_strtoupper(mb_trim($name));

        return array_values(array_filter($names ?? [], fn (mixed $candidate): bool => is_string($candidate) && mb_strtoupper(mb_trim($candidate)) !== $upper));
    }

    private function afterHoleChange(Signature $signature, ?MapConnection $connection): void
    {
        $system = MapSolarsystem::query()->find($signature->map_solarsystem_id);
        if ($system instanceof MapSolarsystem) {
            $this->mapBroadcaster->signaturesChanged($system);
            broadcast(new SignatureUpdatedEvent($system->map_id));
        }
        if ($connection instanceof MapConnection) {
            $this->broadcastMapConnectionAction->handle($connection);
        }
    }
}
