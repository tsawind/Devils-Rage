<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Pi;

/**
 * Patch 63: routes. Two checks on a colony (live, or a template):
 *
 * - Stuck: a storage facility or launchpad holds something a factory here needs, but no route
 *   takes it out. It fills up (12,000 / 12,000 m³) and the extractor feeding it wastes its
 *   output while the factories run short.
 * - Any-input (Tsa's rule): every storage facility and launchpad routes every input the
 *   factories use to every factory using it, so it works whichever storage an extractor (or an
 *   import) is pointed at. Routes cost no CPU or power, so there's no reason to leave one out.
 */
final class PiRoutes
{
    public const HOLDERS = ['storage facility', 'launchpad'];

    public const FACTORIES = ['basic', 'advanced', 'hightech'];

    /**
     * @param  array<int, string>  $kinds  pin id => kind (PiTemplate::pinKind)
     * @param  list<array{from: int, to: int, type: int}>  $routes
     * @param  array<int, array<int, int>>  $contents  pin id => type id => amount
     * @param  list<int>  $extracted  raw this colony's extractors pull (stuck even if no factory route asks for it yet)
     * @return array{stuck: list<array{pin: int, kind: string, type: int, amount: int}>, missing: list<array{from: int, to: int, type: int}>, wanted: int}
     */
    public static function check(array $kinds, array $routes, array $contents = [], array $extracted = []): array
    {
        $needs = [];
        $out = [];
        foreach ($routes as $route) {
            if (in_array($kinds[$route['to']] ?? '', self::FACTORIES, true)) {
                $needs[$route['type']][$route['to']] = true;
            }
            $out[$route['from']][$route['type']][$route['to']] = true;
        }
        $holders = array_keys(array_filter($kinds, fn (string $kind): bool => in_array($kind, self::HOLDERS, true)));
        sort($holders);

        $stuck = [];
        foreach ($holders as $pin) {
            foreach ($contents[$pin] ?? [] as $type => $amount) {
                if ($amount > 0 && (isset($needs[$type]) || in_array((int) $type, $extracted, true)) && ! isset($out[$pin][$type])) {
                    $stuck[] = ['pin' => $pin, 'kind' => $kinds[$pin], 'type' => (int) $type, 'amount' => (int) $amount];
                }
            }
        }

        $missing = [];
        $wanted = 0;
        ksort($needs);
        foreach ($needs as $type => $factories) {
            foreach ($holders as $pin) {
                foreach (array_keys($factories) as $factory) {
                    $wanted++;
                    if (! isset($out[$pin][$type][$factory])) {
                        $missing[] = ['from' => $pin, 'to' => (int) $factory, 'type' => (int) $type];
                    }
                }
            }
        }

        return ['stuck' => $stuck, 'missing' => $missing, 'wanted' => $wanted];
    }
}
