import { computeBandLayout, HUB_FIRST_LANE, HUB_LANE_STEP, type BandLayoutNode } from '@/map/core/layout/bandLayout';
import { computeTreeEdgeGeometries } from '@/map/core/geometry/treeRouting';
import { nodeRect } from '@/map/core/coords';
import type { EdgeGeometry } from '@/map/core/types';
import { describe, expect, it } from 'vitest';

const OPTIONS = { levelGap: 250, rowGap: 85, homeTopRows: 2, nodeWidth: 180, homeLayout: true };

function layout(nodes: BandLayoutNode[], edges: [number, number][], homeId: number | null = 1, options = OPTIONS) {
    return computeBandLayout({ nodes, edges: edges.map(([from, to]) => ({ from, to })), homeId, reservedAlias: null }, options);
}

describe('patch 22: the home layout', () => {
    // Daisy (1) with Alpha (2), Bravo (3), Delta (4), Golf (5).
    const daisy = layout([{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }, { id: 5 }], [[1, 2], [1, 3], [1, 4], [1, 5]]);
    const at = (id: number) => daisy.positions.get(id)!;

    it("home's holes alternate up and down, starting up", () => {
        expect(at(2).y).toBeLessThan(at(1).y);
        expect(at(3).y).toBeGreaterThan(at(1).y);
        expect(at(4).y).toBeLessThan(at(2).y);
        expect(at(5).y).toBeGreaterThan(at(3).y);
    });

    it('the nearest hole takes the right-hand lane, each further hole one lane left', () => {
        expect(at(2).x - at(1).x).toBe(HUB_FIRST_LANE);
        expect(at(3).x - at(1).x).toBe(HUB_FIRST_LANE);
        expect(at(4).x - at(1).x).toBe(HUB_FIRST_LANE - HUB_LANE_STEP);
        expect(at(5).x - at(1).x).toBe(HUB_FIRST_LANE - HUB_LANE_STEP);
    });

    it('every hole of a hub has a straight lane 15 in from its left edge', () => {
        for (const id of [2, 3, 4, 5]) {
            expect(daisy.hubLanes.get(id)).toEqual({ hubId: 1, x: at(id).x - 40 + 15 });
        }
        expect(daisy.anchorId).toBe(1);
    });

    it('beyond the lanes, chains are laid out as before (the parent centred on its children)', () => {
        // Alpha (2) with its chain A0 (6) and two more holes (7, 8).
        const grown = layout(
            [{ id: 1 }, { id: 2 }, { id: 6 }, { id: 7 }, { id: 8 }],
            [[1, 2], [2, 6], [2, 7], [2, 8]],
        );
        const p = (id: number) => grown.positions.get(id)!;
        expect(p(6).x - p(2).x).toBe(250);
        expect(p(6).y).toBeLessThan(p(2).y);
        expect(p(7).y).toBe(p(2).y);
        expect(p(8).y).toBeGreaterThan(p(2).y);
        expect(p(8).y - p(7).y).toBe(85);
    });

    it('beyond the lanes, no empty spare rows: a system sits in the middle of the holes it has (patch 22c)', () => {
        // Alpha (2) with A4 (6), which keeps 3 rows but has only two holes (7, 8).
        const grown = layout(
            [{ id: 1 }, { id: 2, reserve: 3 }, { id: 6, reserve: 3 }, { id: 7, placeholder: true }, { id: 8, placeholder: true }],
            [[1, 2], [2, 6], [6, 7], [6, 8]],
        );
        const p = (id: number) => grown.positions.get(id)!;
        expect(p(6).y).toBe(p(2).y);
        expect(p(6).y).toBeGreaterThan(p(7).y);
        expect(p(6).y).toBeLessThan(p(8).y);
        expect(p(8).y - p(7).y).toBe(85);
    });

    describe('patch 22d: boxes slide toward where they came from', () => {
        // Alpha (2) above with A3 (12) and A4 (13) hanging below Daisy's row; Delta (3) below with
        // D0 (20, its hole D00 30) and D2 (21, holes 50 and 51).
        const nodes: BandLayoutNode[] = [1, 2, 3, 12, 13, 20, 21, 30].map((id) => ({ id }));
        for (const id of [43, 44, 45, 46, 47, 48, 50, 51]) nodes.push({ id, placeholder: true });
        const edges: [number, number][] = [[1, 2], [1, 3], [2, 12], [2, 13], [12, 43], [12, 44], [12, 45], [12, 46], [13, 47], [13, 48], [3, 20], [3, 21], [20, 30], [21, 50], [21, 51]];
        const slid = layout(nodes, edges);
        const p = (id: number) => slid.positions.get(id)!;

        it("Delta comes up to the hub gap, as close as Alpha is above, while its holes stay clear of Alpha's chain", () => {
            expect(p(3).y - p(1).y).toBe(p(1).y - p(2).y);
            expect(p(30).y).toBeGreaterThan(p(48).y);
        });

        it('a system with holes follows its parent, but never more than one row past its first hole', () => {
            expect(p(20).y).toBe(p(3).y);
            expect(p(21).y).toBeGreaterThanOrEqual(p(50).y - 85);
        });

        it('D4 moves up off the middle of its holes, never more than one row past the first', () => {
            // Delta (3): D0 (20) with four holes, then D4 (22) with three holes packed below them.
            const kids: [number, number][] = [70, 71, 72, 73].map((id) => [20, id] as [number, number]).concat([80, 81, 82].map((id) => [22, id] as [number, number]));
            const far = layout(
                [1, 2, 3, 20, 22, 70, 71, 72, 73, 80, 81, 82].map((id) => ({ id })),
                [[1, 2], [1, 3], [3, 20], [3, 22], ...kids],
            );
            const q = (id: number) => far.positions.get(id)!;
            expect(q(22).y).toBeLessThan(q(81).y);
            expect(q(22).y).toBeGreaterThanOrEqual(q(80).y - 85);
            expect(q(80).y).toBe(q(73).y + 85);
        });

        it('a box stops at the box above it in its column', () => {
            expect(p(21).y - p(20).y).toBeGreaterThanOrEqual(85);
        });
    });

    it("nothing overlaps: the next hole up sits beyond the previous hole's whole tree", () => {
        const grown = layout(
            [{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }, { id: 6 }, { id: 7 }],
            [[1, 2], [1, 3], [1, 4], [2, 6], [2, 7]],
        );
        const p = (id: number) => grown.positions.get(id)!;
        // Delta (4) is home's second hole up: above Alpha's extra hole (7).
        expect(p(4).y).toBeLessThan(p(7).y);
    });

    it('home keeps its spot while the rows above it are enough', () => {
        const alone = layout([{ id: 1 }], []);
        const one = layout([{ id: 1 }, { id: 2 }], [[1, 2]]);
        expect(one.positions.get(1)).toEqual(alone.positions.get(1));
    });

    it('the first system of a side chain is a hub too', () => {
        const result = layout(
            [{ id: 1 }, { id: 10, alias: 'A0' }, { id: 11, alias: 'A00' }, { id: 12, alias: 'A01' }],
            [[10, 11], [10, 12]],
        );
        expect(result.sideChains[0].rootId).toBe(10);
        expect(result.hubLanes.get(11)?.hubId).toBe(10);
        expect(result.hubLanes.get(12)?.hubId).toBe(10);
    });

    it('a pinned system in a chain is a hub and pushes the rest of the chain away', () => {
        const result = layout(
            [{ id: 1 }, { id: 2 }, { id: 3, pinned: true }, { id: 4 }, { id: 5 }, { id: 6 }],
            [[1, 2], [2, 3], [2, 6], [3, 4], [3, 5]],
        );
        expect(result.hubLanes.get(4)?.hubId).toBe(3);
        expect(result.hubLanes.get(5)?.hubId).toBe(3);
        const p = (id: number) => result.positions.get(id)!;
        // Alpha's other hole (6) sits clear of the pinned system's lanes down.
        expect(p(6).y).toBeGreaterThan(p(5).y);
    });

    it('switched off: the old layout, no lanes', () => {
        const old = layout([{ id: 1 }, { id: 2 }, { id: 3 }], [[1, 2], [1, 3]], 1, { ...OPTIONS, homeLayout: false });
        expect(old.hubLanes.size).toBe(0);
        expect(old.anchorId).toBe(null);
        expect(old.positions.get(2)!.x - old.positions.get(1)!.x).toBe(250);
    });
});

describe('patch 22: hub lanes are straight pipes', () => {
    it('runs from the middle of the hub straight to the hole, the pill by the hole', () => {
        const hub = nodeRect({ x: 100, y: 300 }, { width: 180, height: 56 });
        const hole = nodeRect({ x: 235, y: 175 }, { width: 180, height: 50 });
        const rects = new Map([
            [1, hub],
            [2, hole],
        ]);
        const geometry = computeTreeEdgeGeometries([{ id: 9, sourceId: 2, targetId: 1, lane: { x: 210, hubId: 1 } }], rects, new Map()).get(9) as Extract<
            EdgeGeometry,
            { kind: 'elbow' }
        >;
        expect(geometry.from).toEqual({ x: 210, y: hole.maxY });
        expect(geometry.to).toEqual({ x: 210, y: hub.centerY });
        expect(geometry.pillAt).toBe('from');
    });
});
