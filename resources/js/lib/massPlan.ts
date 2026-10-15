/**
 * Patch 37: the plan for massing a hole, jump by jump, start to finish.
 *
 * What we know about the hole is a set of "could be" cases: its real size S (90–110 % of the
 * type's) with what is left r. A watched hole (every jump logged) has r = S − logged; otherwise
 * r is anywhere in the estimate. Every jump takes its mass off every case; every CHECK HOLE
 * STATUS keeps only the cases that show what the hole shows (the plan follows the middle
 * case, so it says what to expect, and "if it shows otherwise, mark it and plan again").
 *
 * Rules (keeping everyone home):
 * 1. A jump out never risks collapsing the hole behind the pilot: it stays under the smallest
 *    case, with room for everyone outside to come home (unless "hurry" and the pilot is
 *    expendable: GAMBLE).
 * 2. Only the last pilot coming home may collapse it, and only when rolling.
 * 3. Make it reduced / crit: it never goes further than asked. Big ships bring it close to the
 *    line (without crossing it in any case), then one jump at a time with the lightest decent
 *    ship and a check after each: the jump that flips it pins the size (a fresh hole that shows
 *    reduced after P logged with the last jump m is 2(P − m) < S ≤ 2P). Crit goes through the
 *    half-mass check first.
 * 4. Big ships first: anything smaller than a cruiser only when nothing bigger can go.
 * 5. Polarization: 5 min per pilot per hole per direction; a jump takes about 15 s. Crews that
 *    swap pilots juggle ships on the home side.
 * 6. Each choice is tried to the end (what follows from it) and the one that finishes in the
 *    fewest jumps wins; a ship the fleet could bring ("a rolling battleship") is suggested
 *    when it would save jumps.
 */

export type TMassGoal = 'watch' | 'reduced' | 'crit' | 'roll' | 'closer';
export type TMassOption = { label: string; mode: 'hot' | 'cold'; mass: number };

export type TMassPilot = {
    id: number;
    name: string;
    ship: string;
    options: TMassOption[];
    expendable: boolean;
    /** Seconds ago this pilot last jumped out / home through this hole (null: not lately). */
    lastOut: number | null;
    lastHome: number | null;
    /** The crew lets pilots swap ships on the home side (juggling). */
    canSwap: boolean;
    /** The hull's own mass: smaller than a cruiser only when nothing bigger can go. */
    hull?: number;
};

export type TMassObservation = { status: 'fresh' | 'reduced' | 'critical'; logged: number; flip: number | null };

export type TMassHole = {
    nominal: number;
    /** What's left: worst case, best case. */
    low: number;
    high: number;
    maxJump: number | null;
    status: 'fresh' | 'reduced' | 'critical';
    /** Every jump through it is logged (watched from new): left = size − logged. */
    watched?: boolean;
    logged?: number;
    /** What people saw (with the mass logged then) when watched. */
    observations?: readonly TMassObservation[];
};

export type TMassJumpStep = {
    kind: 'jump';
    at: number;
    pilotId: number;
    pilot: string;
    ship: string;
    shipOwner: string;
    direction: 'out' | 'home';
    option: TMassOption;
    left: [number, number];
    mayCollapse: boolean;
    gamble: boolean;
    closer: boolean;
};

export type TMassStep =
    | TMassJumpStep
    | { kind: 'wait'; at: number; seconds: number; text: string }
    | { kind: 'check'; at: number; text: string; expect: 'reduced' | 'crit' | 'status'; outcome: 'fresh' | 'reduced' | 'critical' }
    | { kind: 'done'; at: number; text: string }
    | { kind: 'stuck'; at: number; text: string };

export type TMassPlan = { goal: TMassGoal; steps: TMassStep[]; jumps: number; tips: string[] };

export const POLARIZATION_SECONDS = 300;
export const JUMP_SECONDS = 15;
/** Below this hull a ship is "smaller than a cruiser". */
export const CRUISER_HULL = 9_000_000;
const MAX_LEGS = 60;
const M = 1_000_000;

