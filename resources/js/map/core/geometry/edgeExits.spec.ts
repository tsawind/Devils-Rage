import { nodeRect } from '@/map/core/coords';
import { computeBandLayout } from '@/map/core/layout/bandLayout';
import { farStretchPoint } from '@/map/core/geometry/paths';
import { computeTreeEdgeGeometries } from '@/map/core/geometry/treeRouting';
import type { EdgeGeometry, Rect, Vec2 } from '@/map/core/types';
import { describe, expect, it } from 'vitest';

const SIZE = { width: 180, height: 40 };
const rects = (anchors: Record<number, Vec2>): Map<number, Rect> => new Map(Object.entries(anchors).map(([id, anchor]) => [Number(id), nodeRect(anchor, SIZE)]));
const elbow = (geometry: EdgeGeometry | undefined) => {
    if (!geometry || geometry.kind !== 'elbow') throw new Error('expected an elbow');
    return geometry;
};

describe('patch 20: pipes leave out of the top and bottom', () => {
    // A0 at (100, 200); A01 two rows up and A03 level, A04 two rows down, one column right.
    const anchors = { 1: { x: 100, y: 200 }, 2: { x: 350, y: 30 }, 3: { x: 350, y: 200 }, 4: { x: 350, y: 370 } };

    it('a system above: out of the top near the right corner, straight to it', () => {
        const geometries = computeTreeEdgeGeometries([{ id: 10, sourceId: 1, targetId: 2 }], rects(anchors), new Map(Object.entries(anchors).map(([k, v]) => [Number(k), v])));
        const g = elbow(geometries.get(10));
        const box = nodeRect(anchors[1], SIZE);
        expect(g.start?.point.y).toBe(box.minY);
        expect(g.start?.point.x).toBeGreaterThan(box.centerX);
        expect(g.start?.normal).toEqual({ x: 0, y: -1 });
        expect(g.from.y).toBe(nodeRect(anchors[2], SIZE).centerY);
    });

    it('a level system: straight out of the right side', () => {
        const geometries = computeTreeEdgeGeometries([{ id: 11, sourceId: 1, targetId: 3 }], rects(anchors), new Map());
        expect(elbow(geometries.get(11)).start ?? null).toBe(null);
    });

    it('a system below: out of the bottom', () => {
        const geometries = computeTreeEdgeGeometries([{ id: 12, sourceId: 1, targetId: 4 }], rects(anchors), new Map());
        expect(elbow(geometries.get(12)).start?.normal).toEqual({ x: 0, y: 1 });
    });

    it('works the same when the connection is stored the other way round', () => {
        const geometries = computeTreeEdgeGeometries([{ id: 13, sourceId: 2, targetId: 1 }], rects(anchors), new Map());
        const g = elbow(geometries.get(13));
        expect(g.end?.normal).toEqual({ x: 0, y: -1 });
        expect(g.start ?? null).toBe(null);
    });

    it('a box right underneath: steps into the gap instead of cutting through it', () => {
        const withBelow = { ...anchors, 5: { x: 100, y: 285 } };
        const geometries = computeTreeEdgeGeometries([{ id: 14, sourceId: 1, targetId: 4 }], rects(withBelow), new Map());
        const g = elbow(geometries.get(14));
        const box = nodeRect(anchors[1], SIZE);
        const below = nodeRect(withBelow[5], SIZE);
        expect(g.start?.normal).toEqual({ x: 0, y: 1 });
        expect(g.from.y).toBeGreaterThan(box.maxY);
        expect(g.from.y).toBeLessThan(below.minY);
    });

    it('patch 21: thick pipes out of one edge keep apart by their widths', () => {
        const up = { 1: { x: 100, y: 400 }, 2: { x: 350, y: 30 }, 3: { x: 350, y: 115 }, 4: { x: 350, y: 200 } };
        const geometries = computeTreeEdgeGeometries(
            [
                { id: 20, sourceId: 1, targetId: 2, width: 30 },
                { id: 21, sourceId: 1, targetId: 3, width: 30 },
                { id: 22, sourceId: 1, targetId: 4, width: 30 },
            ],
            rects(up),
            new Map(),
        );
        const xs = [20, 21, 22].map((id) => elbow(geometries.get(id)).start?.point.x).filter((x): x is number => x !== undefined).sort((a, b) => a - b);
        expect(xs.length).toBeGreaterThanOrEqual(2);
        for (let i = 1; i < xs.length; i++) expect(xs[i] - xs[i - 1]).toBeGreaterThanOrEqual(34);
        // The nearest system's pipe still leaves closest to the right corner.
        expect(elbow(geometries.get(22)).start?.point.x).toBe(Math.max(...xs));
    });

    it('patch 21: the bottom edge keeps clear of the way-back pill', () => {
        const down = { 1: { x: 100, y: 0 }, ...Object.fromEntries([1, 2, 3, 4, 5, 6].map((i) => [i + 1, { x: 350, y: i * 85 }])) };
        const edges = [1, 2, 3, 4, 5, 6].map((i) => ({ id: 30 + i, sourceId: 1, targetId: i + 1, width: 20 }));
        const geometries = computeTreeEdgeGeometries(edges, rects(down), new Map());
        const box = nodeRect(down[1], SIZE);
        for (const edge of edges) {
            const start = elbow(geometries.get(edge.id)).start;
            if (start) expect(start.point.x - 10).toBeGreaterThanOrEqual(box.minX + 56);
        }
    });

    it('puts the pill on the last stretch before the far system', () => {
        const point = farStretchPoint([
            { x: 0, y: 0 },
            { x: 0, y: 100 },
            { x: 200, y: 100 },
        ]);
        expect(point).toEqual({ x: 164, y: 100 });
    });
});

describe('patch 20: rows kept ahead, home inset', () => {
    it('a system keeps spare rows, so its next hole does not push the next branch down', () => {
        const nodes = [{ id: 1 }, { id: 2, alias: 'A', reserve: 3 }, { id: 3, alias: 'B' }, { id: 20, placeholder: true }];
        const before = computeBandLayout({ nodes, edges: [{ from: 1, to: 2 }, { from: 1, to: 3 }, { from: 2, to: 20 }], homeId: 1, reservedAlias: null });
        const after = computeBandLayout({
            nodes: [...nodes, { id: 21, placeholder: true }],
            edges: [{ from: 1, to: 2 }, { from: 1, to: 3 }, { from: 2, to: 20 }, { from: 2, to: 21 }],
            homeId: 1,
            reservedAlias: null,
        });
        expect(after.positions.get(3)).toEqual(before.positions.get(3));
    });

    it('home starts some rows down', () => {
        const flat = computeBandLayout({ nodes: [{ id: 1 }], edges: [], homeId: 1, reservedAlias: null }, { rowGap: 85 });
        const inset = computeBandLayout({ nodes: [{ id: 1 }], edges: [], homeId: 1, reservedAlias: null }, { rowGap: 85, homeTopRows: 4 });
        expect(inset.positions.get(1)!.y - flat.positions.get(1)!.y).toBe(340);
        expect(inset.positions.get(1)!.x).toBe(flat.positions.get(1)!.x);
    });
});
