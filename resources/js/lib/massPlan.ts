import { massText } from '@/lib/rollPlan';

/**
 * Patch 37: the plan for a mass session, jump by jump.
 *
 * Rules (keeping everyone home):
 * 1. A jump OUT never risks collapsing the hole behind the pilot (what went through stays
 *    under the worst case), unless "hurry" is on and the pilot is marked expendable: then it
 *    may gamble (the step says so).
 * 2. A jump HOME may collapse the hole only when rolling (the pilot is home), and never while
 *    the closer is still outside.
 * 3. Polarization: a pilot jumps the same hole in the same direction once per 5 minutes. Each
 *    jump takes about 15 s; the plan gives every step a time and waits when nobody is free.
 *    A crew that "can swap pilots" juggles: a polarized pilot's ship goes on with a free one.
 * 4. Crit / Reduced: when the hole has probably crossed the line (the middle of the window is
 *    past it), the plan stops: CHECK HOLE STATUS, mark it ("made by the last jump" when it
 *    was), and plan again from the new window.
 * 5. Roll with a closer: the heaviest ship that can go out safely waits on the far side, the
 *    others mass it to crit, then the closer comes home (it may close it), then the rest finish.
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
    /** The crew lets pilots swap ships (juggling). */
    canSwap: boolean;
};

export type TMassHole = {
    nominal: number;
    /** What's left: worst case, best case. */
    low: number;
    high: number;
    maxJump: number | null;
    status: 'fresh' | 'reduced' | 'critical';
};

export type TMassStep =
    | {
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
      }
    | { kind: 'wait'; at: number; seconds: number; text: string }
    | { kind: 'check'; at: number; text: string; expect: 'reduced' | 'crit' | 'status' }
    | { kind: 'done'; at: number; text: string }
    | { kind: 'stuck'; at: number; text: string };

export type TMassPlan = { goal: TMassGoal; steps: TMassStep[] };

export const POLARIZATION_SECONDS = 300;
export const JUMP_SECONDS = 15;
const MAX_JUMPS = 80;

type TShip = { owner: TMassPilot; options: TMassOption[] };

