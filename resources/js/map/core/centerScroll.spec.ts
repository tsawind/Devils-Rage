import { centerScroll, insideComfortZone } from '@/map/core/centerScroll';
import { describe, expect, it } from 'vitest';

const view = { scrollLeft: 0, scrollTop: 0, width: 1500, height: 900 };

describe('centerScroll', () => {
    it('leaves the map alone while your system is well inside the view', () => {
        expect(insideComfortZone(view, { x: 750, y: 450 })).toBe(true);
        expect(centerScroll(view, { x: 750, y: 450 }, { rage: false, force: false })).toBeNull();
        expect(centerScroll(view, { x: 700, y: 600 }, { rage: true, force: false })).toBeNull();
    });

    it('re-centers once your system gets within a fifth of an edge', () => {
        // 1500 wide: the right edge zone starts at 1200.
        expect(centerScroll(view, { x: 1250, y: 450 }, { rage: false, force: false })).toEqual({ left: 750, top: 0 });
        // 900 high: the bottom edge zone starts at 720.
        expect(centerScroll(view, { x: 700, y: 760 }, { rage: false, force: false })).toEqual({ left: 200, top: 310 });
    });

    it('mapping: a third in from the left, middle height', () => {
        expect(centerScroll(view, { x: 3000, y: 2000 }, { rage: false, force: true })).toEqual({ left: 2500, top: 1550 });
    });

    it('rage scanning: the upper-left third', () => {
        expect(centerScroll(view, { x: 3000, y: 2000 }, { rage: true, force: true })).toEqual({ left: 2500, top: 1700 });
    });

    it('re-centers when your system is off screen, never scrolling past the start', () => {
        const scrolled = { ...view, scrollLeft: 2000, scrollTop: 2000 };
        expect(centerScroll(scrolled, { x: 100, y: 100 }, { rage: false, force: false })).toEqual({ left: 0, top: 0 });
    });

    it('force always moves', () => {
        expect(centerScroll(view, { x: 750, y: 450 }, { rage: false, force: true })).toEqual({ left: 250, top: 0 });
    });
});
