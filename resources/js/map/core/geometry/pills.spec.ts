import { placePills, PILL_BEND_CLEAR, PILL_CLEAR } from '@/map/core/geometry/pills';
import { describe, expect, it } from 'vitest';

const box = (minX: number, minY: number, maxX: number, maxY: number) => ({ minX, minY, maxX, maxY });

describe('patch 21: pills', () => {
    it('sits in the middle of the last straight stretch before the far system', () => {
        const spots = placePills(
            [
                {
                    id: 1,
                    points: [
                        { x: 0, y: 0 },
                        { x: 40, y: 0 },
                        { x: 40, y: 100 },
                        { x: 200, y: 100 },
                    ],
                    width: 40,
                    height: 20,
                },
            ],
            [],
        );
        expect(spots.get(1)).toEqual({ x: 120, y: 100, dot: false });
    });

    it('keeps clear of the bends: a stretch too short for the pill is skipped', () => {
        const spots = placePills(
            [
                {
                    id: 1,
                    points: [
                        { x: 0, y: 0 },
                        { x: 200, y: 0 },
                        { x: 200, y: 30 },
                        { x: 240, y: 30 },
                    ],
                    width: 40,
                    height: 20,
                },
            ],
            [],
        );
        const spot = spots.get(1)!;
        expect(spot.y).toBe(0);
        expect(spot.x - 20).toBeGreaterThanOrEqual(PILL_BEND_CLEAR);
        expect(spot.x + 20).toBeLessThanOrEqual(200 - PILL_BEND_CLEAR);
    });

    it('never lands on a system box', () => {
        const spots = placePills(
            [
                {
                    id: 1,
                    points: [
                        { x: 0, y: 50 },
                        { x: 300, y: 50 },
                    ],
                    width: 40,
                    height: 20,
                },
            ],
            [box(120, 30, 180, 70)],
        );
        const spot = spots.get(1)!;
        expect(spot.x + 20 + PILL_CLEAR <= 120 || spot.x - 20 - PILL_CLEAR >= 180).toBe(true);
    });

    it('two pipes side by side: their pills do not overlap', () => {
        const pipe = (id: number, y: number) => ({
            id,
            points: [
                { x: 0, y },
                { x: 400, y },
            ],
            width: 60,
            height: 20,
        });
        const spots = placePills([pipe(1, 0), pipe(2, 10)], []);
        const a = spots.get(1)!;
        const b = spots.get(2)!;
        expect(Math.abs(a.x - b.x) >= 60 + PILL_CLEAR || Math.abs(a.y - b.y) >= 20 + PILL_CLEAR).toBe(true);
    });

    it('no room anywhere: a dot', () => {
        const spots = placePills(
            [
                {
                    id: 1,
                    points: [
                        { x: 0, y: 0 },
                        { x: 50, y: 0 },
                    ],
                    width: 80,
                    height: 20,
                },
            ],
            [],
        );
        expect(spots.get(1)?.dot).toBe(true);
    });

    it('a straight pipe is one stretch, even with its unused bend point in the middle', () => {
        const spots = placePills(
            [
                {
                    id: 1,
                    points: [
                        { x: 0, y: 0 },
                        { x: 35, y: 0 },
                        { x: 35, y: 0 },
                        { x: 70, y: 0 },
                    ],
                    width: 40,
                    height: 20,
                },
            ],
            [],
        );
        expect(spots.get(1)).toEqual({ x: 35, y: 0, dot: false });
    });
});
