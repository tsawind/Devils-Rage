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

    it('a hole above grows its chain upward, away from home', () => {
        // Alpha (2) with its chain A1 (6) and two more holes (7, 8).
        const grown = layout(
            [{ id: 1 }, { id: 2 }, { id: 6 }, { id: 7 }, { id: 8 }],
            [[1, 2], [2, 6], [2, 7], [2, 8]],
        );
        const p = (id: number) => grown.positions.get(id)!;
        expect(p(6).y).toBe(p(2).y);
        expect(p(6).x - p(2).x).toBe(250);
        expect(p(7).y).toBeLessThan(p(2).y);
        expect(p(8).y).toBeLessThan(p(7).y);
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
        // Alpha's other hole (6) sits clear of the pinned system's lanes up.
        expect(p(6).y).toBeLessThan(p(4).y);
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
