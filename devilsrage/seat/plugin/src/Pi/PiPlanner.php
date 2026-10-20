<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Pi;

/**
 * Patch 50: works out what a pilot's PI needs, from SeAT's colony data.
 *
 * Plain PHP (no database, no Laravel) so it can be tested on its own. PiData turns SeAT's
 * tables into the arrays below.
 *
 * Planet:
 *   character_id, planet_id, name, type, level, system_id, last_update (unix),
 *   extractors: list of [pin_id, product, product_name, cycle_time (s)|null, qty|null,
 *                        heads, lat, lon, install (unix)|null, expiry (unix)|null]
 *   factories:  list of [pin_id, kind (basic|advanced|hightech), inputs: type ids, outputs: type ids]
 *   storage:    list of [pin_id, kind, capacity (m3), used (m3)]
 * History (earlier extractor programs, kept by our hourly job): pin_id => list of
 *   [install, product, cycle_time, qty, heads, lat, lon]
 * Types: type id => [name, tier (0 = raw, 1-4 = P1-P4)]
 */
final class PiPlanner
{
    /** A basic factory eats 3,000 raw per 30 minutes. */
    public const BASIC_INPUT_PER_HOUR = 6000;

    /** Factory output per hour by the tier it makes (P1 20 per 30 min; P2 5, P3 3, P4 1 per hour). */
    public const OUTPUT_PER_HOUR = [1 => 40, 2 => 5, 3 => 3, 4 => 1];

    /** Most heads one extractor can have. */
    public const MAX_HEADS = 10;

    /** An extractor stopping within this many hours makes its character "due". */
    public const DUE_HOURS = 24;

    /** While you're logged in for a run, also restart what stops within this many hours. */
    public const ALONG_HOURS = 48;

    /** Two raw lines more than this far apart (relative to what their factories need) get a balance step. */
    private const BALANCE_GAP = 1.15;

    /** Output per head this much below the best earlier run on the same spot = the spot is running out. */
    private const DEPLETED = 0.8;

    /** Head groups closer than this (radians) count as the same spot. */
    private const SAME_SPOT = 0.03;

    /** Storage this full gets a "haul it out" step. */
    private const FULL = 0.9;

    /**
     * @param  array<int, array{name: string, tier: int}>  $types
     * @param  array<int, list<array<string, mixed>>>  $history
     */
    public function __construct(
        private readonly array $types,
        private readonly array $history,
        private readonly int $now,
    ) {}

    /**
     * Everything about one planet: its state and the steps that would help it.
     *
     * @param  array<string, mixed>  $planet
     * @return array<string, mixed>
     */
    public function planet(array $planet): array
    {
        $extractors = $planet['extractors'] ?? [];
        $stops = [];
        $notRunning = [];
        $noNumbers = [];
        foreach ($extractors as $extractor) {
            if ($this->notRunning($extractor)) {
                $notRunning[] = $extractor;
            } else {
                $stops[] = (int) $extractor['expiry'];
                if ($this->noNumbers($extractor)) {
                    $noNumbers[] = $extractor;
                }
            }
        }
        $nextStop = $stops === [] ? null : min($stops);
        $stoppedSince = null;
        foreach ($stops as $stop) {
            if ($stop <= $this->now) {
                $stoppedSince = $stoppedSince === null ? $stop : min($stoppedSince, $stop);
            }
        }

        $status = match (true) {
            $extractors === [] => 'factory',
            $notRunning !== [] || $stoppedSince !== null => 'stopped',
            $nextStop !== null && $nextStop <= $this->now + self::DUE_HOURS * 3600 => 'soon',
            default => 'ok',
        };

        $balance = $this->balance($planet);
        $storage = $this->fullestStorage($planet);

        return [
            'planet' => $planet,
            'status' => $status,
            'next_stop' => $nextStop,
            'stopped_since' => $stoppedSince,
            'not_running' => count($notRunning),
            'no_numbers' => count($noNumbers),
            'extracted' => $this->extractedNames($extractors),
            'making' => $this->makingNames($planet),
            'factory_counts' => $this->factoryCounts($planet),
            'balance' => $balance,
            'depleted' => $this->depleted($extractors),
            'storage' => $storage,
            'program_lengths' => array_values(array_map(
                fn (array $e): int => (int) $e['expiry'] - (int) $e['install'],
                array_filter($extractors, fn (array $e): bool => ! $this->notRunning($e) && $e['install'] !== null),
            )),
        ];
    }

