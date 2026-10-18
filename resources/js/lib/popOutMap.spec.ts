import { nearbySystems, viewBoxAround } from '@/lib/popOutMap';
import { describe, expect, it } from 'vitest';

describe('patch 39: the pop-out map', () => {
    const edges = [
        { from: 1, to: 2 },
        { from: 2, to: 3 },
        { from: 3, to: 4 },
        { from: 1, to: 5 },
    ];

    it('shows the systems within two jumps', () => {
        expect([...nearbySystems(2, edges)].sort()).toEqual([1, 2, 3, 4, 5]);
        expect([...nearbySystems(4, edges)].sort()).toEqual([2, 3, 4]);
        expect([...nearbySystems(4, edges, 1)].sort()).toEqual([3, 4]);
    });

    it('centres the view on the character and fits the rest', () => {
        const centre = { id: 1, x: 0, y: 0, w: 100, h: 40 };
        const box = viewBoxAround(centre, [centre, { id: 2, x: 300, y: 0, w: 100, h: 40 }], 10);
        expect(box.x + box.w / 2).toBe(50);
        expect(box.y + box.h / 2).toBe(20);
        expect(box.w).toBe(2 * (400 - 50 + 10));
    });
});