// ---------------------------------------------------------------------------------------
// What the hole could be

type TBand = 'gone' | 'critical' | 'reduced' | 'fresh';
const BANDS: TBand[] = ['gone', 'critical', 'reduced', 'fresh'];

function band(r: number, s: number): TBand {
    if (r <= 0) return 'gone';
    if (r <= 0.1 * s) return 'critical';
    if (r <= 0.5 * s) return 'reduced';
    return 'fresh';
}

/** Parallel lists of cases; index 0 is the middle case the plan follows. */
type TWorld = { s: number[]; r: number[] };

function grid(low: number, high: number, count: number): number[] {
    if (high - low < 1) return [low];
    return Array.from({ length: count }, (_, index) => low + ((high - low) * index) / (count - 1));
}

function initWorld(hole: TMassHole): TWorld {
    const n = hole.nominal;
    const sizes = grid(0.9 * n, 1.1 * n, hole.watched ? 61 : 21);
    const world: TWorld = { s: [], r: [] };
    if (hole.watched && hole.logged !== undefined) {
        for (const s of sizes) {
            const r = s - hole.logged;
            if (r <= 0) continue;
            const fits = (hole.observations ?? []).every((seen) => {
                const at = s - seen.logged;
                if (band(at, s) !== seen.status) return false;
                if (!seen.flip) return true;
                const before = band(at + seen.flip, s);
                return BANDS.indexOf(before) > BANDS.indexOf(seen.status);
            });
            if (fits) {
                world.s.push(s);
                world.r.push(r);
            }
        }
    }
    if (!world.s.length) {
        for (const s of sizes) {
            for (const r of grid(Math.max(1, hole.low), Math.max(1, hole.high), 15)) {
                if (r <= s) {
                    world.s.push(s);
                    world.r.push(r);
                }
            }
        }
    }
    // The middle case first: the size nearest the type's, what's left nearest the middle.
    const midR = (Math.min(...world.r) + Math.max(...world.r)) / 2;
    let best = 0;
    for (let index = 1; index < world.s.length; index++) {
        const score = (i: number) => Math.abs(world.s[i] - n) / n + Math.abs(world.r[i] - midR) / Math.max(1, midR);
        if (score(index) < score(best)) best = index;
    }
    return reorder(world, best);
}

function reorder(world: TWorld, first: number): TWorld {
    const order = [first, ...world.s.map((_, index) => index).filter((index) => index !== first)];
    return { s: order.map((index) => world.s[index]), r: order.map((index) => world.r[index]) };
}

const minR = (world: TWorld) => Math.min(...world.r);
const maxR = (world: TWorld) => Math.max(...world.r);

function shift(world: TWorld, mass: number): TWorld {
    return { s: world.s, r: world.r.map((r) => r - mass) };
}

/** Keep the cases that show what the middle case shows. */
function observe(world: TWorld): { world: TWorld; outcome: TBand } {
    const outcome = band(world.r[0], world.s[0]);
    const keep = world.r.map((r, index) => band(r, world.s[index]) === outcome);
    return { world: { s: world.s.filter((_, index) => keep[index]), r: world.r.filter((_, index) => keep[index]) }, outcome };
}

/** Whether a line (0.5 = reduced, 0.1 = crit) is above, below or across the cases. */
function lineState(world: TWorld, share: number): 'above' | 'below' | 'across' {
    let above = 0;
    for (let index = 0; index < world.r.length; index++) if (world.r[index] > share * world.s[index]) above++;
    return above === world.r.length ? 'above' : above === 0 ? 'below' : 'across';
}

/** A few cases for trying choices out: the extremes and the middle. */
function sample(world: TWorld): TWorld {
    if (world.r.length <= 12) return world;
    const order = world.r.map((_, index) => index).slice(1).sort((a, b) => world.r[a] - world.r[b] || world.s[a] - world.s[b]);
    const picks = new Set<number>([0]);
    for (let k = 0; k < 11; k++) picks.add(order[Math.round((k * (order.length - 1)) / 10)]);
    const list = [...picks];
    return { s: list.map((index) => world.s[index]), r: list.map((index) => world.r[index]) };
}

