<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Pi;

/**
 * Patch 59: EVE's PI colony templates (the JSON EVE copies to the clipboard), checked and
 * described so pilots can keep and trade them on the PI screen.
 *
 * Plain PHP (no database, no Laravel) so it can be tested on its own.
 *
 * EVE's format: CmdCtrLv (command center level), Cmt (comment), Diam (planet diameter),
 * Pln (planet type id), P (pins: T type id, S product type id, H heads, La/Lo position),
 * L (links: S/D 1-based pin numbers, Lv level), R (routes: P 1-based pin path, T type id, Q amount).
 */
final class PiTemplate
{
    /** Bigger than any real template (a full CC 5 colony is about 4 KB). */
    public const MAX_BYTES = 100_000;

    private const MAX_PINS = 200;

    /**
     * Checks pasted text is an EVE PI template.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException with a message for the pilot
     */
    public static function parse(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('Paste a template first.');
        }
        if (strlen($text) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('That is far too long for a PI template.');
        }
        try {
            $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException("That isn't a PI template. In EVE, open the colony, then Templates and copy it to the clipboard.");
        }
        if (! is_array($data) || ! isset($data['P']) || ! is_array($data['P']) || ! array_is_list($data['P'])) {
            throw new \InvalidArgumentException("That isn't a PI template (no installations in it).");
        }
        $pins = $data['P'];
        if ($pins === [] || count($pins) > self::MAX_PINS) {
            throw new \InvalidArgumentException('The template has no installations.');
        }
        foreach ($pins as $pin) {
            if (! is_array($pin) || ! is_int($pin['T'] ?? null) || ! is_numeric($pin['La'] ?? null) || ! is_numeric($pin['Lo'] ?? null)) {
                throw new \InvalidArgumentException('An installation in the template is damaged.');
            }
            if (isset($pin['S']) && ! is_int($pin['S'])) {
                throw new \InvalidArgumentException('An installation in the template is damaged.');
            }
            if (isset($pin['H']) && (! is_int($pin['H']) || $pin['H'] < 0 || $pin['H'] > PiPlanner::MAX_HEADS)) {
                throw new \InvalidArgumentException('An extractor in the template has a strange number of heads.');
            }
        }
        $count = count($pins);
        $inRange = fn ($n): bool => is_int($n) && $n >= 1 && $n <= $count;
        foreach (self::listOf($data, 'L') as $link) {
            if (! is_array($link) || ! $inRange($link['S'] ?? null) || ! $inRange($link['D'] ?? null)) {
                throw new \InvalidArgumentException('A link in the template points at an installation that is not there.');
            }
        }
        foreach (self::listOf($data, 'R') as $route) {
            if (! is_array($route) || ! is_array($route['P'] ?? null) || count($route['P']) < 2 || ! is_int($route['T'] ?? null)) {
                throw new \InvalidArgumentException('A route in the template is damaged.');
            }
            foreach ($route['P'] as $step) {
                if (! $inRange($step)) {
                    throw new \InvalidArgumentException('A route in the template points at an installation that is not there.');
                }
            }
        }
        $level = $data['CmdCtrLv'] ?? null;
        if (! is_int($level) || $level < 0 || $level > 5) {
            throw new \InvalidArgumentException('The template has no command center level.');
        }
        if (! is_int($data['Pln'] ?? null)) {
            throw new \InvalidArgumentException('The template does not say which planet type it is for.');
        }