export function massPlan(goal: TMassGoal, hole: TMassHole, crew: readonly TMassPilot[], hurry = false): TMassPlan {
    const steps: TMassStep[] = [];
    if (goal === 'watch') return { goal, steps };

    const fits = (option: TMassOption) => hole.maxJump === null || option.mass <= hole.maxJump;
    const ships: TShip[] = crew.map((pilot) => ({ owner: pilot, options: pilot.options.filter(fits) })).filter((ship) => ship.options.length > 0);
    if (ships.length === 0) {
        return { goal, steps: [{ kind: 'stuck', at: 0, text: 'No one in the crew fits through this hole.' }] };
    }
    if ((goal === 'reduced' && hole.status !== 'fresh') || (goal === 'crit' && hole.status === 'critical')) {
        return { goal, steps: [{ kind: 'done', at: 0, text: `It already shows ${hole.status === 'critical' ? 'CRIT' : 'REDUCED'}.` }] };
    }

    // Polarization: the moment (seconds from now) each pilot may jump each way again.
    const free = new Map<number, { out: number; home: number }>();
    for (const pilot of crew) {
        free.set(pilot.id, {
            out: pilot.lastOut === null ? 0 : Math.max(0, POLARIZATION_SECONDS - pilot.lastOut),
            home: pilot.lastHome === null ? 0 : Math.max(0, POLARIZATION_SECONDS - pilot.lastHome),
        });
    }

    let t = 0;
    let passed = 0;
    let jumps = 0;
    let next = 0;
    const target = goal === 'reduced' ? hole.nominal * 0.5 : goal === 'crit' ? hole.nominal * 0.1 : 0;
    const lines = [hole.status === 'fresh' ? hole.nominal * 0.5 : null, hole.status !== 'critical' ? hole.nominal * 0.1 : null].filter(
        (value): value is number => value !== null && value >= target,
    );
    const left = (): [number, number] => [Math.max(0, hole.low - passed), Math.max(0, hole.high - passed)];
    const push = (pilot: TMassPilot, ship: TShip, direction: 'out' | 'home', option: TMassOption, flags: { mayCollapse: boolean; gamble: boolean; closer: boolean }) => {
        passed += option.mass;
        jumps++;
        steps.push({ kind: 'jump', at: t, pilotId: pilot.id, pilot: pilot.name, ship: ship.owner.ship, shipOwner: ship.owner.name, direction, option, left: left(), ...flags });
        const slot = free.get(pilot.id);
        if (slot) slot[direction] = t + POLARIZATION_SECONDS;
        t += JUMP_SECONDS;
    };
    let closer: { ship: TShip; pilot: TMassPilot } | null = null;
    /** A pilot for this ship, free to go out now and come home a jump later. */
    const pilotFor = (ship: TShip): TMassPilot | null => {
        const ok = (pilot: TMassPilot) => (free.get(pilot.id)?.out ?? 0) <= t && (free.get(pilot.id)?.home ?? 0) <= t + JUMP_SECONDS;
        if (ok(ship.owner)) return ship.owner;
        if (!ship.owner.canSwap) return null;
        return crew.find((pilot) => pilot.canSwap && pilot !== closer?.pilot && ok(pilot)) ?? null;
    };
    const soonest = () => Math.min(...crew.map((pilot) => Math.max(free.get(pilot.id)?.out ?? 0, (free.get(pilot.id)?.home ?? 0) - JUMP_SECONDS)));

    // Rule 5: the closer goes out first and waits.
    if (goal === 'closer') {
        const candidates = [...ships].sort((a, b) => Math.max(...b.options.map((o) => o.mass)) - Math.max(...a.options.map((o) => o.mass)));
        for (const ship of candidates) {
            const lightest = [...ship.options].sort((a, b) => a.mass - b.mass)[0];
            const pilot = pilotFor(ship);
            if (pilot && lightest.mass < left()[0]) {
                closer = { ship, pilot };
                push(pilot, ship, 'out', lightest, { mayCollapse: false, gamble: false, closer: true });
                break;
            }
        }
        if (!closer) steps.push({ kind: 'stuck', at: t, text: 'No ship can wait on the far side safely: rolling without a closer.' });
    }
    const crewShips = closer ? ships.filter((ship) => ship !== closer!.ship) : ships;
    let massing = goal === 'closer' && closer !== null;

    while (jumps < MAX_JUMPS) {
        const [low, high] = left();
        const finishing = goal === 'roll' || (goal === 'closer' && !massing);

        if (!finishing && high <= target && goal !== 'closer') {
            steps.push({ kind: 'done', at: t, text: goal === 'crit' ? 'It should show CRIT now: check it and mark it.' : 'It should show REDUCED now: check it and mark it.' });
            break;
        }
        if (finishing && high <= 0) {
            steps.push({ kind: 'done', at: t, text: 'It should be gone. Everyone is home.' });
            break;
        }
        // The closer comes home once the others have it at crit for sure (it may close it).
        if (goal === 'closer' && massing && closer && high <= hole.nominal * 0.1) {
            const heaviest = [...closer.ship.options].sort((a, b) => b.mass - a.mass)[0];
            const pilot = closer.pilot;
            const waitFor = Math.max(0, (free.get(pilot.id)?.home ?? 0) - t);
            if (waitFor > 0) {
                steps.push({ kind: 'wait', at: t, seconds: waitFor, text: `${pilot.name} is polarized: wait ${clock(waitFor)}` });
                t += waitFor;
            }
            push(pilot, closer.ship, 'home', heaviest, { mayCollapse: heaviest.mass >= low, gamble: false, closer: true });
            massing = false;
            continue;
        }
        // Probably crossed a line: look in game.
        const line = lines.find((value) => (low + high) / 2 <= value && value < high);
        if (line !== undefined && jumps > (closer ? 1 : 0)) {
            const expect = line === hole.nominal * 0.1 ? 'crit' : 'reduced';
            steps.push({ kind: 'check', at: t, expect, text: `CHECK HOLE STATUS: it may show ${expect === 'crit' ? 'CRIT' : 'REDUCED'} now. Mark it (tick "made by the last jump" if it was) and plan again.` });
            break;
        }

        // The next ship in crew order with a free pilot. Per ship: a trip that closes it for sure
        // (finishing, out safe, out + home ≥ the best case) beats the heaviest trip that can't
        // close it at all; failing both (finishing), a last try with a safe out and the heaviest home.
        type TChoice = { ship: TShip; pilot: TMassPilot; out: TMassOption; back: TMassOption; mayCollapse: boolean; gamble: boolean };
        let chosen: TChoice | null = null;
        let lastTry: TChoice | null = null;
        let gamble: TChoice | null = null;
        for (let offset = 0; offset < crewShips.length && !chosen; offset++) {
            const ship = crewShips[(next + offset) % crewShips.length];
            const pilot = pilotFor(ship);
            if (!pilot) continue;
            const pairs = ship.options.flatMap((out) => ship.options.map((back) => [out, back] as const));
            const safeOut = pairs.filter(([out]) => out.mass < low);
            const closing = finishing ? safeOut.filter(([out, back]) => out.mass + back.mass >= high).sort((a, b) => a[0].mass - b[0].mass)[0] : undefined;
            const quiet = safeOut.filter(([out, back]) => out.mass + back.mass < low).sort((a, b) => b[0].mass + b[1].mass - (a[0].mass + a[1].mass))[0];
            const pick = closing ?? quiet;
            if (pick) {
                chosen = { ship, pilot, out: pick[0], back: pick[1], mayCollapse: pick[0].mass + pick[1].mass >= low, gamble: false };
                next = (crewShips.indexOf(ship) + 1) % crewShips.length;
                break;
            }
            if (finishing && !lastTry) {
                const best = safeOut.sort((a, b) => b[1].mass - a[1].mass || a[0].mass - b[0].mass)[0];
                if (best) lastTry = { ship, pilot, out: best[0], back: best[1], mayCollapse: true, gamble: false };
            }
            if (finishing && hurry && pilot.expendable && !gamble) {
                const heavy = [...pairs].sort((a, b) => b[0].mass + b[1].mass - (a[0].mass + a[1].mass))[0];
                if (heavy) gamble = { ship, pilot, out: heavy[0], back: heavy[1], mayCollapse: true, gamble: heavy[0].mass >= low };
            }
        }
        chosen ??= lastTry ?? gamble;
        if (chosen) next = (crewShips.indexOf(chosen.ship) + 1) % crewShips.length;
        if (!chosen) {
            const wait = soonest() - t;
            if (wait > 0 && wait <= POLARIZATION_SECONDS) {
                steps.push({ kind: 'wait', at: t, seconds: wait, text: `Everyone is polarized: wait ${clock(wait)}` });
                t += wait;
                continue;
            }
            steps.push(
                jumps === (closer ? 1 : 0)
                    ? { kind: 'stuck', at: t, text: 'Nobody can go out without risking the hole collapsing behind them. Check the hole and mark it, add a lighter ship, or turn on hurry for expendable pilots.' }
                    : { kind: 'check', at: t, expect: 'status', text: 'CHECK HOLE STATUS: nobody can go out safely on what we know. Mark it and plan again.' },
            );
            break;
        }
        push(chosen.pilot, chosen.ship, 'out', chosen.out, { mayCollapse: chosen.gamble, gamble: chosen.gamble, closer: false });
        push(chosen.pilot, chosen.ship, 'home', chosen.back, { mayCollapse: chosen.mayCollapse, gamble: false, closer: false });
    }

    return { goal, steps };
}

/** "2:40". */
export function clock(seconds: number): string {
    const whole = Math.max(0, Math.round(seconds));
    return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
}

/** The plan as lines for fleet chat. */
export function massPlanText(plan: TMassPlan, holeLabel: string): string {
    const goal = { watch: 'Watching', reduced: 'Make it reduced', crit: 'Make it crit', roll: 'Roll it', closer: 'Roll it with a closer' }[plan.goal];
    let number = 0;
    const lines = plan.steps.map((step) => {
        if (step.kind !== 'jump') return `>> ${step.text}`;
        number++;
        const swap = step.pilot !== step.shipOwner ? ` (in ${step.shipOwner}'s ${step.ship})` : '';
        const flags = [step.closer ? 'closer' : null, step.gamble ? 'GAMBLE: may be left outside' : null, step.mayCollapse && !step.gamble ? 'may close it' : null].filter(Boolean);
        return `${number}. +${clock(step.at)} ${step.pilot}${swap} ${step.direction === 'out' ? 'OUT' : 'HOME'} ${step.option.label} ${massText(step.option.mass)}${flags.length ? ` [${flags.join(', ')}]` : ''}`;
    });
    return [`${goal}: ${holeLabel}`, ...lines].join('\n');
}
