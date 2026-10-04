import type { Vec2 } from '../types';

/**
 * Patch 21: where each pipe's pill goes.
 *
 * Every pipe gets one pill on a straight stretch, kept clear of the stretch's
 * bends and of every system box. The last stretch before the system further
 * right comes first, then the pipe's longer stretches. Pills also keep off each
 * other: one that would land on another slides along its stretch to a free spot,
 * and if no stretch has room it shrinks to a dot (still clickable). All in
 * screen pixels, since pills are drawn at a fixed size whatever the zoom.
 */

export type PillBox = { minX: number; minY: number; maxX: number; maxY: number };

export type PillRequest = {
    id: number;
    /** The pipe's corner points, in screen pixels. */
    points: Vec2[];
    width: number;
    height: number;
};

export type PillSpot = { x: number; y: number; dot: boolean };

/** Clear room between a pill and the bend (or end) of its stretch. */
export const PILL_BEND_CLEAR = 6;
/** Clear room between a pill and a system box, or another pill. */
export const PILL_CLEAR = 4;
/** A pill that has no room shrinks to a dot this big. */
export const PILL_DOT = 12;
const STEP = 6;

type Stretch = { a: Vec2; b: Vec2; length: number; horizontal: boolean };

/**
 * The pipe's real corners: repeated points dropped, and points in the middle of a
 * straight run too (a straight pipe still carries its unused bend point, which
 * would otherwise split one long stretch into two short halves).
 */
function cornersOf(points: Vec2[]): Vec2[] {
    const pts = points.filter((point, i) => i === 0 || Math.hypot(point.x - points[i - 1].x, point.y - points[i - 1].y) > 0.01);
    return pts.filter((point, i) => {
        if (i === 0 || i === pts.length - 1) return true;
        const before = pts[i - 1];
        const after = pts[i + 1];
        const cross = (point.x - before.x) * (after.y - point.y) - (point.y - before.y) * (after.x - point.x);
        const dot = (point.x - before.x) * (after.x - point.x) + (point.y - before.y) * (after.y - point.y);
        return Math.abs(cross) > 0.01 || dot < 0;
    });
}

function stretchesOf(points: Vec2[]): Stretch[] {
    const pts = cornersOf(points);
    if (pts.length < 2) return [];
    const all: Stretch[] = [];
    for (let i = 1; i < pts.length; i++) {
        const a = pts[i - 1];
        const b = pts[i];
        all.push({ a, b, length: Math.hypot(b.x - a.x, b.y - a.y), horizontal: Math.abs(b.x - a.x) >= Math.abs(b.y - a.y) });
    }
    // The far stretch (at the end further right) first, then the rest longest first.
    const farAtEnd = pts[pts.length - 1].x >= pts[0].x;
    const far = farAtEnd ? all[all.length - 1] : all[0];
    return [far, ...all.filter((stretch) => stretch !== far).sort((x, y) => y.length - x.length)];
}

function overlaps(a: PillBox, b: PillBox, clear: number): boolean {
    return a.minX < b.maxX + clear && a.maxX + clear > b.minX && a.minY < b.maxY + clear && a.maxY + clear > b.minY;
}

/** Candidate centres along a stretch, middle first then stepping outwards, for a pill this big. */
function candidates(stretch: Stretch, width: number, height: number): Vec2[] {
    const halfAlong = stretch.horizontal ? width / 2 : height / 2;
    const start = PILL_BEND_CLEAR + halfAlong;
    const end = stretch.length - PILL_BEND_CLEAR - halfAlong;
    if (end < start) return [];
    const middle = (start + end) / 2;
    const at = (distance: number): Vec2 => ({
        x: stretch.a.x + ((stretch.b.x - stretch.a.x) * distance) / stretch.length,
        y: stretch.a.y + ((stretch.b.y - stretch.a.y) * distance) / stretch.length,
    });
    const result = [at(middle)];
    for (let offset = STEP; middle - offset >= start || middle + offset <= end; offset += STEP) {
        if (middle + offset <= end) result.push(at(middle + offset));
        if (middle - offset >= start) result.push(at(middle - offset));
    }
    return result;
}

const boxAt = (center: Vec2, width: number, height: number): PillBox => ({
    minX: center.x - width / 2,
    maxX: center.x + width / 2,
    minY: center.y - height / 2,
    maxY: center.y + height / 2,
});

export function placePills(requests: readonly PillRequest[], obstacles: readonly PillBox[]): Map<number, PillSpot> {
    const spots = new Map<number, PillSpot>();
    const placed: PillBox[] = [];
    const prepared = requests
        .map((request) => ({ request, stretches: stretchesOf(request.points) }))
        .filter((entry) => entry.stretches.length > 0)
        // The most cramped pipes pick first, so a pipe with room to spare makes way.
        .sort((x, y) => x.stretches[0].length - y.stretches[0].length || x.request.id - y.request.id);

    for (const { request, stretches } of prepared) {
        const xs = request.points.map((point) => point.x);
        const ys = request.points.map((point) => point.y);
        const reach = Math.max(request.width, request.height) + PILL_CLEAR;
        const near = obstacles.filter(
            (box) => box.maxX > Math.min(...xs) - reach && box.minX < Math.max(...xs) + reach && box.maxY > Math.min(...ys) - reach && box.minY < Math.max(...ys) + reach,
        );
        const free = (box: PillBox) => !near.some((other) => overlaps(box, other, PILL_CLEAR)) && !placed.some((other) => overlaps(box, other, PILL_CLEAR));

        let spot: PillSpot | null = null;
        for (const [width, height, dot] of [
            [request.width, request.height, false],
            [PILL_DOT, PILL_DOT, true],
        ] as const) {
            for (const stretch of stretches) {
                const fit = candidates(stretch, width, height).find((center) => free(boxAt(center, width, height)));
                if (fit) {
                    spot = { ...fit, dot };
                    placed.push(boxAt(fit, width, height));
                    break;
                }
            }
            if (spot) break;
        }
        if (!spot) {
            // Nowhere free: a dot in the middle of the far stretch.
            const far = stretches[0];
            spot = { x: (far.a.x + far.b.x) / 2, y: (far.a.y + far.b.y) / 2, dot: true };
            placed.push(boxAt(spot, PILL_DOT, PILL_DOT));
        }
        spots.set(request.id, spot);
    }
    return spots;
}
