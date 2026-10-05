import { FLEET_RULES } from '@/lib/fleetRules';
import { condenseRoute, holdFacts, routeSummary, scanPlan, type TRouteHole, type TRouteHop, type TRouteStep } from '@/lib/routeSummary';
import { describe, expect, it } from 'vitest';

const step = (name: string, depth: number | null, extra: Partial<TRouteStep> = {}): TRouteStep => ({ name, mapped: depth !== null, depth, rage: false, ...extra });
const hole = (extra: Partial<TRouteHole> = {}): TRouteHole => ({
    typeName: 'D845',
    totalMass: 5_000_000_000,
    maxJumpMass: 375_000_000,
    jumpedMass: 0,
    massStatus: 'fresh',
    lifetimeStatus: 'healthy',
    eolSince: null,
    nearSignature: null,
    ...extra,
});
const wh = (extra: Partial<TRouteHole> = {}): TRouteHop => ({ via: 'wormhole', hole: hole(extra) });
const gate: TRouteHop = { via: 'stargate', hole: null };
const now = new Date('2026-10-05T12:00:00Z');

describe('patch 26: the route line', () => {
    it('down your own chain: only the deepest point', () => {
        const steps = [step('Daisy', 0), step('Alpha', 1), step('A1', 2), step('A11', 3), step('A112-11', 4)];
        expect(condenseRoute(steps, [wh(), wh(), wh(), wh()])).toBe('Daisy → A112-11');
    });

    it('out to k-space: the way out with its sig, the gate entry and the destination', () => {
        const steps = [step('Daisy', 0), step('Alpha', 1), step('A121', 2), step('Niarja', null), step('Ahbazon', null), step('Amarr', null)];
        expect(condenseRoute(steps, [wh(), wh(), wh({ nearSignature: 'ABC-123' }), gate, gate])).toBe('Daisy → A121 (ABC) → Niarja → Amarr');
    });

    it('coming home: the * way-back bookmarks where it matters', () => {
        const steps = [step('Amarr', null), step('Badivefi', null), step('1112', 4), step('111', 3), step('11', 2), step('A121-1', 1), step('Alpha', 0)];
        expect(condenseRoute(steps, [gate, wh(), wh(), wh(), wh(), wh()])).toBe('Amarr → Badivefi → *1112 → *Alpha');
    });

    it('over the top of the chain: the turning point is written down', () => {
        const steps = [step('A12', 3), step('A1', 2), step('Alpha', 1), step('Daisy', 0), step('Bravo', 1), step('B1', 2)];
        expect(condenseRoute(steps, [wh(), wh(), wh(), wh(), wh()])).toBe('A12 → *Daisy → B1');
    });

    it('rage chains: only the start and the last return bookmark', () => {
        const steps = [step('Daisy', 0), step('1', 1, { rage: true }), step('11', 2, { rage: true }), step('111', 3, { rage: true }), step('Jita', null)];
        expect(condenseRoute(steps, [wh(), wh(), wh(), wh()])).toBe('Daisy → 1 → 111 → Jita');
    });
});

describe('patch 26: size, mass, chokepoints and risks', () => {
    const steps = [step('Daisy', 0), step('Alpha', 1), step('A12', 2), step('A121', 3)];

    it('size from the smallest hole, mass from the tightest, counted cold with plates on', () => {
        const text = routeSummary({ steps, hops: [wh(), wh({ totalMass: 3_000_000_000, typeName: 'B274' }), wh()], roundTrip: false, now });
        expect(text).toContain('Battleship sized');
        expect(text).toContain(`≈ ${Math.floor((3_000_000_000 * 0.9) / FLEET_RULES.shipMass.battleship)} BS`);
        expect(text).toContain('Choke: Alpha↔A12 "B274"');
    });

    it('in and back out halves the mass', () => {
        const oneWay = routeSummary({ steps, hops: [wh(), wh(), wh()], roundTrip: false, now });
        const both = routeSummary({ steps, hops: [wh(), wh(), wh()], roundTrip: true, now });
        expect(both).toContain('in+out');
        expect(oneWay).not.toContain('in+out');
    });

    it('a medium hole on the way: no battleship count', () => {
        const text = routeSummary({ steps, hops: [wh(), wh({ maxJumpMass: 62_000_000, totalMass: 1_000_000_000 }), wh()], roundTrip: false, now });
        expect(text).toContain('Medium sized');
        expect(text).not.toContain(' BS');
    });

    it('EOL: the worst case time left from when it was marked', () => {
        const text = routeSummary({ steps, hops: [wh({ lifetimeStatus: 'eol', eolSince: '2026-10-05T08:40:00Z' }), wh(), wh()], roundTrip: false, now });
        expect(text).toContain('Risk: Daisy↔Alpha EOL, worst case ≤ 40m left (marked 3h20m ago): scout it');
    });

    it('never longer than the chat limit, and the route always stays', () => {
        const long = Array.from({ length: 120 }, (_, index) => step(`Very-Long-Name-${index}`, null));
        const text = routeSummary({ steps: long, hops: long.slice(1).map(() => gate), roundTrip: false, now });
        expect(text.length).toBeLessThanOrEqual(FLEET_RULES.maxCopyLength);
        expect(text.startsWith('Very-Long-Name-0')).toBe(true);
    });
});

describe('patch 26: experimental predictions', () => {
    const steps = [step('Daisy', 0), step('Alpha', 1), step('A12', 2)];

    it('Will it hold? gives facts for the risky holes', () => {
        const text = holdFacts({ steps, hops: [wh(), wh({ massStatus: 'critical', totalMass: 3_000_000_000, maxJumpMass: 2_000_000_000 })], roundTrip: false, now });
        expect(text).toContain('Will it hold? (experimental, one way)');
        expect(text).toContain('Alpha↔A12 "D845": critical:');
        expect(text).toContain('BS worst case');
    });

    it('Will it hold? says so when nothing is risky', () => {
        expect(holdFacts({ steps: [step('Daisy', 0), step('Jita', null)], hops: [gate], roundTrip: false, now })).toContain('Nothing risky known');
    });

    it('Scan plan: up to three, with why', () => {
        const text = scanPlan(
            [
                { name: 'A12', unscannedSigs: 3, unfoundStatics: [] },
                { name: 'A1', unscannedSigs: 0, unfoundStatics: ['c3'] },
                { name: 'Alpha', unscannedSigs: 0, unfoundStatics: [] },
                { name: 'Daisy', unscannedSigs: 2, unfoundStatics: [] },
                { name: 'X', unscannedSigs: 1, unfoundStatics: [] },
            ],
            true,
        );
        expect(text).toBe('No backup route. Scan (experimental): A12 (3 unscanned sigs), A1 (C3 static not found), Daisy (2 unscanned sigs)');
    });
});
