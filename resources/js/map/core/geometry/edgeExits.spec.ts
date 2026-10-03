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
