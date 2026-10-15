import { holeWindow } from '@/lib/holeWindow';
import { clock, massPlan, massPlanText, POLARIZATION_SECONDS, type TMassPilot } from '@/lib/massPlan';
import { describe, expect, it } from 'vitest';

const M = 1_000_000;
const devoterOptions = [
    { label: 'HOT', mode: 'hot' as const, mass: 132.4 * M },
    { label: 'COLD', mode: 'cold' as const, mass: 32.4 * M },
    { label: 'HOT · 1 entangler', mode: 'hot' as const, mass: 26.48 * M },
    { label: 'COLD · 1 entangler', mode: 'cold' as const, mass: 6.48 * M },
    { label: 'COLD · 3 entanglers', mode: 'cold' as const, mass: 1.07 * M },
];
const devoter = (id: number, name: string, extra: Partial<TMassPilot> = {}): TMassPilot => ({
    id,
    name,
    ship: 'Devoter',
    options: devoterOptions,
    expendable: false,
    lastOut: null,
    lastHome: null,
    canSwap: false,
    ...extra,
});
const crew = [devoter(1, 'Darkness'), devoter(2, 'Delta'), devoter(3, 'William'), devoter(4, 'MyThai')];

describe('patch 37: the hole window from what people saw', () => {
    it('fresh at 1,588.8m and crit made by a 132.4m jump: 185.4–363m left (a 3.3b door)', () => {
        const nominal = 3_300 * M;
        // Crit seen right after the hot jump, with 3,000m logged in all (any P works: the window is about T).
        const window = holeWindow({
            nominal,
            observations: [
                { status: 'fresh', logged: 1_588.8 * M, flip: null },
                { status: 'critical', logged: 3_000 * M, flip: 132.4 * M },
            ],
            loggedNow: 3_000 * M,
        });
        expect(window.consistent).toBe(true);
        expect(window.total[0]).toBeGreaterThanOrEqual(3_177.6 * M);
        expect(window.left[1]).toBeLessThanOrEqual(363 * M + 1);
        expect(window.left[0]).toBeGreaterThan(0);
        expect(window.reasons.length).toBeGreaterThan(1);
    });

    it('says when the log and the marks disagree', () => {
        const window = holeWindow({ nominal: 3_000 * M, observations: [{ status: 'fresh', logged: 2_000 * M, flip: null }], loggedNow: 2_000 * M });
        expect(window.consistent).toBe(false);
    });
});

describe('patch 37: mass plans', () => {
    const crit = { nominal: 3_300 * M, low: 185.36 * M, high: 363 * M, maxJump: 2_000 * M, status: 'critical' as const };

    it('rolling never sends anyone out who could be left outside, one ship out at a time', () => {
        const plan = massPlan('roll', crit, crew);
        let passed = 0;
        for (const step of plan.steps) {
            if (step.kind !== 'jump') continue;
            if (step.direction === 'out') expect(passed + step.option.mass).toBeLessThan(crit.low);
            passed += step.option.mass;
        }
        expect(plan.steps.some((step) => step.kind === 'jump')).toBe(true);
    });

    it('respects polarization: a pilot never goes out twice within 5 minutes', () => {
        const plan = massPlan('crit', { nominal: 3_300 * M, low: 1_485 * M, high: 3_630 * M, maxJump: 2_000 * M, status: 'fresh' }, [devoter(1, 'Solo')]);
        const outs = plan.steps.filter((step) => step.kind === 'jump' && step.direction === 'out').map((step) => step.at);
        for (let index = 1; index < outs.length; index++) expect(outs[index] - outs[index - 1]).toBeGreaterThanOrEqual(POLARIZATION_SECONDS);
    });

    it('juggles: a crew that can swap pilots keeps going with a free pilot', () => {
        const pilots = [devoter(1, 'Darkness', { canSwap: true, lastOut: 10 }), devoter(2, 'Delta', { canSwap: true })];
        const plan = massPlan('crit', { nominal: 3_300 * M, low: 1_485 * M, high: 3_630 * M, maxJump: 2_000 * M, status: 'fresh' }, pilots);
        const first = plan.steps.find((step) => step.kind === 'jump');
        expect(first && first.kind === 'jump' ? first.pilot : null).toBe('Delta');
    });

    it('a closer goes out first and comes home only after the others have it at crit', () => {
        const archon = { id: 9, name: 'TheWrecker', ship: 'Archon', options: [{ label: 'HOT 10000MN AB', mode: 'hot' as const, mass: 1_840 * M }, { label: 'COLD', mode: 'cold' as const, mass: 1_340 * M }], expendable: false, lastOut: null, lastHome: null, canSwap: false };
        const plan = massPlan('closer', { nominal: 3_300 * M, low: 1_485 * M, high: 3_630 * M, maxJump: 2_000 * M, status: 'fresh' }, [archon, ...crew]);
        const first = plan.steps.find((step) => step.kind === 'jump');
        expect(first && first.kind === 'jump' ? [first.pilot, first.direction, first.closer] : null).toEqual(['TheWrecker', 'out', true]);
        // While the closer waits outside, no other jump may close the hole.
        const closerHome = plan.steps.findIndex((step) => step.kind === 'jump' && step.closer && step.direction === 'home');
        const before = closerHome === -1 ? plan.steps : plan.steps.slice(0, closerHome);
        expect(before.every((step) => step.kind !== 'jump' || !step.mayCollapse)).toBe(true);
    });

    it('only gambles with an expendable pilot when hurried', () => {
        const nearlyGone = { nominal: 3_300 * M, low: 0.5 * M, high: 200 * M, maxJump: 2_000 * M, status: 'critical' as const };
        const calm = massPlan('roll', nearlyGone, [devoter(1, 'Darkness', { expendable: true })]);
        expect(calm.steps.some((step) => step.kind === 'jump' && step.gamble)).toBe(false);
        const hurried = massPlan('roll', nearlyGone, [devoter(1, 'Darkness', { expendable: true })], true);
        expect(hurried.steps.some((step) => step.kind === 'jump' && step.gamble)).toBe(true);
    });

    it('writes the plan for fleet chat with times', () => {
        expect(clock(160)).toBe('2:40');
        const text = massPlanText(massPlan('roll', crit, crew), 'Alpha ↔ A5 B520');
        expect(text.split('\n')[0]).toBe('Roll it: Alpha ↔ A5 B520');
        expect(text).toContain('OUT');
    });
});