// ---------------------------------------------------------------------------------------
// Ships, pilots and the plan state

type TShip = { key: number; owner: TMassPilot; options: TMassOption[]; small: boolean; out: boolean; pilotId: number | null };
type TPilotState = { pilot: TMassPilot; out: boolean; freeOut: number; freeHome: number; inShip: number | null };

type TState = {
    goal: TMassGoal;
    nominal: number;
    hurry: boolean;
    world: TWorld;
    ships: TShip[];
    pilots: TPilotState[];
    t: number;
    legs: number;
    checkedSinceLeg: boolean;
    closerShip: number | null;
    steps: TMassStep[];
    /** The lines that get a one-jump-at-a-time approach (0.5, 0.1), in order. */
    precise: number[];
    gone: boolean;
    stuck: boolean;
};

type TLeg = { ship: TShip; pilot: TPilotState; direction: 'out' | 'home'; option: TMassOption; gamble: boolean };

function clone(state: TState): TState {
    return {
        ...state,
        ships: state.ships.map((ship) => ({ ...ship })),
        pilots: state.pilots.map((pilot) => ({ ...pilot })),
        steps: [...state.steps],
        precise: [...state.precise],
    };
}

const lightest = (options: TMassOption[]) => Math.min(...options.map((option) => option.mass));
const heaviest = (options: TMassOption[]) => Math.max(...options.map((option) => option.mass));

/** Distinct masses only (two props of one size are one choice). */
function distinct(options: TMassOption[]): TMassOption[] {
    const seen = new Map<number, TMassOption>();
    for (const option of [...options].sort((a, b) => b.mass - a.mass)) if (!seen.has(Math.round(option.mass / 1000))) seen.set(Math.round(option.mass / 1000), option);
    return [...seen.values()];
}

const collapseAllowed = (goal: TMassGoal) => goal === 'roll' || goal === 'closer';

/** What the ships outside need to come home (all but the last, who may close it when rolling). */
function reserve(state: TState, except: TShip | null): number {
    const outside = state.ships.filter((ship) => ship.out && ship !== except).map((ship) => lightest(ship.options));
    if (!outside.length) return 0;
    const total = outside.reduce((sum, mass) => sum + mass, 0);
    return collapseAllowed(state.goal) ? total - Math.max(...outside) : total;
}

function legs(state: TState, allowSmall: boolean): TLeg[] {
    const result: TLeg[] = [];
    const world = state.world;
    const low = minR(world);
    const seen = new Set<string>();
    for (const ship of state.ships) {
        if (ship.small && !allowSmall) continue;
        const pilots = ship.out
            ? state.pilots.filter((pilot) => pilot.pilot.id === ship.pilotId)
            : state.pilots.filter((pilot) => !pilot.out && (pilot.pilot.id === ship.owner.id || (pilot.pilot.canSwap && ship.owner.canSwap && pilot.inShip === null)));
        for (const pilot of pilots) {
            const direction = ship.out ? 'home' : 'out';
            if ((direction === 'out' ? pilot.freeOut : pilot.freeHome) > state.t) continue;
            if (state.closerShip !== null && direction === 'out' && state.ships.some((other) => other.key === state.closerShip && !other.out) && ship.key !== state.closerShip) continue;
            for (const option of distinct(ship.options)) {
                const key = `${direction}:${ship.owner.ship}:${option.mass}:${pilot.out}`;
                if (seen.has(key + ':' + ship.key)) continue;
                seen.add(key + ':' + ship.key);
                const leg = validLeg(state, ship, pilot, direction, option, low);
                if (leg) result.push(leg);
            }
        }
    }
    return result;
}

/** How low the hole may go without the goal going too far: crit for "reduced", open for "crit". */
function limitShare(goal: TMassGoal): number | null {
    return goal === 'reduced' ? 0.1 : goal === 'crit' ? 0 : null;
}

