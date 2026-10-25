<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Corporation;
use App\Models\Killmail;
use App\Models\Type;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Patch 55: who lives in a system, worked out from the kills the mapper keeps (wormhole kills,
 * 90+ days): the corps seen most (on how many different days), the hours they are active (EVE
 * time), how big their fleets are and what they fly.
 */
final class SystemAssessment
{
    public const int DAYS = 90;

    /**
     * @return array{kills: int, since: string, hours: list<int>, peak: array{from: int, to: int}|null, fleet: array{median: int, max: int}|null, corporations: list<array{name: string, kills: int, losses: int, days: int}>, ships: list<array{name: string, count: int}>}
     */
    public static function for(int $solarsystemId): array
    {
        $since = now()->subDays(self::DAYS);
        /** @var Collection<int, Killmail> $kills */
        $kills = Killmail::query()
            ->where('solarsystem_id', $solarsystemId)
            ->where('time', '>=', $since)
            ->orderByDesc('time')
            ->limit(2000)
            ->get(['id', 'time', 'data']);

        $hours = array_fill(0, 24, 0);
        $fleetSizes = [];
        $corps = [];
        $ships = [];
        foreach ($kills as $kill) {
            $time = $kill->time instanceof DateTimeInterface ? $kill->time : new DateTimeImmutable((string) $kill->time);
            $hours[(int) $time->format('G')]++;
            $day = $time->format('Y-m-d');
            $data = json_decode((string) json_encode($kill->data), true) ?: [];
            $attackers = (array) ($data['attackers'] ?? []);
            $fleetSizes[] = count($attackers);
            $victimCorp = $data['victim']['corporation_id'] ?? null;
            if (is_int($victimCorp)) {
                $corps[$victimCorp]['losses'] = ($corps[$victimCorp]['losses'] ?? 0) + 1;
                $corps[$victimCorp]['days'][$day] = true;
            }
            $seen = [];
            foreach ($attackers as $attacker) {
                $corp = $attacker['corporation_id'] ?? null;
                if (is_int($corp) && ! isset($seen[$corp])) {
                    $seen[$corp] = true;
                    $corps[$corp]['kills'] = ($corps[$corp]['kills'] ?? 0) + 1;
                    $corps[$corp]['days'][$day] = true;
                }
                $ship = $attacker['ship_type_id'] ?? null;
                if (is_int($ship)) {
                    $ships[$ship] = ($ships[$ship] ?? 0) + 1;
                }
            }
        }

        uasort($corps, fn (array $a, array $b): int => [count($b['days'] ?? []), ($b['kills'] ?? 0) + ($b['losses'] ?? 0)] <=> [count($a['days'] ?? []), ($a['kills'] ?? 0) + ($a['losses'] ?? 0)]);
        $topCorps = array_slice($corps, 0, 6, true);
        $corpNames = Corporation::query()->whereKey(array_keys($topCorps))->pluck('name', 'id');
        arsort($ships);
        $topShips = array_slice($ships, 0, 8, true);
        $shipNames = Type::query()->whereKey(array_keys($topShips))->pluck('name', 'id');
        sort($fleetSizes);

        return [
            'kills' => $kills->count(),
            'since' => $since->toDateString(),
            'hours' => $hours,
            'peak' => self::peak($hours),
            'fleet' => $fleetSizes === [] ? null : ['median' => $fleetSizes[intdiv(count($fleetSizes), 2)], 'max' => end($fleetSizes)],
            'corporations' => array_values(array_map(
                fn (int $id, array $corp): array => ['name' => (string) ($corpNames[$id] ?? "Corp $id"), 'kills' => $corp['kills'] ?? 0, 'losses' => $corp['losses'] ?? 0, 'days' => count($corp['days'] ?? [])],
                array_keys($topCorps),
                $topCorps,
            )),
            'ships' => array_values(array_map(
                fn (int $id, int $count): array => ['name' => (string) ($shipNames[$id] ?? "Type $id"), 'count' => $count],
                array_keys($topShips),
                $topShips,
            )),
        ];
    }

    /**
     * The busiest three hours in a row (EVE time, wrapping past midnight), or null with no kills.
     *
     * @param  list<int>  $hours
     * @return array{from: int, to: int}|null
     */
    public static function peak(array $hours): ?array
    {
        if (array_sum($hours) === 0) {
            return null;
        }
        $best = 0;
        $bestSum = -1;
        for ($start = 0; $start < 24; $start++) {
            $sum = $hours[$start] + $hours[($start + 1) % 24] + $hours[($start + 2) % 24];
            if ($sum > $bestSum) {
                $bestSum = $sum;
                $best = $start;
            }
        }

        return ['from' => $best, 'to' => ($best + 3) % 24];
    }
}
