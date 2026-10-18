import { drivenElsewhere, mapsAltHere, mapsMainHere, type TWindowInfo } from '@/lib/windowRegistry';
import { describe, expect, it } from 'vitest';

const me = { id: 'me', startedAt: 200, slug: 'daisy', driver: 1 };
const win = (id: string, startedAt: number, driver: number | null, slug = 'daisy'): TWindowInfo => ({ id, startedAt, slug, driver, seenAt: 0 });

describe('patch 39: each character is mapped by one window', () => {
    it('leaves a character to the window where it is the main', () => {
        const list = [win('a', 100, 2)];
        expect(drivenElsewhere('daisy', 2, list)).toBe(true);
        expect(drivenElsewhere('other', 2, list)).toBe(false);
        expect(mapsAltHere('daisy', 2, list, me)).toBe(false);
    });

    it('maps tracked alts in the oldest window on the map only', () => {
        expect(mapsAltHere('daisy', 3, [win('a', 100, 2)], me)).toBe(false);
        expect(mapsAltHere('daisy', 3, [win('a', 300, 2)], me)).toBe(true);
        expect(mapsAltHere('daisy', 3, [win('a', 100, 2, 'other')], me)).toBe(true);
    });

    it('lets the older of two windows with the same main map it', () => {
        expect(mapsMainHere('daisy', 1, [win('a', 100, 1)], me)).toBe(false);
        expect(mapsMainHere('daisy', 1, [win('a', 300, 1)], me)).toBe(true);
        expect(mapsMainHere('daisy', 1, [win('a', 100, 2)], me)).toBe(true);
    });
});