describe('patch 37: plans start to finish with the big ships', () => {
    const vargur = (id: number, name: string): TMassPilot => ({
        id,
        name,
        ship: 'Vargur',
        hull: 120 * M,
        options: [
            { label: 'HOT 500MN', mode: 'hot', mass: 170 * M },
            { label: 'COLD', mode: 'cold', mass: 120 * M },
        ],
        expendable: false,
        lastOut: null,
        lastHome: null,
        canSwap: false,
    });
    const small = (id: number, name: string, ship: string): TMassPilot => ({
        id,
        name,
        ship,
        hull: 1.3 * M,
        options: [
            { label: 'HOT 1MN AB', mode: 'hot', mass: 1.9 * M },
            { label: 'COLD', mode: 'cold', mass: 1.4 * M },
        ],
        expendable: false,
        lastOut: null,
        lastHome: null,
        canSwap: false,
    });
    const archon: TMassPilot = {
        id: 9,
        name: 'TheWrecker',
        ship: 'Archon',
        hull: 1_123 * M,
        options: [
            { label: 'HOT 50000/10000MN', mode: 'hot', mass: 1_840 * M },
            { label: 'COLD', mode: 'cold', mass: 1_340 * M },
        ],
        expendable: false,
        lastOut: null,
        lastHome: null,
        canSwap: false,
    };
    const fleet = [small(1, 'Luigi', 'Pacifier'), vargur(2, 'Liam'), vargur(3, 'Delta'), small(4, 'Nocnitsa', 'Bifrost'), vargur(5, 'Wrecker'), vargur(6, 'William'), archon];
    const watched = { nominal: 3_300 * M, low: 2_970 * M, high: 3_630 * M, maxJump: 2_000 * M, status: 'fresh' as const, watched: true, logged: 0 };

    it('rolls a watched fresh 3.3b hole with the Archon alone, everyone home', () => {
        const plan = massPlan('roll', watched, fleet);
        const jumps = plan.steps.filter((step) => step.kind === 'jump');
        expect(jumps.length).toBe(2);
        expect(jumps.every((step) => step.kind === 'jump' && step.ship === 'Archon')).toBe(true);
        expect(plan.steps.at(-1)?.kind).toBe('done');
        expect(plan.tips.join(' ')).toContain('smaller than a cruiser');
    });

    it('makes it crit through the half-mass check, never with ships smaller than a cruiser', () => {
        const plan = massPlan('crit', watched, fleet);
        const checks = plan.steps.filter((step) => step.kind === 'check');
        expect(checks.some((step) => step.kind === 'check' && step.outcome === 'reduced')).toBe(true);
        expect(checks.at(-1)?.kind === 'check' && checks.at(-1)?.outcome).toBe('critical');
        expect(plan.steps.some((step) => step.kind === 'jump' && ['Pacifier', 'Bifrost'].includes(step.ship))).toBe(false);
        expect(plan.steps.at(-1)?.kind).toBe('done');
        expect(plan.jumps).toBeLessThanOrEqual(8);
    });

    it('never collapses the hole while making it crit or reduced', () => {
        for (const goal of ['crit', 'reduced'] as const) {
            const plan = massPlan(goal, watched, fleet);
            expect(plan.steps.some((step) => step.kind === 'jump' && step.mayCollapse)).toBe(false);
        }
    });

    it('hurry on an unknown hole: carrier out as heavy as is safe, home hot, then a little more mass, no checks', () => {
        const carrier = { ...archon, options: [...archon.options, { label: 'HOT 500MN', mode: 'hot' as const, mass: 1_390 * M }] };
        const unknown = { nominal: 3_300 * M, low: 1_462 * M, high: 3_607 * M, maxJump: 2_000 * M, status: 'fresh' as const };
        const crewInHurry = [vargur(2, 'Liam'), vargur(3, 'Delta'), carrier].map((pilot) => ({ ...pilot, expendable: pilot.ship !== 'Archon' }));
        const plan = massPlan('roll', unknown, crewInHurry, true);
        const jumps = plan.steps.filter((step) => step.kind === 'jump');
        expect(jumps[0]?.kind === 'jump' && [jumps[0].ship, jumps[0].direction, jumps[0].option.mass]).toEqual(['Archon', 'out', 1_390 * M]);
        expect(jumps[1]?.kind === 'jump' && [jumps[1].ship, jumps[1].direction, jumps[1].option.mass]).toEqual(['Archon', 'home', 1_840 * M]);
        expect(jumps.slice(2).every((step) => step.kind === 'jump' && step.ship === 'Vargur')).toBe(true);
        expect(plan.steps.some((step) => step.kind === 'check')).toBe(false);
        expect(plan.steps.at(-1)?.kind).toBe('done');
    });

    it('suggests a ship to bring when the crew is slow', () => {
        const plan = massPlan('roll', watched, [vargur(2, 'Liam')]);
        expect(plan.tips.some((tip) => tip.startsWith('Bringing'))).toBe(true);
    });
});
