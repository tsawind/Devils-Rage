import { decideStatic } from '@/lib/staticCertainty';
import { describe, expect, it } from 'vitest';

describe('patch 37: no static certainty before a scan', () => {
    const input = {
        statics: [{ name: 'V753', leadsTo: 'c6' }],
        holes: [{ signatureId: 1, typeName: 'V753', isStatic: false, linked: true, leadsTo: 'c6' }],
        uncategorized: 0,
        wayBack: null,
    };

    it('does not mark the static when only the jumped-in hole is known (nothing pasted)', () => {
        expect(decideStatic({ ...input, scanned: false }).marks).toEqual([]);
    });

    it('marks it once the system was scanned', () => {
        expect(decideStatic({ ...input, scanned: true }).marks.map((mark) => mark.signatureId)).toEqual([1]);
    });
});
