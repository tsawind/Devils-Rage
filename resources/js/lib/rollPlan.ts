/**
 * Patch 37: the rolling planner ("Mass a hole" in the Fleet window).
 *
 * What's left in the hole is a range [low, high] (see estimateMass): the worst case is the
 * low end (it collapses sooner), the best case the high end. The planner sends ships out
 * and back with their own cold (prop off) and hot (prop on) masses and keeps three rules:
 *
 * 1. Nobody gets stranded: a jump OUT must never be able to collapse the hole (the mass
 *    through it stays under the worst case). A jump HOME may collapse it only when rolling.
 * 2. When the hole has probably crossed a line (reduced 50%, crit 10%: the middle of the
 *    range is past it) the plan stops at a CHECK HOLE STATUS: you look in game and mark the pipe; the plan carries on from the
 *    new numbers (a mark restarts the math).
 * 3. One person's characters first (they can check the hole themselves); among those the
 *    person who needs the fewest characters. Only when nobody can do it alone are crews
 *    mixed. Within a crew ships go in their squad order (the ISBoxer order).
 */

export type TRollGoal = 'reduced' | 'crit' | 'roll';
export type TJumpMode = 'hot' | 'cold';

export type TRollShip = {
    id: number;
    name: string;
    ship: string;
    /** Who flies it (their SeAT main, else the account): one person = one crew. */
    owner: string;
    /** Position in its squad (the ISBoxer order). */
    order: number;
    cold: number;
    hot: number;
    /** Patch 37: every jump mass the ship can make (each prop, a HIC's entanglers); else cold and hot. */
    options?: TRollOption[];
};

export type TRollOption = { label: string; mode: TJumpMode; mass: number };

export type TRollHole = {
    total: number;
    /** What may be left, worst case (low) and best case (high). */
    low: number;
    high: number;
    /** The most one ship may weigh per jump. */
    maxJump: number | null;
    /** What it shows in game now (the newest mark). */
    status?: 'fresh' | 'reduced' | 'critical' | null;
};

export type TRollJump = { mode: TJumpMode; mass: number; label: string };

export type TRollStep =
    | { kind: 'trip'; ship: TRollShip; out: TRollJump; back: TRollJump; left: [number, number]; mayCollapse: boolean }
    | { kind: 'check'; expect: 'reduced' | 'crit' | 'gone' | 'status'; left: [number, number]; text: string }
    | { kind: 'done'; text: string }
    | { kind: 'stuck'; text: string };

export type TRollPlan = { goal: TRollGoal; crew: string; owners: string[]; steps: TRollStep[]; jumps: number };

const REDUCED = 0.5;
const CRIT = 0.1;
/** Never plan more than this many round trips before a check. */
const MAX_TRIPS = 40;

/** A ship's jump masses: its options, else cold and hot. */
export function optionsOf(ship: TRollShip): TRollOption[] {
    if (ship.options?.length) return ship.options;
    return [
        { label: 'HOT', mode: 'hot', mass: ship.hot },
        { label: 'COLD', mode: 'cold', mass: ship.cold },
    ];
}

/** Every out + home pair, heaviest first. */
function pairsOf(ship: TRollShip, maxJump: number | null): [TRollOption, TRollOption][] {
    const options = optionsOf(ship).filter((option) => maxJump === null || option.mass <= maxJump);
    const pairs: [TRollOption, TRollOption][] = [];
    for (const out of options) for (const back of options) pairs.push([out, back]);
    return pairs.sort((a, b) => b[0].mass + b[1].mass - (a[0].mass + a[1].mass) || b[1].mass - a[1].mass);
}

