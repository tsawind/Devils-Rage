<?php

declare(strict_types=1);

namespace App\Services\Discord;

use App\Enums\ConnectionType;
use App\Enums\LifetimeStatus;
use App\Enums\MassStatus;
use App\Enums\ShipSize;
use App\Models\Map;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Models\Wormhole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Patch 39: the way from home to a system in the chain, for Discord posts (#daisy-chain-kills,
 * #chain-exits): each hole on the way with its type, size letter, direction and mass / life,
 * and what fits through the tightest one ("Fits: battleships · 1.2–2.0B left").
 */
final class ChainWayDescriber
{
    /** Ship classes by the largest single jump a hole allows (kg). */
    private const array FITS = [
        5_000_000 => 'frigates only',
        62_500_000 => 'cruisers',
        375_000_000 => 'battleships',
    ];

    /** Home's holes by their callsign (as the mapper shows them). */
    private const array CALLSIGNS = [
        'A' => 'Alpha', 'B' => 'Bravo', 'D' => 'Delta', 'G' => 'Golf', 'J' => 'Juliett', 'K' => 'Kilo', 'M' => 'Mike', 'N' => 'November', 'P' => 'Papa',
        'Q' => 'Quebec', 'R' => 'Romeo', 'S' => 'Sierra', 'T' => 'Tango', 'U' => 'Uniform', 'V' => 'Victor', 'W' => 'Whiskey', 'X' => 'X-ray', 'Y' => 'Yankee', 'Z' => 'Zulu',
    ];

    /** @var array<int, array{systems: Collection<int, MapSolarsystem>, connections: Collection<int, MapConnection>}> */
    private array $cache = [];

    /**
     * The way from the map's home to `$solarsystemId` (a system on the map), or null when
     * it isn't on the map or can't be reached from home.
     *
     * @return array{jumps: int, home: string, line: string, fits: string|null, alias: string|null}|null
     */
    public function fromHome(Map $map, int $solarsystemId): ?array
    {
        ['systems' => $systems, 'connections' => $connections] = $this->load($map);
        $home = $systems->first(fn (MapSolarsystem $system): bool => (int) $system->solarsystem_id === (int) $map->home_solarsystem_id);
        $target = $systems->first(fn (MapSolarsystem $system): bool => (int) $system->solarsystem_id === $solarsystemId);
        if (! $home instanceof MapSolarsystem || ! $target instanceof MapSolarsystem) {
            return null;
        }

        $path = $this->path($home->id, $target->id, $connections);
        if ($path === null) {
            return null;
        }

        $byId = $systems->keyBy('id');
        $steps = [];
        $tightest = null;
        for ($index = 1, $count = count($path); $index < $count; $index++) {
            $near = $byId[$path[$index - 1]];
            $far = $byId[$path[$index]];
            $connection = $this->between($connections, $near->id, $far->id);
            $hole = $connection instanceof MapConnection ? $this->hole($connection, $near->id) : null;
            $steps[] = sprintf('%s%s', $this->name($far), $hole !== null ? ' ('.$hole['text'].')' : '');
            if ($hole !== null && $hole['max_jump'] !== null && ($tightest === null || $hole['max_jump'] < $tightest['max_jump'])) {
                $tightest = $hole;
            }
        }

        return [
            'jumps' => count($path) - 1,
            'home' => $this->name($home),
            'line' => implode(' → ', [$this->name($home), ...$steps]),
            'fits' => $tightest !== null ? $this->fits($tightest) : null,
            'alias' => $target->alias,
        ];
    }

    /**
     * The name of a system on the map: its alias (callsigns for home's holes), else its name.
     */
    public function name(MapSolarsystem $system): string
    {
        $alias = mb_trim((string) $system->alias);
        if ($alias === '') {
            return (string) $system->solarsystem->name;
        }

        return self::CALLSIGNS[mb_strtoupper($alias)] ?? $alias;
    }

    /**
     * @return array{systems: Collection<int, MapSolarsystem>, connections: Collection<int, MapConnection>}
     */
    private function load(Map $map): array
    {
        return $this->cache[$map->id] ??= [
            'systems' => MapSolarsystem::query()->where('map_id', $map->id)->with('solarsystem:id,name,security')->get(),
            'connections' => MapConnection::query()
                ->where('map_id', $map->id)
                ->with('signatures.wormhole')
                ->withSum('jumps', 'mass')
                ->get(),
        ];
    }

    /**
     * Breadth-first from home over the map's pipes (stargates included).
     *
     * @param  Collection<int, MapConnection>  $connections
     * @return list<int>|null
     */
    private function path(int $from, int $to, Collection $connections): ?array
    {
        $neighbours = [];
        foreach ($connections as $connection) {
            $neighbours[$connection->from_map_solarsystem_id][] = $connection->to_map_solarsystem_id;
            $neighbours[$connection->to_map_solarsystem_id][] = $connection->from_map_solarsystem_id;
        }
        $parent = [$from => null];
        $queue = [$from];
        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current === $to) {
                $path = [];
                for ($node = $to; $node !== null; $node = $parent[$node]) {
                    $path[] = $node;
                }

                return array_reverse($path);
            }
            foreach ($neighbours[$current] ?? [] as $next) {
                if (! array_key_exists($next, $parent)) {
                    $parent[$next] = $current;
                    $queue[] = $next;
                }
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, MapConnection>  $connections
     */
    private function between(Collection $connections, int $a, int $b): ?MapConnection
    {
        return $connections->first(fn (MapConnection $connection): bool => ($connection->from_map_solarsystem_id === $a && $connection->to_map_solarsystem_id === $b)
            || ($connection->from_map_solarsystem_id === $b && $connection->to_map_solarsystem_id === $a));
    }

    /**
     * One hole on the way, seen from the side nearer home.
     *
     * @return array{text: string, max_jump: float|null, total: float|null, logged: float, mass: MassStatus|null, life: LifetimeStatus, eol_since: CarbonImmutable|null, critical_since: CarbonImmutable|null}|null
     */
    private function hole(MapConnection $connection, int $nearId): ?array
    {
        if ($connection->type === ConnectionType::Stargate || $connection->type === ConnectionType::Stargate->value) {
            return null;
        }

        $near = $connection->signatures->first(fn (Signature $signature): bool => (int) $signature->map_solarsystem_id === $nearId);
        $far = $connection->signatures->first(fn (Signature $signature): bool => (int) $signature->map_solarsystem_id !== $nearId);
        $nearType = $this->realType($near);
        $farType = $this->realType($far);
        $type = $nearType ?? $farType;
        $arrow = $nearType instanceof Wormhole ? '→' : ($farType instanceof Wormhole ? '←' : '');
        $size = $connection->ship_size instanceof ShipSize ? $connection->ship_size : (is_string($connection->ship_size) ? ShipSize::tryFrom($connection->ship_size) : null);
        $mass = $connection->mass_status instanceof MassStatus ? $connection->mass_status : MassStatus::tryFrom((string) $connection->mass_status);
        $life = $connection->lifetime;

        $parts = array_filter([
            $type instanceof Wormhole ? $type->name : 'K162',
            trim(sprintf('%s %s', $this->sizeLetter($size, $type), $arrow)),
            match ($mass) {
                MassStatus::Reduced => 'reduced',
                MassStatus::Critical => 'crit',
                default => null,
            },
            match ($life) {
                LifetimeStatus::EndOfLife => 'EOL',
                LifetimeStatus::Critical => 'EOL!',
                LifetimeStatus::Imminent => 'closing',
                default => null,
            },
        ], fn (?string $part): bool => $part !== null && $part !== '');

        $maxJump = $type instanceof Wormhole ? (float) $type->maximum_jump_mass : $this->jumpForSize($size);

        return [
            'text' => implode(' ', $parts),
            'max_jump' => $maxJump,
            'total' => $type instanceof Wormhole ? (float) $type->total_mass : null,
            'logged' => (float) ($connection->jumps_sum_mass ?? 0),
            'mass' => $mass,
            'life' => $life,
            'eol_since' => $connection->eol_since,
            'critical_since' => $connection->critical_since,
        ];
    }

    private function realType(?Signature $signature): ?Wormhole
    {
        $type = $signature?->wormhole;

        return $type instanceof Wormhole && ! str_starts_with(mb_strtoupper($type->name), 'K162') ? $type : null;
    }

    private function sizeLetter(?ShipSize $size, ?Wormhole $type): string
    {
        $jump = $type instanceof Wormhole ? (float) $type->maximum_jump_mass : null;
        if ($jump !== null) {
            return match (true) {
                $jump <= 5_000_000 => 'S',
                $jump <= 62_500_000 => 'M',
                $jump <= 375_000_000 => 'L',
                default => 'XL',
            };
        }

        return match ($size) {
            ShipSize::Frigate => 'S',
            ShipSize::Medium => 'M',
            ShipSize::Large => 'L',
            ShipSize::ExtraLarge => 'XL',
            default => '',
        };
    }

    private function jumpForSize(?ShipSize $size): ?float
    {
        return match ($size) {
            ShipSize::Frigate => 5_000_000.0,
            ShipSize::Medium => 62_500_000.0,
            ShipSize::Large => 375_000_000.0,
            ShipSize::ExtraLarge => 1_000_000_000.0,
            default => null,
        };
    }

    /**
     * "Fits: battleships · 1.2–2.0B left · EOL, collapsing by 15:32 EVE".
     *
     * @param  array{text: string, max_jump: float|null, total: float|null, logged: float, mass: MassStatus|null, life: LifetimeStatus, eol_since: CarbonImmutable|null, critical_since: CarbonImmutable|null}  $hole
     */
    private function fits(array $hole): string
    {
        $class = 'capitals';
        foreach (self::FITS as $limit => $label) {
            if ((float) $hole['max_jump'] <= $limit) {
                $class = $label;
                break;
            }
        }

        $parts = ['Fits: '.$class];
        if ($hole['total'] !== null) {
            [$low, $high] = $this->massLeft($hole['total'], $hole['logged'], $hole['mass']);
            $parts[] = sprintf('%s–%s left', $this->billions($low), $this->billions($high));
        }

        $latest = match ($hole['life']) {
            LifetimeStatus::Critical => $hole['critical_since']?->addHour() ?? $hole['eol_since']?->addHours(4),
            LifetimeStatus::EndOfLife, LifetimeStatus::Imminent => $hole['eol_since']?->addHours(4),
            default => null,
        };
        if ($latest !== null) {
            $parts[] = sprintf('EOL, collapsing by %s EVE', $latest->utc()->format('H:i'));
        }

        return implode(' · ', $parts);
    }

    /**
     * What a hole may have left (kg), as the mapper estimates it: its size ±10% less the
     * logged jumps, held inside its mass status (fresh ≥ half, reduced ≤ half, crit ≤ 10%).
     *
     * @return array{0: float, 1: float}
     */
    private function massLeft(float $total, float $logged, ?MassStatus $status): array
    {
        $low = max(0.0, $total * 0.9 - $logged);
        $high = max(0.0, $total * 1.1 - $logged);

        return match ($status) {
            MassStatus::Reduced => [max(min($low, $total * 1.1 * 0.5), $total * 0.9 * 0.1), min($high, $total * 1.1 * 0.5)],
            MassStatus::Critical => [0.0, min($high, $total * 1.1 * 0.1)],
            MassStatus::Fresh => [max($low, $total * 0.9 * 0.5), max($high, $total * 0.9 * 0.5)],
            default => [$low, $high],
        };
    }

    private function billions(float $kg): string
    {
        return $kg >= 1_000_000_000 ? sprintf('%.1fB', $kg / 1_000_000_000) : sprintf('%dM', (int) round($kg / 1_000_000));
    }
}