    /**
     * The PI run: characters (in the order given, i.e. login order) that need logging in,
     * each with the planets to visit and what to do there.
     *
     * @param  array<int, array{name: string, planets: list<array<string, mixed>>}>  $characters  character id => name + planets
     * @return array{characters: list<array<string, mixed>>, clear: list<array<string, mixed>>, minutes: int, deadline: int|null, next_stop: int|null}
     */
    public function run(array $characters): array
    {
        $due = [];
        $clear = [];
        $minutes = 0;
        $deadline = null;
        $nextStop = null;

        foreach ($characters as $characterId => $character) {
            $planets = array_map(fn (array $p): array => $this->planet($p), $character['planets']);
            if ($planets === []) {
                continue;
            }
            $isDue = false;
            $charNext = null;
            foreach ($planets as $p) {
                if (in_array($p['status'], ['stopped', 'soon'], true)) {
                    $isDue = true;
                }
                if ($p['next_stop'] !== null) {
                    $charNext = $charNext === null ? $p['next_stop'] : min($charNext, $p['next_stop']);
                }
            }
            if ($charNext !== null) {
                $nextStop = $nextStop === null ? $charNext : min($nextStop, $charNext);
            }
            if (! $isDue) {
                $clear[] = ['character_id' => $characterId, 'name' => $character['name'], 'planets' => count($planets), 'next_stop' => $charNext];

                continue;
            }

            $visits = [];
            $restarted = [];
            foreach ($planets as $p) {
                $along = $p['next_stop'] !== null && $p['next_stop'] <= $this->now + self::ALONG_HOURS * 3600;
                $wanted = in_array($p['status'], ['stopped', 'soon'], true) || $along;
                if (! $wanted) {
                    continue;
                }
                $steps = $this->steps($p);
                if ($steps === []) {
                    continue;
                }
                if ($p['planet']['extractors'] !== []) {
                    $restarted[] = $p;
                }
                $visits[] = ['planet' => $p, 'steps' => $steps];
                if ($p['next_stop'] !== null && $p['next_stop'] > $this->now) {
                    $deadline = $deadline === null ? $p['next_stop'] : min($deadline, $p['next_stop']);
                }
            }
            if ($visits === []) {
                continue;
            }

            $timer = $this->timerStep($restarted, $character['name']);
            $minutes += 1 + 2 * count($visits);
            $due[] = [
                'character_id' => $characterId,
                'name' => $character['name'],
                'visits' => $visits,
                'timer' => $timer,
                'minutes' => 1 + 2 * count($visits),
            ];
        }

        return ['characters' => $due, 'clear' => $clear, 'minutes' => $minutes, 'deadline' => $deadline, 'next_stop' => $nextStop];
    }