/** Plan with these ships only (in this order). */
export function planWith(goal: TRollGoal, hole: TRollHole, ships: readonly TRollShip[], crew = 'crew'): TRollPlan {
    const steps: TRollStep[] = [];
    const fits = ships.filter((ship) => optionsOf(ship).some((option) => hole.maxJump === null || option.mass <= hole.maxJump));
    const target = goal === 'reduced' ? hole.total * REDUCED : goal === 'crit' ? hole.total * CRIT : 0;
    let passed = 0;
    let next = 0;
    let trips = 0;
    const left = (): [number, number] => [Math.max(0, hole.low - passed), Math.max(0, hole.high - passed)];

    // Lines it hasn't been seen to cross yet (the ones a check can tell us about).
    const status = hole.status ?? 'fresh';
    const lines = [status === 'fresh' ? hole.total * REDUCED : null, status !== 'critical' ? hole.total * CRIT : null].filter((value): value is number => value !== null && value >= target);
    if ((goal === 'reduced' && status !== 'fresh') || (goal === 'crit' && status === 'critical')) {
        return { goal, crew, owners: [], steps: [{ kind: 'done', text: `It already shows ${status === 'critical' ? 'CRIT' : 'REDUCED'}.` }], jumps: 0 };
    }

    if (fits.length === 0) {
        return { goal, crew, owners: [], steps: [{ kind: 'stuck', text: 'No ticked ship fits through this hole.' }], jumps: 0 };
    }

    while (trips < MAX_TRIPS) {
        const [low, high] = left();
        // Reached the goal for sure.
        if (goal !== 'roll' && high <= target) {
            steps.push({ kind: 'done', text: goal === 'crit' ? 'It should show CRIT now. Check it and mark it.' : 'It should show REDUCED now. Check it and mark it.' });
            break;
        }
        if (goal === 'roll' && high <= 0) {
            steps.push({ kind: 'done', text: 'It should be gone. Check that everyone is home.' });
            break;
        }
        // May have crossed a line (reduced on the way to crit, or the goal): look in game.
        // A line counts as probably crossed once the middle of the range is past it.
        const line = lines.find((value) => (low + high) / 2 <= value && value < high);
        if (line !== undefined && trips > 0) {
            const expect = line === hole.total * CRIT ? 'crit' : 'reduced';
            steps.push({
                kind: 'check',
                expect,
                left: [low, high],
                text: `CHECK HOLE STATUS: it may show ${expect === 'crit' ? 'CRIT' : 'REDUCED'} now. Mark what it shows on the pipe; the plan carries on from there.`,
            });
            break;
        }

        // The next ship (squad order, cycling) with the heaviest safe out + back.
        let chosen: { ship: TRollShip; out: TRollOption; back: TRollOption; collapse: boolean } | null = null;
        for (let offset = 0; offset < fits.length && !chosen; offset++) {
            const ship = fits[(next + offset) % fits.length];
            for (const [out, back] of pairsOf(ship, hole.maxJump)) {
                const outMass = out.mass;
                const total = outMass + back.mass;
                // Rule 1: out never collapses it; home only collapses it when rolling.
                if (outMass >= low) continue;
                if (goal !== 'roll' && total >= low) continue;
                chosen = { ship, out, back, collapse: total >= low };
                next = (fits.indexOf(ship) + 1) % fits.length;
                break;
            }
        }
        if (!chosen) {
            steps.push(
                passed === 0
                    ? { kind: 'stuck', text: 'No ship can go out without risking the hole collapsing behind it. Check the hole and mark it, or add a lighter ship.' }
                    : {
                          kind: 'check',
                          expect: goal === 'roll' ? 'status' : goal === 'crit' ? 'crit' : 'reduced',
                          left: [low, high],
                          text: 'CHECK HOLE STATUS: no ship can go out safely on what we know. Mark what it shows; the plan carries on from there.',
                      },
            );
            break;
        }

        const out = { mode: chosen.out.mode, mass: chosen.out.mass, label: chosen.out.label };
        const back = { mode: chosen.back.mode, mass: chosen.back.mass, label: chosen.back.label };
        passed += out.mass + back.mass;
        trips++;
        steps.push({ kind: 'trip', ship: chosen.ship, out, back, left: left(), mayCollapse: chosen.collapse });
    }
    if (trips >= MAX_TRIPS) steps.push({ kind: 'check', expect: 'status', left: left(), text: 'CHECK HOLE STATUS before going on.' });

    return { goal, crew, owners: [...new Set(steps.flatMap((step) => (step.kind === 'trip' ? [step.ship.owner] : [])))], steps, jumps: trips * 2 };
}

/** How far a plan gets: done beats a check beats stuck; then fewer jumps. */
function score(plan: TRollPlan): number {
    const last = plan.steps.at(-1);
    const base = last?.kind === 'done' ? 0 : last?.kind === 'check' ? 1_000 : 1_000_000;
    return base + plan.jumps;
}

/**
 * The plan for a goal: each person's crew alone first (fewest characters first), the one that
 * gets furthest with the fewest jumps; mixed crews only when no single person gets anywhere.
 */
export function rollPlan(goal: TRollGoal, hole: TRollHole, ships: readonly TRollShip[]): TRollPlan {
    const owners = new Map<string, TRollShip[]>();
    for (const ship of [...ships].sort((a, b) => a.order - b.order)) {
        owners.set(ship.owner, [...(owners.get(ship.owner) ?? []), ship]);
    }
    const solo = [...owners.entries()]
        .sort((a, b) => a[1].length - b[1].length)
        .map(([owner, list]) => planWith(goal, hole, list, `${owner}'s characters`));
    const best = solo.filter((plan) => plan.jumps > 0 || plan.steps.at(-1)?.kind === 'done').sort((a, b) => score(a) - score(b))[0];
    if (best && best.steps.at(-1)?.kind !== 'stuck') return best;

    // Mixed: people with fewer characters first, each in their squad order.
    const mixed = [...owners.values()].sort((a, b) => a.length - b.length).flat();
    return planWith(goal, hole, mixed, 'mixed crew');
}

/** "1,4xx–1,8xxm" style: millions, rounded down / up to the hundred. */
export function rangeText([low, high]: [number, number]): string {
    const m = (kg: number) => Math.round(kg / 1_000_000);
    return `${m(low).toLocaleString('en-US')}–${m(high).toLocaleString('en-US')}m`;
}

/** The plan as lines for fleet chat. */
export function planText(plan: TRollPlan, holeLabel: string): string {
    const head = `${plan.goal === 'crit' ? 'Make it crit' : plan.goal === 'reduced' ? 'Make it reduced' : 'Roll it'}: ${holeLabel} (${plan.crew})`;
    const lines = plan.steps.map((step, index) => {
        if (step.kind === 'trip') {
            return `${index + 1}. ${step.ship.name} (${step.ship.ship}) out ${step.out.label} ${massText(step.out.mass)}, home ${step.back.label} ${massText(step.back.mass)} → ${rangeText(step.left)} left${step.mayCollapse ? ' (may collapse on the way home)' : ''}`;
        }
        return `>> ${step.text}`;
    });
    return [head, ...lines].join('\n');
}

/** "1,840m", "26.5m", "1.07m": small masses keep their decimals (HIC threading). */
export function massText(kg: number): string {
    const m = kg / 1_000_000;
    if (m < 10) return `${m.toFixed(2)}m`;
    if (m < 100) return `${m.toFixed(1)}m`;
    return `${Math.round(m).toLocaleString('en-US')}m`;
}
