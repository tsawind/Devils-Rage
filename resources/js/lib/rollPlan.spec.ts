import { massText, planText, planWith, rollPlan, type TRollShip } from '@/lib/rollPlan';
import { describe, expect, it } from 'vitest';

const M = 1_000_000;
const mega = (id: number, owner: string, order: number): TRollShip => ({ id, name: `Alt ${id}`, ship: 'Megathron', owner, order, cold: 203 * M, hot: 303 * M });
const hic = (id: number, owner: string, order: number): TRollShip => ({ id, name: `HIC ${id}`, ship: 'Devoter', owner, order, cold: 3 * M, hot: 53 * M });

const fresh = { total: 3_000 * M, low: 1_350 * M, high: 3_300 * M, maxJump: 2_000 * M, status: 'fresh' as const };

describe('patch 37: rolling planner', () => {
    it('never sends a ship out when it could collapse the hole behind it', () => {
        const plan = planWith('roll', fresh, [mega(1, 'A', 1), mega(2, 'A', 2), hic(3, 'A', 3)]);
        let passed = 0;
        for (const step of plan.steps) {
            if (step.kind !== 'trip') continue;
            expect(passed + step.out.mass).toBeLessThan(fresh.low);
            passed += step.out.mass + step.back.mass;
        }
    });

    it('stops at CHECK HOLE STATUS once it has probably gone reduced', () => {
        const plan = planWith('crit', fresh, [mega(1, 'A', 1), mega(2, 'A', 2)]);
        const last = plan.steps.at(-1);
        expect(last?.kind).toBe('check');
        expect(last && last.kind === 'check' ? last.expect : null).toBe('reduced');
        expect(plan.steps.filter((step) => step.kind === 'trip').length).toBeGreaterThan(0);
    });

    it('a reduced hole goes on to crit without collapsing (no jump home may collapse it)', () => {
        const reduced = { total: 3_000 * M, low: 270 * M, high: 1_650 * M, maxJump: 2_000 * M, status: 'reduced' as const };
        const plan = planWith('crit', reduced, [mega(1, 'A', 1), hic(2, 'A', 2)]);
        expect(plan.steps.every((step) => step.kind !== 'trip' || !step.mayCollapse)).toBe(true);
    });

    it('rolling lets the jump home collapse it, never the jump out', () => {
        const crit = { total: 3_000 * M, low: 0.1 * M, high: 330 * M, maxJump: 2_000 * M, status: 'critical' as const };
        const plan = planWith('roll', crit, [mega(1, 'A', 1), hic(2, 'A', 2)]);
        expect(plan.steps.at(-1)?.kind === 'done' || plan.steps.at(-1)?.kind === 'check' || plan.steps.at(-1)?.kind === 'stuck').toBe(true);
    });

    it('prefers one person who can do it alone, the one with fewer characters', () => {
        const ships = [mega(1, 'Big', 1), mega(2, 'Big', 2), mega(3, 'Big', 3), mega(4, 'Small', 1), mega(5, 'Small', 2)];
        const plan = rollPlan('crit', fresh, ships);
        expect(plan.owners.length).toBe(1);
    });

    it('goes in squad order (ISBoxer order)', () => {
        const plan = planWith('crit', fresh, [mega(1, 'A', 1), mega(2, 'A', 2), mega(3, 'A', 3)]);
        const order = plan.steps.flatMap((step) => (step.kind === 'trip' ? [step.ship.id] : []));
        expect(order.slice(0, 2)).toEqual([1, 2]);
    });

    it('is done at once when the hole already shows the goal', () => {
        const plan = planWith('reduced', { ...fresh, status: 'reduced' }, [mega(1, 'A', 1)]);
        expect(plan.steps).toEqual([{ kind: 'done', text: 'It already shows REDUCED.' }]);
    });

    it('writes a plan for fleet chat', () => {
        const text = planText(planWith('crit', fresh, [mega(1, 'A', 1)], "A's characters"), 'Daisy ↔ Bravo V753');
        expect(text.split('\n')[0]).toBe("Make it crit: Daisy ↔ Bravo V753 (A's characters)");
        expect(text).toContain('out HOT');
    });

    it('leaves out ships too big for the hole', () => {
        const plan = planWith('crit', { ...fresh, maxJump: 62 * M }, [mega(1, 'A', 1)]);
        expect(plan.steps[0].kind).toBe('stuck');
    });
});

describe('patch 37: mass options (props, entanglers)', () => {
    const devoter: TRollShip = {
        id: 9,
        name: 'Darkness Holder',
        ship: 'Devoter',
        owner: 'A',
        order: 1,
        cold: 32.4 * M,
        hot: 132.4 * M,
        options: [
            { label: 'HOT 500MN MWD', mode: 'hot', mass: 132.4 * M },
            { label: 'COLD', mode: 'cold', mass: 32.4 * M },
            { label: 'HOT 500MN MWD · 1 entangler', mode: 'hot', mass: 26.48 * M },
            { label: 'COLD · 1 entangler', mode: 'cold', mass: 6.48 * M },
            { label: 'COLD · 3 entanglers', mode: 'cold', mass: 1.07 * M },
        ],
    };

    it('threads a nearly closed hole with the tiny masses', () => {
        const tight = { total: 3_000 * M, low: 10 * M, high: 300 * M, maxJump: 2_000 * M, status: 'critical' as const };
        const plan = planWith('roll', tight, [devoter]);
        const first = plan.steps[0];
        expect(first.kind).toBe('trip');
        if (first.kind === 'trip') {
            expect(first.out.mass).toBeLessThan(10 * M);
            expect(first.out.label).toContain('entangler');
        }
    });

    it('writes small masses with decimals', () => {
        expect(massText(1.07 * M)).toBe('1.07m');
        expect(massText(26.48 * M)).toBe('26.5m');
        expect(massText(1_840 * M)).toBe('1,840m');
    });
});
