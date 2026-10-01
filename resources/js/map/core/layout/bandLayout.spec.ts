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
        // Patch 13: no ghost for a combat home; Alpha's row is still kept free.
        expect(result.ghosts.map((ghost) => ghost.label)).toEqual(['A']);
        // Lanes hang off Daisy inside the main band, below its systems.
        expect(result.lanes.every((lane) => lane.parentId === 1)).toBe(true);
        expect(result.combatBand).toBe(null);
        expect(p.get(2)!.y).toBeGreaterThan(p.get(8)!.y);
        // Blue started first: its lane is on the left.
        expect(p.get(3)!.x).toBeLessThan(p.get(2)!.x);
        expect(result.lanes.map((lane) => lane.color)).toEqual(['blue', 'red']);
        // Red drops straight down: home, 1, 11; 12 starts a column to the right.
        expect(p.get(4)!.x).toBe(p.get(2)!.x);
        expect(p.get(5)!.x).toBe(p.get(2)!.x);
        // Compact lanes: rows 60 apart.
        expect(p.get(4)!.y - p.get(2)!.y).toBe(60);
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

    it('patch 13: puts a lane under the system it hangs off, that branch at the bottom', () => {
        const nodes = [
            sys(DAISY, 'Daisy'),
            sys(2, 'A'),
            sys(3, 'B'),
            sys(4, 'D'),
            sys(5, 'D1', { color: 'red', home: true }),
            sys(6, '1', { color: 'red' }),
            sys(7, 'A1'),
        ];
        const result = computeBandLayout({
            nodes,
            edges: [
                { from: 1, to: 2 },
                { from: 1, to: 3 },
                { from: 1, to: 4 },
                { from: 4, to: 5 },
                { from: 5, to: 6 },
                { from: 2, to: 7 },
            ],
            homeId: DAISY,
            laneOrder: ['red'],
            compareNodes: byAlias(nodes),
            reservedAlias: 'A',
        });
        const p = result.positions;
        // Delta (leads to Red) sorts below Bravo, though D comes after B anyway; Alpha stays on top.
        expect(p.get(4)!.y).toBeGreaterThan(p.get(3)!.y);
        expect(p.get(4)!.y).toBeGreaterThan(p.get(2)!.y);
        // The lane starts below the main chain's systems, to the right of Delta, inside the main band.
        expect(p.get(5)!.y).toBeGreaterThan(p.get(4)!.y);
        expect(p.get(5)!.x).toBeGreaterThan(p.get(4)!.x);
        expect(result.lanes[0].parentId).toBe(4);
        expect(result.mainBand!.maxY).toBeGreaterThanOrEqual(result.lanes[0].maxY);
    });

    it('patch 13: a combat home found through another combat home still gets its lane', () => {
        const nodes = [
            sys(DAISY, 'Daisy'),
            sys(2, 'A'),
            sys(4, 'D'),
            sys(5, 'D1', { color: 'red', home: true }),
            sys(6, 'X', { color: 'blue', home: true }),
            sys(7, '1', { color: 'blue' }),
        ];
        const result = computeBandLayout({
            nodes,
            edges: [
                { from: 1, to: 2 },
                { from: 1, to: 4 },
                { from: 4, to: 5 },
                { from: 5, to: 6 },
                { from: 6, to: 7 },
            ],
            homeId: DAISY,
            laneOrder: ['red', 'blue'],
            compareNodes: byAlias(nodes),
            reservedAlias: 'A',
        });
        expect(result.lanes.map((lane) => lane.color).sort()).toEqual(['blue', 'red']);
        // Blue hangs under Delta too, beside Red, inside the main band.
        expect(result.positions.get(6)!.y).toBe(result.positions.get(5)!.y);
        expect(result.positions.get(6)!.x).toBeGreaterThan(result.positions.get(5)!.x);
        expect(result.combatBand).toBeNull();
    });

    it('patch 14: a chain being cleaned up (no home) hangs off the converted system', () => {
        const nodes = [
            sys(DAISY, 'Daisy'),
            sys(4, 'D'),
            sys(5, 'D1'),
            sys(8, 'D12'),
            sys(6, '11', { color: 'red' }),
            sys(7, '12', { color: 'red' }),
        ];
        const result = computeBandLayout({
            nodes,
            edges: [
                { from: 1, to: 4 },
                { from: 4, to: 5 },
                { from: 5, to: 8 },
                { from: 8, to: 6 },
                { from: 8, to: 7 },
            ],
            homeId: DAISY,
            laneOrder: ['red'],
            compareNodes: byAlias(nodes),
            reservedAlias: null,
        });
        expect(result.bandOf.get(8)).toBe('main');
        expect(result.bandOf.get(6)).toBe('lane');
        expect(result.parentOf.get(6)).toBe(8);
        // The lane sits under D12, inside the main band; no "not linked" combat area.
        expect(result.positions.get(6)!.y).toBeGreaterThan(result.positions.get(8)!.y);
        expect(result.positions.get(6)!.x).toBeGreaterThan(result.positions.get(8)!.x);
        expect(result.combatBand).toBeNull();
        expect(result.lanes.map((lane) => lane.parentId)).toEqual([8]);
    });

    it('patch 14: a chain being cleaned up knows every member hanging off the band', () => {
        const nodes = [sys(DAISY, 'Daisy'), sys(4, 'D'), sys(5, 'D1'), sys(6, '1', { color: 'red' }), sys(7, '2', { color: 'red' }), sys(8, '11', { color: 'red' })];
        const result = computeBandLayout({
            nodes,
            edges: [
                { from: 1, to: 4 },
                { from: 4, to: 5 },
                { from: 5, to: 6 },
                { from: 5, to: 7 },
                { from: 6, to: 8 },
            ],
            homeId: DAISY,
            laneOrder: ['red'],
            compareNodes: byAlias(nodes),
        });
        expect(result.parentOf.get(6)).toBe(5);
        expect(result.parentOf.get(7)).toBe(5);
        expect(result.parentOf.get(8)).toBe(6);
        for (const id of [6, 7, 8]) expect(result.positions.has(id)).toBe(true);
    });

    it('patch 15: unjumped holes in a lane stack beside their system, the chain keeps going down', () => {
        const nodes = [
            sys(DAISY, 'Daisy'),
            sys(5, 'B', { color: 'red', home: true }),
            sys(6, '1', { color: 'red' }),
            sys(-1, null, { color: 'red', placeholder: true }),
            sys(-2, null, { color: 'red', placeholder: true }),
            sys(-3, null, { color: 'red', placeholder: true }),
            sys(-4, null, { color: 'red', placeholder: true }),
            sys(7, '11', { color: 'red' }),
        ];
        const result = computeBandLayout(
            {
                nodes,
                edges: [
                    { from: 1, to: 5 },
                    { from: 5, to: 6 },
                    { from: 6, to: -1 },
                    { from: 6, to: -2 },
                    { from: 6, to: -3 },
                    { from: 6, to: -4 },
                    { from: 6, to: 7 },
                ],
                homeId: DAISY,
                laneOrder: ['red'],
                compareNodes: byAlias(nodes),
            },
            { laneNodeWidth: 180, laneNodeHeight: 60, laneColumnGap: 300, laneRowGap: 80 },
        );
        const p = result.positions;
        const one = p.get(6)!;
        // Holes beside system 1, stacked down; none takes a new column.
        const holes = [-1, -2, -3, -4].map((id) => p.get(id)!);
        expect(holes.every((point) => point.x === one.x + 190)).toBe(true);
        expect(holes.map((point) => point.y - one.y).toSorted((a, b) => a - b)).toEqual([0, 34, 68, 102]);
        // 11 stays in the same column, below the stack of four holes.
        expect(p.get(7)!.x).toBe(one.x);
        expect(p.get(7)!.y).toBeGreaterThanOrEqual(one.y + 136);
    });

    it('patch 16: an armed hole in a lane sits straight below its system, or as the next branch', () => {
        const nodes = [
            sys(DAISY, 'Daisy'),
            sys(5, 'B', { color: 'red', home: true }),
            sys(6, '1', { color: 'red' }),
            sys(-1, null, { color: 'red', placeholder: true, armed: true }),
            sys(-2, null, { color: 'red', placeholder: true }),
            sys(-3, null, { color: 'red', placeholder: true, armed: true }),
        ];
        const result = computeBandLayout(
            {
                nodes,
                edges: [
                    { from: 1, to: 5 },
                    { from: 5, to: 6 },
                    { from: 6, to: -1 },
                    { from: 6, to: -2 },
                    { from: 5, to: -3 },
                ],
                homeId: DAISY,
                laneOrder: ['red'],
                compareNodes: byAlias(nodes),
            },
            { laneNodeWidth: 180, laneNodeHeight: 60, laneColumnGap: 300, laneRowGap: 80 },
        );
        const p = result.positions;
        const one = p.get(6)!;
        // System 1 has no jumped child: its armed hole is straight below it.
        expect(p.get(-1)!.x).toBe(one.x);
        expect(p.get(-1)!.y).toBeGreaterThan(one.y);
        // The unarmed one still sits beside it.
        expect(p.get(-2)!.x).toBe(one.x + 190);
        // The home already goes on to 1: its armed hole is the next branch to the right.
        expect(p.get(-3)!.x).toBeGreaterThan(one.x);
        expect(p.get(-3)!.x).not.toBe(p.get(5)!.x + 190);
        // The compact boxes beside 1 stay clear of the next column's card.
        expect(p.get(-2)!.x + 100).toBeLessThanOrEqual(p.get(-3)!.x - 10);
    });

    it('patch 13: sorts a branch leading to a lane after its siblings', () => {
        const nodes = [sys(DAISY, 'Daisy'), sys(2, 'A'), sys(3, 'B'), sys(5, 'A1', { color: 'red', home: true })];
        const result = computeBandLayout({
            nodes,
            edges: [
                { from: 1, to: 2 },
                { from: 1, to: 3 },
                { from: 2, to: 5 },
            ],
            homeId: DAISY,
            laneOrder: ['red'],
            compareNodes: byAlias(nodes),
            reservedAlias: 'A',
        });
        // Alpha leads to Red, so it goes below Bravo.
        expect(result.positions.get(2)!.y).toBeGreaterThan(result.positions.get(3)!.y);
    });

    it('patch 13: unlinked chains sit between the main chain and the side chains', () => {
        const nodes = [
            sys(DAISY, 'Daisy'),
            sys(2, 'A'),
            sys(10, null),
            sys(11, '1'),
            sys(30, null, { color: 'green', home: true }),
            sys(31, '1', { color: 'green' }),
        ];
        const result = computeBandLayout({
            nodes,
            edges: [
                { from: 1, to: 2 },
                { from: 10, to: 11 },
                { from: 30, to: 31 },
            ],
            homeId: DAISY,
            laneOrder: ['green'],
            compareNodes: byAlias(nodes),
            reservedAlias: 'A',
        });
        const p = result.positions;
        expect(result.combatBand).not.toBe(null);
        expect(p.get(30)!.y).toBeGreaterThan(p.get(2)!.y);
        expect(p.get(10)!.y).toBeGreaterThan(p.get(31)!.y);
        expect(result.lanes[0].parentId).toBe(null);
    });

    it('patch 13: a lane linked to a side chain sits inside the side chains', () => {
        const nodes = [
            sys(DAISY, 'Daisy'),
            sys(2, 'A'),
            sys(10, null),
            sys(11, '1'),
            sys(12, '11'),
            sys(20, '111', { color: 'blue', home: true }),
            sys(21, '1', { color: 'blue' }),
        ];
        const result = computeBandLayout({
            nodes,
            edges: [
                { from: 1, to: 2 },
                { from: 10, to: 11 },
                { from: 11, to: 12 },
                { from: 12, to: 20 },
                { from: 20, to: 21 },
            ],
            homeId: DAISY,
            laneOrder: ['blue'],
            compareNodes: byAlias(nodes),
            reservedAlias: 'A',
        });
        expect(result.combatBand).toBe(null);
        expect(result.lanes[0].parentId).toBe(12);
        expect(result.positions.get(20)!.y).toBeGreaterThan(result.positions.get(12)!.y);
        expect(result.sideBand!.maxY).toBeGreaterThanOrEqual(result.lanes[0].maxY);
    });
});
