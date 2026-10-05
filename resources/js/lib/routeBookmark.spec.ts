import { estimateMass } from '@/lib/massEstimate';
import { routeBookmark, type TRouteHole } from '@/lib/routeBookmark';
import { describe, expect, it } from 'vitest';

// Daisy (1) → Alpha (2) → A1 (3) → A12 (4) → A121 (5) → Jita-ish high-sec (6) → Amarr (7, by gate)
const names: Record<number, string> = { 1: 'Daisy', 2: 'Alpha', 3: 'A1', 4: 'A12', 5: 'A121', 6: 'Niarja', 7: 'Amarr' };
const classes: Record<number, string> = { 1: 'c5', 2: 'c6', 3: 'c3', 4: 'c3', 5: 'c2', 6: 'hs', 7: 'hs' };
const mapped = new Set([1, 2, 3, 4, 5]);
const fresh = (total: number) => estimateMass({ totalMass: total, jumped: 0, status: 'fresh' });
const holes: Record<string, TRouteHole> = {
    '1-2': { typeName: 'V753', estimate: fresh(3_300_000_000), nearSignature: 'QRH-254', nearIsStatic: true },
    '2-3': { typeName: 'D845', estimate: fresh(5_000_000_000), nearSignature: 'AAA-111', nearIsStatic: false },
    '3-4': { typeName: 'D845', estimate: fresh(5_000_000_000), nearSignature: 'BBB-222', nearIsStatic: false },
    '4-5': { typeName: 'B274', estimate: fresh(2_000_000_000), nearSignature: 'CCC-333', nearIsStatic: false },
    '5-6': { typeName: 'D845', estimate: fresh(5_000_000_000), nearSignature: 'ABC-456', nearIsStatic: true },
};
const input = (steps: number[]) => ({
    steps,
    nameOf: (id: number) => names[id],
    isMapped: (id: number) => mapped.has(id),
    classOf: (id: number) => classes[id] ?? null,
    holeBetween: (from: number, to: number) => holes[`${from}-${to}`] ?? null,
});

describe('patch 25: the route bookmark to a character', () => {
    it('out of the chain into k-space: the way out, where they are, the mass and the chokepoint', () => {
        expect(routeBookmark(input([1, 2, 3, 4, 5, 6, 7]))).toBe(
            'Daisy → … → A121 ABC HSs → Amarr (Mass available: 1,800m kg ↔ 2,200m kg) Chokepoint - A12↔A121 "B274" 2b kg+-',
        );
    });

    it('somewhere on the map: origin → … → where they are', () => {
        expect(routeBookmark(input([1, 2, 3, 4]))).toMatch(/^Daisy → … → A12 \(Mass available/);
    });

    it('a short way is written out in full', () => {
        expect(routeBookmark(input([1, 2, 3]))).toMatch(/^Daisy → Alpha → A1 \(/);
    });

    it('at the origin itself: just its name', () => {
        expect(routeBookmark(input([1]))).toBe('Daisy');
    });

    it('by gate only: no mass line', () => {
        expect(routeBookmark(input([6, 7]))).toBe('Niarja → Amarr');
    });
});