/** Every case keeps more than `share` of its size after `mass` more goes through. */
function staysAbove(world: TWorld, mass: number, share: number): boolean {
    return world.r.every((r, index) => r - mass > share * world.s[index]);
}

function validLeg(state: TState, ship: TShip, pilot: TPilotState, direction: 'out' | 'home', option: TMassOption, low: number): TLeg | null {
    const goal = state.goal;
    const world = state.world;
    const limit = limitShare(goal);
    if (direction === 'out') {
        if (limit !== null) {
            // Room for this ship and everyone outside to come home, without the goal going too far.
            if (staysAbove(world, option.mass + lightest(ship.options) + reserve(state, null), limit)) return { ship, pilot, direction, option, gamble: false };
            return null;
        }
        if (low > option.mass + reserve(state, null)) return { ship, pilot, direction, option, gamble: false };
        if (state.hurry && pilot.pilot.expendable && world.r[0] > option.mass) return { ship, pilot, direction, option, gamble: true };
        return null;
    }
    const lastOut = state.ships.filter((other) => other.out).length === 1;
    if (limit === null && lastOut) return { ship, pilot, direction, option, gamble: false };
    if (limit !== null) return staysAbove(world, option.mass + reserve(state, ship), limit) ? { ship, pilot, direction, option, gamble: false } : null;
    return low > option.mass + reserve(state, ship) ? { ship, pilot, direction, option, gamble: false } : null;
}

function apply(state: TState, leg: TLeg, record: boolean): void {
    const before = state.world;
    state.world = shift(before, leg.option.mass);
    state.legs++;
    state.checkedSinceLeg = false;
    const mayCollapse = before.r.some((r) => r - leg.option.mass <= 0);
    leg.ship.out = leg.direction === 'out';
    leg.ship.pilotId = leg.direction === 'out' ? leg.pilot.pilot.id : null;
    leg.pilot.out = leg.direction === 'out';
    leg.pilot.inShip = leg.direction === 'out' ? leg.ship.key : null;
    if (leg.direction === 'out') leg.pilot.freeOut = state.t + POLARIZATION_SECONDS;
    else leg.pilot.freeHome = state.t + POLARIZATION_SECONDS;
    if (record) {
        state.steps.push({
            kind: 'jump',
            at: state.t,
            pilotId: leg.pilot.pilot.id,
            pilot: leg.pilot.pilot.name,
            ship: leg.ship.owner.ship,
            shipOwner: leg.ship.owner.name,
            direction: leg.direction,
            option: leg.option,
            left: [Math.max(0, minR(state.world)), Math.max(0, maxR(state.world))],
            mayCollapse,
            gamble: leg.gamble,
            closer: leg.ship.key === state.closerShip,
        });
    }
    state.t += JUMP_SECONDS;
    if (state.world.r[0] <= 0) state.gone = true;
}

function goalReached(state: TState): boolean {
    if (state.ships.some((ship) => ship.out)) return false;
    if (state.goal === 'reduced') return lineState(state.world, 0.5) === 'below';
    if (state.goal === 'crit') return lineState(state.world, 0.1) === 'below';
    return state.gone;
}

/** CHECK HOLE STATUS when a line runs across the cases and nothing was checked since the last jump. */
function maybeCheck(state: TState, record: boolean): void {
    if (state.checkedSinceLeg || state.gone || state.legs === 0) return;
    const lines = [0.5, 0.1].filter((share) => lineState(state.world, share) === 'across');
    if (!lines.length) return;
    const { world, outcome } = observe(state.world);
    state.world = world;
    state.checkedSinceLeg = true;
    if (!record || outcome === 'gone') return;
    const last = [...state.steps].reverse().find((step): step is TMassJumpStep => step.kind === 'jump');
    const name = { fresh: 'FRESH', reduced: 'REDUCED', critical: 'CRIT' }[outcome];
    const flips = state.steps.filter((step) => step.kind === 'check').at(-1);
    const flipped = flips?.kind === 'check' && flips.outcome !== outcome;
    state.steps.push({
        kind: 'check',
        at: state.t,
        expect: lines.includes(0.1) ? 'crit' : 'reduced',
        outcome,
        text: `CHECK HOLE STATUS: expect ${name}${
            flipped || outcome !== 'fresh' ? ` (mark it${last ? ', made by this jump' : ''})` : ''
        } · left ${massText(Math.max(0, minR(state.world)))}–${massText(Math.max(0, maxR(state.world)))}. If it shows otherwise, mark it and plan again.`,
    });
}

