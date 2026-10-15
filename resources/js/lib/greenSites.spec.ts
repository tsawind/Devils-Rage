import { greenTotal, isk } from '@/lib/greenSites';
import { describe, expect, it } from 'vitest';

describe('patch 37: green sites', () => {
    it('4 Core Garrisons = 1413 / 2814 (C5: recon 100; dread 3 avengers × 50 + drifter 300)', () => {
        const total = greenTotal(['Core Garrison', 'Core Garrison', 'Core Garrison', 'Core Garrison'])!;
        expect(isk(total.recon)).toBe('1,414');
        expect(total.recon).toBeCloseTo(1413.6);
        expect(total.dread).toBeCloseTo(2813.6);
        expect(total.lines).toEqual([{ name: 'Core Garrison', count: 4, recon: expect.closeTo(1413.6), dread: expect.closeTo(2813.6) }]);
    });

    it('C6 sites spawn 4 avengers with a dread', () => {
        const total = greenTotal(['Core Bastion', null, 'Wormhole', 'Perimeter Camp'])!;
        expect(total.recon).toBeCloseTo(545.6);
        expect(total.dread).toBeCloseTo(445.6 + 200 + 300);
    });

    it('nothing when no sites', () => {
        expect(greenTotal(['Unstable Wormhole', undefined])).toBeNull();
    });
});
