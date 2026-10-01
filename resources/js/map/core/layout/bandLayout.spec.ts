import { computeBandLayout, isLoopEdge, type BandLayoutNode } from '@/map/core/layout/bandLayout';
import { describe, expect, it } from 'vitest';

/** Patch 12: main band on top, side chains below it, combat lanes side by side at the bottom. */

const DAISY = 1;

function sys(id: number, alias: string | null, extra: Partial<BandLayoutNode> = {}): BandLayoutNode {
    return { id, alias, ...extra };
}

const byAlias = (nodes: BandLayoutNode[]) => (a: number, b: number) => {
    const aliasA = nodes.find((node) => node.id === a)?.alias ?? '';
    const aliasB = nodes.find((node) => node.id === b)?.alias ?? '';
    return aliasA.localeCompare(aliasB);
};

describe('computeBandLayout', () => {
    it('lays the main chain out left to right with statics first and rows 100 apart', () => {
        const nodes = [sys(DAISY, 'Daisy'), sys(2, 'A'), sys(3, 'B'), sys(4, 'A1'), sys(5, 'A0')];
        const result = computeBandLayout({
            nodes,
            edges: [
                { from: 1, to: 3 },
                { from: 1, to: 2 },
                { from: 2, to: 4 },
                { from: 2, to: 5 },
            ],
            homeId: DAISY,
            compareNodes: byAlias(nodes),
            reservedAlias: 'A',
        });
        const p = result.positions;
        expect(p.get(2)!.x).toBe(380);
        expect(p.get(4)!.x).toBe(700);
        // A0 above A1, Alpha above Bravo.
        expect(p.get(5)!.y).toBeLessThan(p.get(4)!.y);
        expect(p.get(2)!.y).toBeLessThan(p.get(3)!.y);
        expect(p.get(4)!.y - p.get(5)!.y).toBe(100);
        expect(result.ghosts).toHaveLength(0);
        expect(result.bandOf.get(4)).toBe('main');
    });

    it('keeps a ghost row for Alpha when it is not mapped', () => {
        const nodes = [sys(DAISY, 'Daisy'), sys(3, 'B')];
        const result = computeBandLayout({ nodes, edges: [{ from: 1, to: 3 }], homeId: DAISY, compareNodes: byAlias(nodes), reservedAlias: 'A' });
        expect(result.ghosts).toHaveLength(1);
        expect(result.ghosts[0].label).toBe('A');
        expect(result.ghosts[0].note).toBe('kept free');
        expect(result.ghosts[0].position.y).toBeLessThan(result.positions.get(3)!.y);
    });

    it('moves combat homes to their own lanes, side by side in start order, chains straight down', () => {
        const nodes = [
            sys(DAISY, 'Daisy'),
            sys(2, 'A', { color: 'red', home: true }),
            sys(3, 'B', { color: 'blue', home: true }),
            sys(4, '1', { color: 'red' }),
            sys(5, '11', { color: 'red' }),
            sys(6, '12', { color: 'red' }),
            sys(7, '1', { color: 'blue' }),
            sys(8, 'D'),
        ];
        const result = computeBandLayout({
            nodes,
            edges: [
                { from: 1, to: 2 },
                { from: 1, to: 3 },
                { from: 1, to: 8 },
                { from: 2, to: 4 },
                { from: 4, to: 5 },
                { from: 4, to: 6 },
                { from: 3, to: 7 },
            ],
            homeId: DAISY,
            laneOrder: ['blue', 'red'],
            compareNodes: byAlias(nodes),
            reservedAlias: 'A',
        });
        const p = result.positions;
        // Ghosts hold Alpha's and Bravo's spots in the main band.
        expect(result.ghosts.map((ghost) => ghost.label).sort()).toEqual(['A', 'B']);
        // Lanes below the main band.
        expect(p.get(2)!.y).toBeGreaterThan(p.get(8)!.y);
        // Blue started first: its lane is on the left.
        expect(p.get(3)!.x).toBeLessThan(p.get(2)!.x);
        expect(result.lanes.map((lane) => lane.color)).toEqual(['blue', 'red']);
        // Red drops straight down: home, 1, 11; 12 starts a column to the right.
        expect(p.get(4)!.x).toBe(p.get(2)!.x);
        expect(p.get(5)!.x).toBe(p.get(2)!.x);
        expect(p.get(4)!.y - p.get(2)!.y).toBe(100);
        expect(p.get(6)!.x).toBeGreaterThan(p.get(5)!.x);
        expect(p.get(6)!.y).toBe(p.get(5)!.y);
        // Daisy → Alpha is how Alpha was found, not a loop.
        expect(isLoopEdge(result.parentOf, 1, 2)).toBe(false);
        expect(result.bandOf.get(4)).toBe('lane');
    });

    it('puts chains not linked to Daisy in the side band, each its own block', () => {
        const nodes = [sys(DAISY, 'Daisy'), sys(2, 'A'), sys(10, null), sys(11, '1'), sys(12, '11'), sys(20, 'Z'), sys(21, 'Z1')];
        const result = computeBandLayout({
            nodes,
            edges: [
                { from: 1, to: 2 },
                { from: 10, to: 11 },
                { from: 11, to: 12 },
                { from: 20, to: 21 },
            ],
            homeId: DAISY,
            compareNodes: byAlias(nodes),
            reservedAlias: 'A',
        });
        const p = result.positions;
        expect(result.sideChains.map((chain) => chain.rootId).sort()).toEqual([10, 20]);
        expect(p.get(10)!.y).toBeGreaterThan(p.get(2)!.y);
        expect(p.get(10)!.x).toBe(60);
        expect(p.get(11)!.x).toBe(380);
        expect(Math.abs(p.get(20)!.y - p.get(10)!.y)).toBeGreaterThanOrEqual(100);
        expect(result.bandOf.get(21)).toBe('side');
        expect(result.sideBand).not.toBe(null);
    });

    it('keeps systems where they were first found and marks the other connections as loops', () => {
        const nodes = [
            sys(DAISY, 'Daisy'),
            sys(2, 'A', { color: 'red', home: true }),
            sys(3, '1', { color: 'red' }),
            sys(4, '11', { color: 'red' }),
            sys(5, 'B'),
            sys(6, 'B1'),
        ];
        const result = computeBandLayout({
            nodes,
            edges: [
                { from: 1, to: 2 },
                { from: 2, to: 3 },
                { from: 3, to: 4 },
                { from: 1, to: 5 },
                { from: 5, to: 6 },
                // Red 11 has a hole into B1.
                { from: 4, to: 6 },
            ],
            homeId: DAISY,
            laneOrder: ['red'],
            compareNodes: byAlias(nodes),
            reservedAlias: 'A',
        });
        expect(result.bandOf.get(6)).toBe('main');
        expect(result.bandOf.get(4)).toBe('lane');
        expect(isLoopEdge(result.parentOf, 4, 6)).toBe(true);
        expect(isLoopEdge(result.parentOf, 3, 4)).toBe(false);
    });

    it('gives an unlinked combat home its own lane', () => {
        const nodes = [sys(DAISY, 'Daisy'), sys(2, 'A'), sys(30, null, { color: 'green', home: true }), sys(31, '1', { color: 'green' })];
        const result = computeBandLayout({
            nodes,
            edges: [
                { from: 1, to: 2 },
                { from: 30, to: 31 },
            ],
            homeId: DAISY,
            laneOrder: ['green'],
            compareNodes: byAlias(nodes),
            reservedAlias: 'A',
        });
        expect(result.lanes).toHaveLength(1);
        expect(result.lanes[0].homeId).toBe(30);
        expect(result.bandOf.get(30)).toBe('lane');
        expect(result.positions.get(31)!.x).toBe(result.positions.get(30)!.x);
        expect(result.sideChains).toHaveLength(0);
    });

    it('sorts placeholders after real systems so the chain runs down through real ones', () => {
        const nodes = [
            sys(DAISY, 'Daisy'),
            sys(2, 'A', { color: 'red', home: true }),
            sys(-5, null, { color: 'red', placeholder: true }),
            sys(3, '1', { color: 'red' }),
        ];
        const result = computeBandLayout({
            nodes,
            edges: [
                { from: 1, to: 2 },
                { from: 2, to: -5 },
                { from: 2, to: 3 },
            ],
            homeId: DAISY,
            laneOrder: ['red'],
            compareNodes: byAlias(nodes),
            reservedAlias: 'A',
        });
        expect(result.positions.get(3)!.x).toBe(result.positions.get(2)!.x);
        expect(result.positions.get(-5)!.x).toBeGreaterThan(result.positions.get(2)!.x);
    });
});