/** The line the plan is approaching one jump at a time, if any. */
function activeLine(state: TState): number | null {
    for (const share of state.precise) if (lineState(state.world, share) !== 'below') return share;
    return null;
}

/**
 * The jumps that may go now: cruisers and bigger; smaller ships only when no bigger one can
 * go even after waiting out polarization. Roll with a closer: the closer comes home only
 * when that closes it for sure, or nothing else can go.
 */
function candidates(state: TState): TLeg[] {
    let options = legs(state, false);
    if (!options.length) {
        const bigWaiting = state.pilots.some((pilot) => Math.max(pilot.freeOut, pilot.freeHome) > state.t && state.ships.some((ship) => !ship.small && ship.owner.id === pilot.pilot.id));
        if (bigWaiting) return [];
        options = legs(state, true);
    }
    if (state.goal === 'closer' && state.closerShip !== null) {
        const others = options.filter((leg) => leg.ship.key !== state.closerShip);
        const closing = options.filter((leg) => leg.ship.key === state.closerShip && leg.direction === 'home' && state.world.r.every((r) => r - leg.option.mass <= 0));
        if (closing.length) return closing;
        if (others.length) return others;
    }
    return options;
}

/** The lightest jump worth one-at-a-time steps: the lightest decent option of at least 1 % of the hole. */
function resolution(state: TState, nominal: number): number {
    const masses = state.ships.filter((ship) => !ship.small).flatMap((ship) => ship.options.map((option) => option.mass));
    const useful = masses.filter((mass) => mass >= 0.01 * nominal);
    return Math.max(0.02 * nominal, useful.length ? Math.min(...useful) : Math.min(...masses, Infinity));
}

/**
 * The jumps allowed now. Approaching a line one jump at a time: while every case is above it,
 * jumps that keep it so (a big ship may go out and wait); at the line, light jumps only.
 */
function allowed(state: TState): TLeg[] {
    const options = candidates(state);
    const line = activeLine(state);
    if (line === null || !options.length) return options;
    if (lineState(state.world, line) === 'above') {
        const approach = options.filter((leg) => staysAbove(state.world, leg.option.mass, line));
        if (approach.length) return approach;
    }
    const step = resolution(state, state.nominal);
    const light = options.filter((leg) => leg.option.mass <= step * 1.001);
    return light.length ? light : options;
}

/** The default choice (also used to try each choice to the end). */
function policy(state: TState): TLeg | null {
    const options = allowed(state);
    if (!options.length) return null;
    const line = activeLine(state);
    if (line !== null) {
        if (lineState(state.world, line) === 'above' && options.every((leg) => staysAbove(state.world, leg.option.mass, line))) return pickHeaviest(options);
        // At the line: the lightest decent jump, one at a time (home first on a tie).
        return pickLightest(options);
    }
    if (state.goal === 'reduced') {
        const home = options.filter((leg) => leg.direction === 'home');
        return home.length ? pickLightest(home) : null;
    }
    if (state.goal === 'crit') return pickHeaviest(options);
    // Rolling: a jump home that closes it in every case, else the heaviest that may go.
    const closing = options.filter((leg) => leg.direction === 'home' && state.world.r.every((r) => r - leg.option.mass <= 0));
    if (closing.length) return pickLightest(closing);
    return pickHeaviest(options);
}