    /**
     * What to do on one planet while you're there, most important first.
     *
     * @param  array<string, mixed>  $p  from planet()
     * @return list<array{kind: string, title: string, why: string, time: int|null, time_word: string|null}>
     */
    public function steps(array $p): array
    {
        $steps = [];
        $extractors = $p['planet']['extractors'];
        if ($extractors !== []) {
            $count = count($extractors);
            $what = $count === 1 ? 'the '.$extractors[0]['product_name'].' extractor' : ($count === 2 ? 'both extractors' : 'all '.$count.' extractors');
            if ($p['not_running'] > 0 && $p['not_running'] === $count) {
                $steps[] = $this->step('restart', 'Start '.$what, 'No extraction program is running.');
            } elseif ($p['stopped_since'] !== null) {
                $steps[] = $this->step('restart', 'Restart '.$what, '', $p['stopped_since'], 'stopped');
            } elseif ($p['next_stop'] !== null) {
                $steps[] = $this->step('restart', 'Restart '.$what, '', $p['next_stop'], 'stops');
            }
        }

        $balance = $p['balance'];
        if ($balance !== null && $balance['move'] > 0) {
            $steps[] = $this->step(
                'balance',
                sprintf('Move %d head%s from %s to %s', $balance['move'], $balance['move'] === 1 ? '' : 's', $balance['from'], $balance['to']),
                sprintf(
                    '%s is short for its factories (%s%% of what they use) while %s makes %s%%. About even after the move. Moving heads changes the spot, so treat it as a good guess.',
                    $balance['to'],
                    $balance['lines'][$balance['to']]['percent'],
                    $balance['from'],
                    $balance['lines'][$balance['from']]['percent'],
                ),
            );
        } elseif ($balance !== null && $balance['move'] === 0 && $balance['full']) {
            $steps[] = $this->step(
                'balance',
                'Add an extractor for '.$balance['to'].', or use a shorter program',
                sprintf('%s is short for its factories (%s%% of what they use) and its extractor already has %d heads.', $balance['to'], $balance['lines'][$balance['to']]['percent'], self::MAX_HEADS),
            );
        }

        foreach ($p['depleted'] as $spot) {
            $steps[] = $this->step(
                'move',
                'Move the '.$spot['product_name'].' heads to a fresh spot',
                sprintf('Down %d%% per head compared with an earlier program on this spot, so the spot is running out.', $spot['down']),
                $spot['since'],
                'best',
            );
        }

        if ($p['no_numbers'] > 0) {
            $steps[] = $this->step('submit', 'Open the planet and press Submit once', "EVE hasn't shared this extractor's numbers yet, so we can't check its balance. They show up after one Submit.");
        }

        if ($p['storage'] !== null && $p['storage']['fill'] >= self::FULL) {
            $steps[] = $this->step('haul', 'Empty the '.$p['storage']['kind'], sprintf('It was %d%% full when the planet was last opened in game.', (int) round($p['storage']['fill'] * 100)));
        }

        return $steps;
    }

    /**
     * Raw lines feeding basic factories on one planet: what each makes against what its
     * factories use, and how many heads to move to even them out.
     *
     * @param  array<string, mixed>  $planet
     * @return array{lines: array<string, array{type: int, per_hour: int, need: int, heads: int, extractors: int, percent: int}>, from: string, to: string, move: int, full: bool}|null
     */
    public function balance(array $planet): ?array
    {
        $need = [];
        foreach ($planet['factories'] ?? [] as $factory) {
            if ($factory['kind'] !== 'basic') {
                continue;
            }
            foreach (array_unique($factory['inputs']) as $input) {
                if (($this->types[$input]['tier'] ?? null) === 0) {
                    $need[$input] = ($need[$input] ?? 0) + self::BASIC_INPUT_PER_HOUR;
                }
            }
        }

        $lines = [];
        foreach ($planet['extractors'] ?? [] as $extractor) {
            $type = (int) ($extractor['product'] ?? 0);
            if (! isset($need[$type]) || $this->notRunning($extractor) || $this->noNumbers($extractor)) {
                continue;
            }
            $name = $this->types[$type]['name'] ?? (string) $extractor['product_name'];
            $line = $lines[$name] ?? ['type' => $type, 'per_hour' => 0, 'need' => $need[$type], 'heads' => 0, 'extractors' => 0];
            $line['per_hour'] += (int) round($extractor['qty'] * 3600 / $extractor['cycle_time']);
            $line['heads'] += (int) $extractor['heads'];
            $line['extractors']++;
            $lines[$name] = $line;
        }
        if (count($lines) < 2) {
            return null;
        }
        foreach ($lines as $name => $line) {
            $lines[$name]['percent'] = (int) round(100 * $line['per_hour'] / $line['need']);
        }

        $ratio = fn (array $line): float => $line['per_hour'] / $line['need'];
        $names = array_keys($lines);
        usort($names, fn (string $a, string $b): int => $ratio($lines[$a]) <=> $ratio($lines[$b]));
        $to = $names[0];
        $from = $names[count($names) - 1];
        $low = $lines[$to];
        $high = $lines[$from];

        // Only worth it when the short line really starves its factories and the gap is real.
        if ($ratio($low) >= 1.0 || $ratio($high) < $ratio($low) * self::BALANCE_GAP || $low['heads'] < 1 || $high['heads'] < 1) {
            return null;
        }

        $perHeadLow = $low['per_hour'] / $low['heads'];
        $perHeadHigh = $high['per_hour'] / $high['heads'];
        $exact = ($ratio($high) - $ratio($low)) / ($perHeadLow / $low['need'] + $perHeadHigh / $high['need']);
        $room = self::MAX_HEADS * $low['extractors'] - $low['heads'];
        $spare = $high['heads'] - 1;
        $move = (int) max(0, min((int) round($exact), $room, $spare));

        return ['lines' => $lines, 'from' => $from, 'to' => $to, 'move' => $move, 'full' => $room <= 0];
    }

