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
 *   storage:    list of [pin_id, kind, capacity (m3), used (m3), contents?: list of [type_id, name, tier, amount],
 *                        previous?: [at (unix), amounts: type id => amount] (what it held the time before)]
 *   customs:    [used (m3), capacity (m3)]|null (Patch 59: PI goods in this planet's customs office)
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

    /**
     * Patch 59: what a customs office taxes per unit, whatever the market price (ISK). Exporting
     * pays rate x this; importing pays half. P2 7,200 is confirmed; the rest is the usual table.
     */
    public const TAX_BASE = [0 => 5, 1 => 400, 2 => 7200, 3 => 60000, 4 => 1200000];

    /**
     * Patch 59: a factory-only planet (everything imported) only counts as producing when it was
     * opened in game this recently (a working import planet gets visited about weekly).
     */
    public const FACTORY_ACTIVE_DAYS = 8;

    /**
     * Patch 61: CCP's extraction formula (developers.eveonline.com/docs/guides/pi). EVE's
     * qty_per_cycle is a base value, not what a cycle makes: each cycle makes
     * barWidth x qty / (1 + t x 0.012) x (1 + 0.8 x wave), barWidth = cycle seconds / 900,
     * t = (cycle + 0.5) x barWidth. Checked against in-game program totals (within ~2%).
     */
    public const DECAY_FACTOR = 0.012;

    public const NOISE_FACTOR = 0.8;

    /** Patch 60: the corp's usual program (6 d 20 h), when nothing else says how long programs run. */
    public const STANDARD_PROGRAM_HOURS = 164;

    /**
     * Patch 60: a character that would sit stopped longer than this before the next reset gets
     * "restart it now with this run"; shorter, "leave it until the next reset".
     */
    public const ALIGN_HOURS = 24;

    /** Most heads one extractor can have. */
    public const MAX_HEADS = 10;

    /** An extractor stopping within this many hours makes its character "due". */
    public const DUE_HOURS = 24;

    /** While you're logged in for a run, also restart what stops within this many hours. */
    public const ALONG_HOURS = 48;

    /** Two raw lines more than this far apart (relative to what their factories need) get a balance step. */
    private const BALANCE_GAP = 1.15;

    /**
     * Output per head this much below the best earlier run on the same spot = the spot is running out.
     * Patch 59: 10%, because losing one head of 12 for a fresh spot already pays from about 8%.
     */
    private const DEPLETED = 0.9;

    /** Patch 59: a raw buffer this full (or full before the next stop) gets a "piling up" step. */
    private const PILING = 0.75;

    /** Patch 59: readings closer together than this (seconds) are too close to tell a trend. */
    private const TREND_MIN_GAP = 3600;

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
        $lines = $this->rawLines($planet);
        $supply = 1.0;
        foreach ($lines as $line) {
            $supply = min($supply, $line['per_hour'] / $line['need']);
        }

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
            'stores' => $this->stores($planet),
            'buffers' => $this->buffers($planet),
            'launchpad' => $this->launchpad($planet),
            'customs' => $planet['customs'] ?? null,
            'heads' => array_sum(array_map(fn (array $e): int => (int) $e['heads'], $extractors)),
            'supply' => $lines === [] ? null : $supply,
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
                $clear[] = ['character_id' => $characterId, 'name' => $character['name'], 'planets' => count($planets), 'next_stop' => $charNext, 'infos' => $planets, 'align' => null];

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

        [$due, $clear, $minutes] = $this->lineUp($due, $clear, $minutes);
        foreach ($clear as &$entry) {
            unset($entry['infos']);
        }
        unset($entry);

        return ['characters' => $due, 'clear' => $clear, 'minutes' => $minutes, 'deadline' => $deadline, 'next_stop' => $nextStop, 'next_reset' => $due === [] ? null : $this->nextReset($due)];
    }

    /**
     * Patch 60: line the timers up without odd program lengths. Everyone in this run restarts now,
     * so their next reset is one program from now. A character that isn't due but would stop well
     * before that joins this run ("restart it now too"); one that stops a little before it is left
     * until the next reset. Program lengths stay as they are.
     *
     * @param  list<array<string, mixed>>  $due
     * @param  list<array<string, mixed>>  $clear
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: int}
     */
    private function lineUp(array $due, array $clear, int $minutes): array
    {
        if ($due === []) {
            return [$due, $clear, $minutes];
        }
        $reset = $this->nextReset($due);
        $keep = [];
        foreach ($clear as $entry) {
            $stop = $entry['next_stop'];
            $gap = $stop === null ? 0.0 : ($reset - $stop) / 3600;
            if ($stop === null || $gap <= 4) {
                $keep[] = $entry;

                continue;
            }
            if ($gap <= self::ALIGN_HOURS) {
                $entry['align'] = ['kind' => 'later', 'hours' => (int) round($gap)];
                $keep[] = $entry;

                continue;
            }
            $visits = [];
            foreach ($entry['infos'] as $p) {
                if ($p['planet']['extractors'] === []) {
                    continue;
                }
                $visits[] = ['planet' => $p, 'steps' => [$this->step(
                    'align',
                    'Restart now with the others',
                    sprintf('That is about %s before your next reset, so it would need a login of its own (or sit stopped). Restarting it now puts it on the same timer as the others.', $this->duration((int) round($gap * 3600))),
                    $p['next_stop'],
                    'stops',
                )]];
            }
            if ($visits === []) {
                $keep[] = $entry;

                continue;
            }
            $minutes += 1 + 2 * count($visits);
            $due[] = [
                'character_id' => $entry['character_id'],
                'name' => $entry['name'],
                'visits' => $visits,
                'timer' => null,
                'minutes' => 1 + 2 * count($visits),
                'align' => true,
            ];
        }

        return [$due, $keep, $minutes];
    }

    /**
     * When the characters in this run stop next if restarted now: now plus their usual program
     * length (the most common one among them, or 6 d 20 h).
     *
     * @param  list<array<string, mixed>>  $due
     */
    private function nextReset(array $due): int
    {
        $lengths = [];
        foreach ($due as $character) {
            foreach ($character['visits'] as $visit) {
                foreach ($visit['planet']['program_lengths'] as $length) {
                    $hours = (int) round($length / 3600);
                    $lengths[$hours] = ($lengths[$hours] ?? 0) + 1;
                }
            }
        }
        arsort($lengths);
        $hours = $lengths === [] ? self::STANDARD_PROGRAM_HOURS : (int) array_key_first($lengths);

        return $this->now + max(1, $hours) * 3600;
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
        $buffers = $p['buffers'] ?? [];
        $told = [];
        if ($balance !== null && $balance['move'] > 0) {
            $steps[] = $this->step(
                'balance',
                sprintf('Move %d head%s from %s to %s', $balance['move'], $balance['move'] === 1 ? '' : 's', $balance['from'], $balance['to']),
                sprintf(
                    '%s is short for its factories (%s%% of the %s / h they use) while %s makes %s%%. About even after the move. Moving heads changes the spot, so treat it as a good guess.%s',
                    $balance['to'],
                    $balance['lines'][$balance['to']]['percent'],
                    number_format($balance['lines'][$balance['to']]['need']),
                    $balance['from'],
                    $balance['lines'][$balance['from']]['percent'],
                    $this->bufferNote($buffers, $balance['to'], $balance['from']),
                ),
            );
            $told = [$balance['to'] => true, $balance['from'] => true];
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
                sprintf('Down %d%% per head compared with an earlier program on this spot, so the spot is running out. %s', $spot['down'], $this->freshSpotAdvice($spot['down'], (int) ($p['heads'] ?? 0))),
                $spot['since'],
                'best',
            );
        }

        if ($p['no_numbers'] > 0) {
            $steps[] = $this->step('submit', 'Open the planet and press Submit once', "EVE hasn't shared this extractor's numbers yet, so we can't check its balance. They show up after one Submit.");
        }

        $hoursLeft = $p['next_stop'] !== null ? max(0.0, ($p['next_stop'] - $this->now) / 3600) : 0.0;
        foreach ($buffers as $name => $buffer) {
            if (isset($told[$name])) {
                continue;
            }
            $perHour = $buffer['per_hour'];
            $fullIn = $perHour !== null && $perHour > 0 ? $buffer['room'] / $perHour : null;
            $emptyIn = $perHour !== null && $perHour < 0 ? $buffer['amount'] / -$perHour : null;
            if ($buffer['fill'] >= self::FULL || ($buffer['fill'] >= self::PILING && $perHour !== null && $perHour > 0) || ($fullIn !== null && $fullIn < $hoursLeft)) {
                $steps[] = $this->step(
                    'pile',
                    $name.' is piling up in storage',
                    sprintf(
                        'The storage was %d%% full%s. Once it is full the extractor\'s output is thrown away: move a head from %s to the other line, or take some out.',
                        (int) round($buffer['fill'] * 100),
                        $perHour !== null ? sprintf(', up %s / h since the time before', number_format($perHour)) : '',
                        $name,
                    ),
                );
            } elseif ($emptyIn !== null && $emptyIn < max($hoursLeft, 24.0)) {
                $steps[] = $this->step(
                    'drain',
                    $name.' storage is running low',
                    sprintf('Down %s / h since the time before (%s left), so it runs dry in about %s and its factories wait. Add a head to %s or move them to a fresh spot.', number_format(-$perHour), number_format($buffer['amount']), $this->duration((int) round($emptyIn * 3600)), $name),
                );
            }
        }

        $pad = $p['launchpad'] ?? null;
        if ($pad !== null && $pad['fill'] >= self::FULL) {
            $customs = $p['customs'] ?? null;
            $free = $customs !== null ? (float) $customs['capacity'] - (float) $customs['used'] : null;
            if ($free !== null && $free >= $pad['used']) {
                $steps[] = $this->step('launch', 'Launch the launchpad to the customs office', sprintf('It was %d%% full (%s m³) and the customs office has %s m³ free, so just undock and launch: no hauler needed.', (int) round($pad['fill'] * 100), number_format($pad['used']), number_format($free)));
            } else {
                $steps[] = $this->step('haul', 'Empty the launchpad', sprintf('It was %d%% full (%s m³) when the planet was last opened in game.%s', (int) round($pad['fill'] * 100), number_format($pad['used']), $free !== null ? sprintf(' The customs office only has %s m³ free, so bring a hauler.', number_format(max(0.0, $free))) : ''));
            }
        } elseif ($pad === null && $p['storage'] !== null && $p['storage']['kind'] === 'command center' && $p['storage']['fill'] >= self::FULL) {
            $steps[] = $this->step('haul', 'Empty the command center', sprintf('It was %d%% full when the planet was last opened in game.', (int) round($p['storage']['fill'] * 100)));
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
        $lines = $this->rawLines($planet);
        if (count($lines) < 2) {
            return null;
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
     * Raw lines feeding basic factories (found from the routes, so a storage facility in
     * between doesn't matter): what each extractor line makes per hour against what its basic
     * factories use (6,000 / h each, so 12,000 / h for two).
     *
     * @param  array<string, mixed>  $planet
     * @return array<string, array{type: int, per_hour: int, need: int, heads: int, extractors: int, percent: int}>
     */
    public function rawLines(array $planet): array
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
            $line = $lines[$name] ?? ['type' => $type, 'per_hour' => 0, 'need' => $need[$type], 'heads' => 0, 'extractors' => 0, 'percent' => 0];
            $line['per_hour'] += $this->perHour($extractor);
            $line['heads'] += (int) $extractor['heads'];
            $line['extractors']++;
            $lines[$name] = $line;
        }
        foreach ($lines as $name => $line) {
            $lines[$name]['percent'] = (int) round(100 * $line['per_hour'] / $line['need']);
        }

        return $lines;
    }

    /**
     * Patch 59: raw material waiting in storage facilities (the buffer between extractor and
     * factories), and how it changed since the colony was opened the time before.
     *
     * @param  array<string, mixed>  $planet
     * @return array<string, array{type: int, amount: int, fill: float, room: int, per_hour: int|null, since: int|null}>  by raw name
     */
    public function buffers(array $planet): array
    {
        $buffers = [];
        $lastUpdate = $planet['last_update'] ?? null;
        foreach ($planet['storage'] ?? [] as $store) {
            if (($store['kind'] ?? '') !== 'storage facility' || (float) $store['capacity'] <= 0) {
                continue;
            }
            $previous = $store['previous'] ?? null;
            $gap = $previous !== null && $lastUpdate !== null ? $lastUpdate - (int) $previous['at'] : 0;
            $raws = array_values(array_filter($store['contents'] ?? [], fn (array $c): bool => ($c['tier'] ?? -1) === 0));
            $known = array_flip(array_column($raws, 'type_id'));
            foreach ($previous['amounts'] ?? [] as $typeId => $amount) {
                if (! isset($known[$typeId]) && ($this->types[$typeId]['tier'] ?? null) === 0) {
                    $raws[] = ['type_id' => $typeId, 'name' => $this->types[$typeId]['name'], 'tier' => 0, 'amount' => 0];
                }
            }
            foreach ($raws as $raw) {
                $name = (string) $raw['name'];
                $was = $previous['amounts'][$raw['type_id']] ?? ($previous !== null ? 0 : null);
                $buffers[$name] = [
                    'type' => (int) $raw['type_id'],
                    'amount' => (int) $raw['amount'] + ($buffers[$name]['amount'] ?? 0),
                    'fill' => (float) $store['used'] / (float) $store['capacity'],
                    'room' => (int) floor(max(0.0, (float) $store['capacity'] - (float) $store['used']) / (float) ($this->types[(int) $raw['type_id']]['volume'] ?? 0.005)),
                    'per_hour' => $was !== null && $gap >= self::TREND_MIN_GAP ? (int) round(((int) $raw['amount'] - $was) * 3600 / $gap) : null,
                    'since' => $was !== null && $gap >= self::TREND_MIN_GAP ? (int) $previous['at'] : null,
                ];
            }
        }

        return $buffers;
    }

    /**
     * Patch 59: the value of what a planet makes per day: its top product (or raw, for an
     * extraction-only planet), held back by the shortest raw line, after exporting it through
     * the customs office at $taxRate (0.05 = 5%). Prices are per unit.
     *
     * @param  array<string, mixed>  $p  from planet()
     * @param  array<int, array{buy: float, sell: float}>  $prices
     * @return array{items: list<array{type: int, name: string, tier: int, per_day: int}>, buy: float, split: float, sell: float, tax: float, m3: float}
     */
    public function value(array $p, array $prices, float $taxRate = 0.0): array
    {
        $items = [];
        foreach ($this->products($p) as $item) {
            $items[] = $item;
        }
        $buy = 0.0;
        $sell = 0.0;
        $m3 = 0.0;
        $tax = 0.0;
        foreach ($items as $item) {
            $buy += $item['per_day'] * (float) ($prices[$item['type']]['buy'] ?? 0);
            $sell += $item['per_day'] * (float) ($prices[$item['type']]['sell'] ?? 0);
            $m3 += $item['per_day'] * (float) ($this->types[$item['type']]['volume'] ?? 0);
            $tax += self::exportTax($item['tier'], $item['per_day'], $taxRate);
        }

        return ['items' => $items, 'buy' => $buy - $tax, 'split' => ($buy + $sell) / 2 - $tax, 'sell' => $sell - $tax, 'tax' => $tax, 'm3' => $m3];
    }

    /** Patch 59: customs tax to export these units (importing costs half of this). */
    public static function exportTax(int $tier, int $units, float $taxRate): float
    {
        return $units * (self::TAX_BASE[$tier] ?? 0) * max(0.0, $taxRate);
    }

    /**
     * What a planet makes per day, for value().
     *
     * @param  array<string, mixed>  $p  from planet()
     * @return list<array{type: int, name: string, tier: int, per_day: int}>
     */
    public function products(array $p): array
    {
        $planet = $p['planet'];
        $out = [];
        $making = $p['making'];
        $lastUpdate = $planet['last_update'] ?? null;
        if (($planet['extractors'] ?? []) === [] && ($lastUpdate === null || $lastUpdate < $this->now - self::FACTORY_ACTIVE_DAYS * 86400)) {
            return [];
        }
        if ($making !== null) {
            $factor = $p['supply'] === null ? 1.0 : min(1.0, (float) $p['supply']);
            foreach ($making['per_hour'] as $name => $perHour) {
                $out[] = ['type' => (int) ($making['type_ids'][$name] ?? 0), 'name' => $name, 'tier' => (int) $making['tier'], 'per_day' => (int) round($perHour * 24 * $factor)];
            }

            return $out;
        }
        foreach ($planet['extractors'] ?? [] as $extractor) {
            if ($this->notRunning($extractor) || $this->noNumbers($extractor) || (int) $extractor['expiry'] <= $this->now) {
                continue;
            }
            $out[] = ['type' => (int) $extractor['product'], 'name' => (string) $extractor['product_name'], 'tier' => 0, 'per_day' => $this->perHour($extractor) * 24];
        }

        return $out;
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
     * Patch 59: which customs office belongs to which planet. EVE doesn't say, so each office
     * goes to the planet whose products / raw materials it holds the most kinds of (offices that
     * match two planets equally, or none, stay unplaced).
     *
     * @param  list<array<string, mixed>>  $planets  one character's planets (PiData's shape)
     * @param  list<array{location_id: int, used: float, contents: list<array{type_id: int}>}>  $offices
     * @return array{placed: array<int, array{used: float, capacity: int, contents: list<array<string, mixed>>}>, unplaced: list<array<string, mixed>>}  placed by planet id
     */
    public function placeCustoms(array $planets, array $offices, int $capacity): array
    {
        $kinds = [];
        foreach ($planets as $planet) {
            $types = [];
            foreach ($planet['extractors'] ?? [] as $extractor) {
                $types[(int) $extractor['product']] = true;
            }
            foreach ($planet['factories'] ?? [] as $factory) {
                foreach (array_merge($factory['inputs'], $factory['outputs']) as $typeId) {
                    $types[(int) $typeId] = true;
                }
            }
            $kinds[(int) $planet['planet_id']] = $types;
        }
        $placed = [];
        $unplaced = [];
        foreach ($offices as $office) {
            $held = array_unique(array_map(fn (array $c): int => (int) $c['type_id'], $office['contents']));
            $scores = [];
            foreach ($kinds as $planetId => $types) {
                $scores[$planetId] = count(array_filter($held, fn (int $t): bool => isset($types[$t])));
            }
            arsort($scores);
            $best = array_key_first($scores);
            $values = array_values($scores);
            if ($best === null || $values[0] === 0 || (isset($values[1]) && $values[1] === $values[0]) || isset($placed[$best])) {
                $unplaced[] = $office;

                continue;
            }
            $placed[$best] = ['used' => (float) $office['used'], 'capacity' => $capacity, 'contents' => $office['contents']];
        }

        return ['placed' => $placed, 'unplaced' => $unplaced];
    }

    /**
     * Patch 59: what the storage says about the balance step's two lines.
     *
     * @param  array<string, array<string, mixed>>  $buffers
     */
    private function bufferNote(array $buffers, string $short, string $long): string
    {
        $notes = [];
        if (($buffers[$short]['per_hour'] ?? null) !== null && $buffers[$short]['per_hour'] < 0) {
            $notes[] = sprintf('%s storage agrees: down %s / h since the time before', $short, number_format(-$buffers[$short]['per_hour']));
        }
        if (($buffers[$long]['per_hour'] ?? null) !== null && $buffers[$long]['per_hour'] > 0) {
            $notes[] = sprintf('%s is piling up (%d%% full)', $long, (int) round($buffers[$long]['fill'] * 100));
        }

        return $notes === [] ? '' : ' '.implode('; ', $notes).'.';
    }

    /**
     * Patch 59: a fresh spot farther out may cost a head or two (a longer link uses power and
     * CPU). Losing one head of N pays off once the old spot is down more than 1/N, two heads 2/N.
     */
    public function freshSpotAdvice(int $down, int $heads): string
    {
        if ($heads < 3) {
            return 'A fresh spot pays off.';
        }
        if ($down * $heads > 200) {
            return sprintf('A fresh spot pays off even if a longer link leaves room for only %d heads.', $heads - 2);
        }
        if ($down * $heads > 100) {
            return sprintf('A fresh spot pays off if you can keep %d heads; with %d it is about even.', $heads - 1, $heads - 2);
        }

        return sprintf('Only worth it if the fresh spot keeps all %d heads.', $heads);
    }

    /**
     * Patch 59: all launchpads together (where the product waits to be launched or hauled).
     *
     * @param  array<string, mixed>  $planet
     * @return array{used: float, capacity: float, fill: float}|null
     */
    public function launchpad(array $planet): ?array
    {
        $used = 0.0;
        $capacity = 0.0;
        foreach ($planet['storage'] ?? [] as $store) {
            if (($store['kind'] ?? '') === 'launchpad') {
                $used += (float) $store['used'];
                $capacity += (float) $store['capacity'];
            }
        }

        return $capacity > 0 ? ['used' => $used, 'capacity' => $capacity, 'fill' => $used / $capacity] : null;
    }

    /**
     * Patch 59: every launchpad and storage facility with how full it is (launchpads first). The
     * command center only counts when there is nothing else.
     *
     * @param  array<string, mixed>  $planet
     * @return list<array{kind: string, fill: float, used: float, capacity: float, contents: list<array{name: string, amount: int}>}>
     */
    public function stores(array $planet): array
    {
        $order = ['launchpad' => 0, 'storage facility' => 1, 'command center' => 2];
        $stores = [];
        foreach ($planet['storage'] ?? [] as $store) {
            $capacity = (float) $store['capacity'];
            if ($capacity <= 0) {
                continue;
            }
            $contents = $store['contents'] ?? [];
            usort($contents, fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);
            $stores[] = [
                'kind' => (string) $store['kind'],
                'fill' => (float) $store['used'] / $capacity,
                'used' => (float) $store['used'],
                'capacity' => $capacity,
                'contents' => $contents,
            ];
        }
        $real = array_values(array_filter($stores, fn (array $s): bool => $s['kind'] !== 'command center'));
        if ($real !== []) {
            $stores = $real;
        }
        usort($stores, fn (array $a, array $b): int => [$order[$a['kind']] ?? 3, -$a['fill']] <=> [$order[$b['kind']] ?? 3, -$b['fill']]);

        return $stores;
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
        $ids = [];
        foreach ($planet['factories'] ?? [] as $factory) {
            foreach (array_unique($factory['outputs']) as $output) {
                $tier = $this->types[$output]['tier'] ?? null;
                if ($tier === null || $tier < 1) {
                    continue;
                }
                $name = $this->types[$output]['name'];
                $byTier[$tier][$name] = ($byTier[$tier][$name] ?? 0) + self::OUTPUT_PER_HOUR[$tier];
                $ids[$name] = (int) $output;
            }
        }
        if ($byTier === []) {
            return null;
        }
        $top = max(array_keys($byTier));

        return ['tier' => $top, 'names' => array_keys($byTier[$top]), 'per_hour' => $byTier[$top], 'type_ids' => array_intersect_key($ids, $byTier[$top])];
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

    /**
     * Patch 61: units a whole program makes (CCP's formula), from EVE's base qty_per_cycle.
     */
    public static function programUnits(int $qty, int $cycleSeconds, int $durationSeconds): int
    {
        if ($qty <= 0 || $cycleSeconds <= 0 || $durationSeconds < $cycleSeconds) {
            return 0;
        }
        $barWidth = $cycleSeconds / 900.0;
        $phase = $qty ** 0.7;
        $total = 0;
        $cycles = intdiv($durationSeconds, $cycleSeconds);
        for ($i = 0; $i < $cycles; $i++) {
            $t = ($i + 0.5) * $barWidth;
            $decay = $qty / (1 + $t * self::DECAY_FACTOR);
            $wave = max((cos($phase + $t / 12) + cos($phase / 2 + $t * 0.2) + cos($t * 0.5)) / 3, 0.0);
            $total += (int) ($barWidth * $decay * (1 + self::NOISE_FACTOR * $wave));
        }

        return $total;
    }

    /**
     * Patch 61: an extractor's average units per hour over its program (or a standard 6 d 20 h
     * program when EVE gives no start / end).
     *
     * @param  array<string, mixed>  $extractor
     */
    public function perHour(array $extractor): int
    {
        $qty = (int) ($extractor['qty'] ?? 0);
        $cycle = (int) ($extractor['cycle_time'] ?? 0);
        $install = $extractor['install'] ?? null;
        $expiry = $extractor['expiry'] ?? null;
        $duration = $install !== null && $expiry !== null && $expiry > $install ? (int) $expiry - (int) $install : self::STANDARD_PROGRAM_HOURS * 3600;
        $units = self::programUnits($qty, $cycle, $duration);

        return $units > 0 ? (int) round($units * 3600 / (intdiv($duration, $cycle) * $cycle)) : 0;
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