const pickHeaviest = (options: TLeg[]) => options.reduce((best, leg) => (leg.option.mass > best.option.mass ? leg : best));
const pickLightest = (options: TLeg[]) =>
    options.reduce((best, leg) => (leg.option.mass < best.option.mass || (leg.option.mass === best.option.mass && leg.direction === 'home' && best.direction === 'out') ? leg : best));

/** Wait for the soonest pilot when everyone is polarized; false when that won't help. */
function waitForPilot(state: TState, record: boolean): boolean {
    const times = state.pilots.flatMap((pilot) => [pilot.freeOut, pilot.freeHome]).filter((time) => time > state.t);
    if (!times.length) return false;
    const until = Math.min(...times);
    if (record) {
        const last = state.steps.at(-1);
        const start = last?.kind === 'wait' ? last.at : state.t;
        if (last?.kind === 'wait') state.steps.pop();
        state.steps.push({ kind: 'wait', at: start, seconds: until - start, text: `Polarized: wait ${clock(until - start)}` });
    }
    state.t = until;
    return true;
}

/** Plays the plan to the end with the default choices: how many jumps it takes (or Infinity). */
function rollout(start: TState): number {
    const state = clone(start);
    state.world = sample(state.world);
    let waits = 0;
    while (state.legs < MAX_LEGS) {
        maybeCheck(state, false);
        if (goalReached(state)) return state.legs + state.t / 600;
        const leg = policy(state);
        if (!leg) {
            if (waits++ < 6 && waitForPilot(state, false)) continue;
            return Infinity;
        }
        waits = 0;
        apply(state, leg, false);
        if (state.gone) return state.legs + state.t / 600 + (state.ships.some((ship) => ship.out) ? 1000 : 0);
    }
    return Infinity;
}

/** Each possible next jump, tried to the end: the one finishing soonest (ties: the default). */
function choose(state: TState): TLeg | null {
    const fallback = policy(state);
    const options = allowed(state);
    let best = fallback;
    let bestScore = fallback ? score(state, fallback) : Infinity;
    for (const leg of options.slice(0, 30)) {
        const value = score(state, leg);
        if (value < bestScore - 0.5) {
            best = leg;
            bestScore = value;
        }
    }
    return best;
}

function score(state: TState, leg: TLeg): number {
    const next = clone(state);
    const ship = next.ships.find((candidate) => candidate.key === leg.ship.key)!;
    const pilot = next.pilots.find((candidate) => candidate.pilot.id === leg.pilot.pilot.id)!;
    apply(next, { ...leg, ship, pilot }, false);
    if (next.gone) return next.ships.some((other) => other.out) ? Infinity : next.legs;
    return rollout(next);
}

// ---------------------------------------------------------------------------------------

export function massPlan(goal: TMassGoal, hole: TMassHole, crew: readonly TMassPilot[], hurry = false): TMassPlan {
    if (goal === 'watch') return { goal, steps: [], jumps: 0, tips: [] };
    if ((goal === 'reduced' && hole.status !== 'fresh') || (goal === 'crit' && hole.status === 'critical')) {
        return { goal, steps: [{ kind: 'done', at: 0, text: `It already shows ${hole.status === 'critical' ? 'CRIT' : 'REDUCED'}.` }], jumps: 0, tips: [] };
    }
    const base = run(goal, hole, crew, hurry, true);
    // Rolling: with the half-mass check when it costs no more than two extra jumps.
    if (goal === 'roll' && hole.status === 'fresh') {
        const loose = run(goal, hole, crew, hurry, false);
        const chosen = loose.jumps + 2 < base.jumps || !finished(base) ? loose : base;
        return withTips(chosen, goal, hole, crew, hurry);
    }
    return withTips(base, goal, hole, crew, hurry);
}

const finished = (plan: TMassPlan) => plan.steps.at(-1)?.kind === 'done';