    /**
     * Extractors making clearly less per head than an earlier program on the same spot.
     *
     * @param  list<array<string, mixed>>  $extractors
     * @return list<array{pin_id: int, product_name: string, down: int, since: int}>
     */
    public function depleted(array $extractors): array
    {
        $found = [];
        foreach ($extractors as $extractor) {
            if ($this->notRunning($extractor) || $this->noNumbers($extractor) || (int) $extractor['heads'] < 1) {
                continue;
            }
            $now = $this->perHead($extractor);
            $best = null;
            foreach ($this->history[(int) $extractor['pin_id']] ?? [] as $run) {
                if ((int) $run['install'] >= (int) $extractor['install']
                    || (int) $run['product'] !== (int) $extractor['product']
                    || (int) $run['cycle_time'] !== (int) $extractor['cycle_time']
                    || empty($run['qty']) || (int) $run['heads'] < 1
                    || ! $this->sameSpot($run, $extractor)) {
                    continue;
                }
                $rate = $this->perHead($run);
                if ($best === null || $rate > $best['rate']) {
                    $best = ['rate' => $rate, 'install' => (int) $run['install']];
                }
            }
            if ($best !== null && $now < $best['rate'] * self::DEPLETED) {
                $found[] = [
                    'pin_id' => (int) $extractor['pin_id'],
                    'product_name' => (string) $extractor['product_name'],
                    'down' => (int) round(100 * (1 - $now / $best['rate'])),
                    'since' => $best['install'],
                ];
            }
        }

        return $found;
    }

    /**
     * After restarting several planets of one character: pick one program length so they
     * all stop together next time (one login per run instead of several).
     *
     * @param  list<array<string, mixed>>  $restarted  from planet()
     * @return array{kind: string, title: string, why: string, time: null, time_word: null}|null
     */
    private function timerStep(array $restarted, string $name): ?array
    {
        $lengths = [];
        foreach ($restarted as $p) {
            foreach ($p['program_lengths'] as $length) {
                $lengths[] = $length;
            }
        }
        if (count($restarted) < 2 || $lengths === [] || max($lengths) - min($lengths) < 6 * 3600) {
            return null;
        }

        return $this->step(
            'timer',
            'Give every extractor the same program length',
            sprintf('Right now they run from %s to %s. With one length they all stop together, so %s needs one login per run.', $this->duration(min($lengths)), $this->duration(max($lengths)), $name),
        );
    }

