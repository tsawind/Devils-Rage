import { decideStatic } from '@/lib/staticCertainty';
import { describe, expect, it } from 'vitest';

/**
 * Patch 53: A2 (C4, statics H900 → C5 and U574 → C6). The way back YSM is a U574 (it opened
 * here), the only other signature JQL isn't scanned down yet: JQL has to be the H900.
 */
describe('patch 53: a signature not scanned down can still be the only place for a static', () => {
    const input = {
        statics: [
            { name: 'H900', leadsTo: 'c5' },
            { name: 'U574', leadsTo: 'c6' },
        ],
        holes: [{ signatureId: 10, typeName: 'U574', isStatic: false, linked: true, leadsTo: 'c6' }],
        uncategorized: 1,
        wayBack: { signatureId: 10, thisSideType: 'U574', farSideType: null, leadsTo: 'c6' },
        scanned: true,
    };

    it('marks the uncategorised signature as H900 and the way back as U574', () => {
        const result = decideStatic({ ...input, uncategorizedIds: [20] });
        expect(result.marks).toEqual([
            { signatureId: 20, staticName: 'H900', setType: true, categorize: true },
            { signatureId: 10, staticName: 'U574', setType: false },
        ]);
    });

    it('still waits when the uncategorised signatures are not known', () => {
        expect(decideStatic(input).marks).toEqual([]);
    });

    it('does not guess when two uncategorised signatures could be it', () => {
        const result = decideStatic({ ...input, uncategorized: 2, uncategorizedIds: [20, 21] });
        expect(result.marks.map((mark) => mark.staticName)).not.toContain('H900');
    });
});