function run(goal: TMassGoal, hole: TMassHole, crew: readonly TMassPilot[], hurry: boolean, precise: boolean): TMassPlan {
    const fits = (option: TMassOption) => hole.maxJump === null || option.mass <= hole.maxJump;
    const ships: TShip[] = crew
        .map((pilot, index) => ({
            key: index,
            owner: pilot,
            options: pilot.options.filter(fits),
            small: (pilot.hull ?? Math.max(0, ...pilot.options.map((option) => option.mass))) < CRUISER_HULL,
            out: false,
            pilotId: null,
        }))
        .filter((ship) => ship.options.length > 0);
    if (!ships.length) return { goal, steps: [{ kind: 'stuck', at: 0, text: 'No one in the crew fits through this hole.' }], jumps: 0, tips: [] };

    const state: TState = {
        goal,
        nominal: hole.nominal,
        hurry,
        world: initWorld(hole),
        ships,
        pilots: crew.map((pilot) => ({
            pilot,
            out: false,
            inShip: null,
            freeOut: pilot.lastOut === null ? 0 : Math.max(0, POLARIZATION_SECONDS - pilot.lastOut),
            freeHome: pilot.lastHome === null ? 0 : Math.max(0, POLARIZATION_SECONDS - pilot.lastHome),
        })),
        t: 0,
        legs: 0,
        checkedSinceLeg: true,
        closerShip: null,
        steps: [],
        precise: [],
        gone: false,
        stuck: false,
    };
    if (goal === 'reduced') state.precise = [0.5];
    if (goal === 'crit') state.precise = hole.status === 'fresh' ? [0.5] : [];
    if (goal === 'roll' && precise && hole.status === 'fresh') state.precise = [0.5];

    // Roll with a closer: the heaviest ship that can wait outside goes out first, light.
    if (goal === 'closer') {
        const low = minR(state.world);
        const closer = [...ships].sort((a, b) => heaviest(b.options) - heaviest(a.options)).find((ship) => lightest(ship.options) < low && !ship.small);
        if (closer) {
            state.closerShip = closer.key;
            const pilot = state.pilots.find((candidate) => candidate.pilot.id === closer.owner.id)!;
            const option = distinct(closer.options).at(-1)!;
            apply(state, { ship: closer, pilot, direction: 'out', option, gamble: false }, true);
        } else {
            state.steps.push({ kind: 'stuck', at: 0, text: 'No ship can wait on the far side safely: rolling without a closer.' });
        }
    }

    let waits = 0;
    while (state.legs < MAX_LEGS) {
        maybeCheck(state, true);
        if (goalReached(state)) {
            state.steps.push({ kind: 'done', at: state.t, text: doneText(state) });
            break;
        }
        const leg = choose(state);
        if (!leg) {
            if (waits++ < 12 && waitForPilot(state, true)) continue;
            state.stuck = true;
            state.steps.push({
                kind: 'stuck',
                at: state.t,
                text: state.ships.some((ship) => ship.out)
                    ? 'Nobody can come home without risking a collapse with someone still out: check the hole and plan again.'
                    : 'Nobody can go out without risking the hole collapsing behind them. Check the hole and mark it, or bring a lighter ship (a HIC).',
            });
            break;
        }
        waits = 0;
        apply(state, leg, true);
        if (state.gone) {
            const sure = state.world.r.every((r) => r <= 0);
            state.steps.push({
                kind: 'done',
                at: state.t,
                text: state.ships.some((ship) => ship.out)
                    ? 'It may close here with someone outside!'
                    : sure
                      ? 'Gone for sure. Everyone is home.'
                      : 'It should close with this jump (everyone is home). If it is still there, mark it and plan again.',
            });
            break;
        }
    }
    return { goal, steps: state.steps, jumps: state.legs, tips: [] };
}

function doneText(state: TState): string {
    const left = `${massText(Math.max(0, minR(state.world)))}–${massText(Math.max(0, maxR(state.world)))}`;
    if (state.goal === 'reduced') return `It shows REDUCED, everyone is home. Left ${left}.`;
    if (state.goal === 'crit') return `It shows CRIT, everyone is home. Left ${left}.`;
    return 'Done.';
}