    /**
     * @param  array<string, mixed>  $planet
     * @return array{kind: string, fill: float}|null
     */
    private function fullestStorage(array $planet): ?array
    {
        $fullest = null;
        foreach ($planet['storage'] ?? [] as $store) {
            if ((float) $store['capacity'] <= 0) {
                continue;
            }
            $fill = (float) $store['used'] / (float) $store['capacity'];
            if ($fullest === null || $fill > $fullest['fill']) {
                $fullest = ['kind' => (string) $store['kind'], 'fill' => $fill];
            }
        }

        return $fullest;
    }

    /**
     * @param  list<array<string, mixed>>  $extractors
     * @return list<string>
     */
    private function extractedNames(array $extractors): array
    {
        $names = [];
        foreach ($extractors as $extractor) {
            $names[] = $this->types[(int) ($extractor['product'] ?? 0)]['name'] ?? (string) $extractor['product_name'];
        }

        return array_values(array_unique(array_filter($names)));
    }

    /**
     * What the planet's factories make at the highest tier present.
     *
     * @param  array<string, mixed>  $planet
     * @return array{tier: int, names: list<string>, per_hour: array<string, int>}|null
     */
    public function makingNames(array $planet): ?array
    {
        $byTier = [];
        foreach ($planet['factories'] ?? [] as $factory) {
            foreach (array_unique($factory['outputs']) as $output) {
                $tier = $this->types[$output]['tier'] ?? null;
                if ($tier === null || $tier < 1) {
                    continue;
                }
                $name = $this->types[$output]['name'];
                $byTier[$tier][$name] = ($byTier[$tier][$name] ?? 0) + self::OUTPUT_PER_HOUR[$tier];
            }
        }
        if ($byTier === []) {
            return null;
        }
        $top = max(array_keys($byTier));

        return ['tier' => $top, 'names' => array_keys($byTier[$top]), 'per_hour' => $byTier[$top]];
    }

    /**
     * @param  array<string, mixed>  $planet
     * @return array{basic: int, advanced: int, hightech: int}
     */
    private function factoryCounts(array $planet): array
    {
        $counts = ['basic' => 0, 'advanced' => 0, 'hightech' => 0];
        foreach ($planet['factories'] ?? [] as $factory) {
            $counts[$factory['kind']] = ($counts[$factory['kind']] ?? 0) + 1;
        }

        return $counts;
    }

    /** @param  array<string, mixed>  $extractor */
    private function notRunning(array $extractor): bool
    {
        return (int) ($extractor['heads'] ?? 0) < 1 || ($extractor['expiry'] ?? null) === null;
    }

    /** @param  array<string, mixed>  $extractor */
    private function noNumbers(array $extractor): bool
    {
        return empty($extractor['qty']) || empty($extractor['cycle_time']);
    }

    /** @param  array<string, mixed>  $extractor */
    private function perHead(array $extractor): float
    {
        return (float) $extractor['qty'] * 3600 / (float) $extractor['cycle_time'] / max(1, (int) $extractor['heads']);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function sameSpot(array $a, array $b): bool
    {
        return abs((float) $a['lat'] - (float) $b['lat']) < self::SAME_SPOT
            && abs((float) $a['lon'] - (float) $b['lon']) < self::SAME_SPOT;
    }

    private function duration(int $seconds): string
    {
        $hours = (int) round($seconds / 3600);
        if ($hours < 48) {
            return $hours.' h';
        }
        $days = intdiv($hours, 24);
        $rest = $hours % 24;

        return $days.' d'.($rest > 0 ? ' '.$rest.' h' : '');
    }

    /**
     * @return array{kind: string, title: string, why: string, time: int|null, time_word: string|null}
     */
    private function step(string $kind, string $title, string $why, ?int $time = null, ?string $timeWord = null): array
    {
        return ['kind' => $kind, 'title' => $title, 'why' => $why, 'time' => $time, 'time_word' => $timeWord];
    }
}