        return $data;
    }

    /**
     * The same template whatever the spacing it was pasted with (to spot duplicates).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fingerprint(array $data): string
    {
        return sha1(json_encode($data, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Every type id the summary needs a name for.
     *
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    public static function typeIds(array $data): array
    {
        $ids = [(int) $data['Pln']];
        foreach ($data['P'] as $pin) {
            $ids[] = (int) $pin['T'];
            if (isset($pin['S'])) {
                $ids[] = (int) $pin['S'];
            }
        }
        foreach (self::listOf($data, 'R') as $route) {
            $ids[] = (int) $route['T'];
        }

        return array_values(array_unique($ids));
    }

    /**
     * What the template builds, in words.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array{name: string, tier: int}>  $types
     * @return array{planet: string, level: int, routes: array{wanted: int, missing: int}, comment: string, extractors: list<array{name: string, heads: int}>, factories: array{basic: int, advanced: int, hightech: int}, storage: array{launchpad: int, storage: int}, raw: list<string>, makes: list<string>, tier: int|null, links: int, title: string}
     */
    public static function summary(array $data, array $types): array
    {
        $planetName = $types[(int) $data['Pln']]['name'] ?? '';
        $planet = preg_match('/\(([^)]+)\)/', $planetName, $m) === 1 ? $m[1] : ($planetName !== '' ? $planetName : 'Unknown planet');

        $extractors = [];
        $factories = ['basic' => 0, 'advanced' => 0, 'hightech' => 0];
        $storage = ['launchpad' => 0, 'storage' => 0];
        $raw = [];
        $made = [];
        foreach ($data['P'] as $pin) {
            $kind = self::pinKind($types[(int) $pin['T']]['name'] ?? '');
            $product = isset($pin['S']) ? (int) $pin['S'] : null;
            $productName = $product !== null ? ($types[$product]['name'] ?? null) : null;
            if ($kind === 'extractor') {
                $extractors[] = ['name' => $productName ?? 'Nothing set', 'heads' => (int) ($pin['H'] ?? 0)];
                if ($productName !== null) {
                    $raw[$productName] = true;
                }
            } elseif (isset($factories[$kind])) {
                $factories[$kind]++;
                if ($productName !== null) {
                    $made[$productName] = $types[$product]['tier'] ?? 0;
                }
            } elseif ($kind === 'launchpad') {
                $storage['launchpad']++;
            } elseif ($kind === 'storage facility') {
                $storage['storage']++;
            }
        }
        if ($raw === []) {
            foreach (self::listOf($data, 'R') as $route) {
                $type = $types[(int) $route['T']] ?? null;
                if ($type !== null && $type['tier'] === 0) {
                    $raw[$type['name']] = true;
                }
            }
        }
        $tier = $made === [] ? null : max($made);
        $makes = $tier === null ? [] : array_keys(array_filter($made, fn (int $t): bool => $t === $tier));
        sort($makes);
        $rawNames = array_keys($raw);
        sort($rawNames);

        $what = $makes !== [] ? implode(', ', $makes).' (P'.$tier.')' : ($rawNames !== [] ? implode(' + ', $rawNames) : 'Empty colony');

        // Patch 63: does it follow the any-input rule (every storage routes every input to every factory using it)?
        $kinds = [];
        foreach ($data['P'] as $index => $pin) {
            $kinds[$index + 1] = self::pinKind($types[(int) $pin['T']]['name'] ?? '');
        }
        $routes = [];
        foreach (self::listOf($data, 'R') as $route) {
            if (is_array($route) && is_array($route['P'] ?? null) && count($route['P']) >= 2 && is_int($route['T'] ?? null)) {
                $routes[] = ['from' => (int) $route['P'][0], 'to' => (int) $route['P'][count($route['P']) - 1], 'type' => (int) $route['T']];
            }
        }
        $check = PiRoutes::check($kinds, $routes);

        return [
            'planet' => $planet,
            'level' => (int) $data['CmdCtrLv'],
            'routes' => ['wanted' => $check['wanted'], 'missing' => count($check['missing'])],
            'comment' => is_string($data['Cmt'] ?? null) ? $data['Cmt'] : '',
            'extractors' => $extractors,
            'factories' => $factories,
            'storage' => $storage,
            'raw' => $rawNames,
            'makes' => $makes,
            'tier' => $tier,
            'links' => count(self::listOf($data, 'L')),
            'title' => $planet.' · '.$what,
        ];
    }

    /** extractor, basic, advanced, hightech, launchpad, storage facility, command center or other, from a pin's type name. */
    public static function pinKind(string $typeName): string
    {
        $name = strtolower($typeName);

        return match (true) {
            str_contains($name, 'extractor control unit') => 'extractor',
            str_contains($name, 'basic industry facility') => 'basic',
            str_contains($name, 'advanced industry facility') => 'advanced',
            str_contains($name, 'high-tech production plant') => 'hightech',
            str_contains($name, 'launchpad') || str_contains($name, 'launch pad') => 'launchpad',
            str_contains($name, 'storage facility') => 'storage facility',
            str_contains($name, 'command center') => 'command center',
            default => 'other',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<mixed>
     */
    private static function listOf(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }
}