/** Ships worth bringing: tried as an extra crew member, suggested when they save jumps. */
const SUGGESTIONS: { name: string; ship: string; hull: number; options: TMassOption[] }[] = [
    {
        name: 'a rolling battleship (Higgs)',
        ship: 'Rolling battleship',
        hull: 100 * M,
        options: [
            { label: 'HOT 500MN', mode: 'hot', mass: 300 * M },
            { label: 'COLD', mode: 'cold', mass: 200 * M },
        ],
    },
    {
        name: 'a rolling HIC (Higgs)',
        ship: 'Rolling HIC',
        hull: 12 * M,
        options: [
            { label: 'HOT', mode: 'hot', mass: 132.4 * M },
            { label: 'COLD', mode: 'cold', mass: 32.4 * M },
            { label: '🧵 3 entanglers', mode: 'cold', mass: 1.07 * M },
        ],
    },
];

function withTips(plan: TMassPlan, goal: TMassGoal, hole: TMassHole, crew: readonly TMassPilot[], hurry: boolean): TMassPlan {
    const tips: string[] = [];
    const small = crew.filter((pilot) => (pilot.hull ?? Math.max(0, ...pilot.options.map((option) => option.mass))) < CRUISER_HULL);
    if (small.length && !plan.steps.some((step) => step.kind === 'jump' && small.some((pilot) => pilot.name === step.shipOwner))) {
        tips.push(`Left out (smaller than a cruiser): ${small.map((pilot) => pilot.name).join(', ')}.`);
    }
    if (plan.jumps > 8 || !finished(plan)) {
        for (const extra of SUGGESTIONS) {
            const virtual: TMassPilot = { id: -1, name: `+ ${extra.ship}`, ship: extra.ship, options: extra.options, expendable: false, lastOut: null, lastHome: null, canSwap: false, hull: extra.hull };
            const better = run(goal, hole, [...crew, virtual], hurry, goal !== 'roll');
            if (finished(better) && (!finished(plan) || better.jumps <= plan.jumps - 3)) {
                tips.push(`Bringing ${extra.name} would do it in ${better.jumps} jumps${finished(plan) ? ` instead of ${plan.jumps}` : ''}.`);
                break;
            }
        }
    }
    return { ...plan, tips };
}

/** "2:40". */
export function clock(seconds: number): string {
    const whole = Math.max(0, Math.round(seconds));
    return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
}

/** "132.4m". */
export function massText(kg: number): string {
    const m = kg / M;
    const abs = Math.abs(m);
    const text = abs < 10 ? abs.toFixed(2) : abs < 100 ? abs.toFixed(1) : Math.round(abs).toLocaleString('en-US');
    return `${m < 0 ? '−' : ''}${text}m`;
}

/** The plan as lines for fleet chat. */
export function massPlanText(plan: TMassPlan, holeLabel: string): string {
    const goal = { watch: 'Watching', reduced: 'Make it reduced', crit: 'Make it crit', roll: 'Roll it', closer: 'Roll it with a closer' }[plan.goal];
    let number = 0;
    const lines = plan.steps.map((step) => {
        if (step.kind !== 'jump') return `>> ${step.text}`;
        number++;
        const swap = step.pilot !== step.shipOwner ? ` (in ${step.shipOwner}'s ${step.ship})` : ` (${step.ship})`;
        const flags = [step.closer ? 'closer' : null, step.gamble ? 'GAMBLE: may be left outside' : null, step.mayCollapse && !step.gamble ? 'may close it' : null].filter(Boolean);
        return `${number}. +${clock(step.at)} ${step.pilot}${swap} ${step.direction === 'out' ? 'OUT' : 'HOME'} ${step.option.label} ${massText(step.option.mass)}${flags.length ? ` [${flags.join(', ')}]` : ''}`;
    });
    return [`${goal}: ${holeLabel}`, ...lines, ...plan.tips.map((tip) => `>> ${tip}`)].join('\n');
}
