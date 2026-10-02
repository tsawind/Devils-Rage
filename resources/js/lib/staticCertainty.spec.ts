import { classCode, decideStatic, wayBackCouldBe } from '@/lib/staticCertainty';
import { describe, expect, it } from 'vitest';

const V753 = [{ name: 'V753', leadsTo: 'c6' }];

describe('patch 13: the static only when it is certain', () => {
    it('does not mark a static-type hole while a signature is still unscanned', () => {
        const result = decideStatic({
            statics: [{ name: 'V911', leadsTo: 'c5' }],
            holes: [{ signatureId: 1, typeName: 'V911', isStatic: false, linked: false }],
            uncategorized: 1,
            wayBack: null,
        });
        expect(result.mark).toBe(null);
    });

    it('does not mark it while another wormhole has no type yet', () => {
        const result = decideStatic({
            statics: [{ name: 'V911', leadsTo: 'c5' }],
            holes: [
                { signatureId: 1, typeName: 'V911', isStatic: false, linked: false },
                { signatureId: 2, typeName: null, isStatic: false, linked: false },
            ],
            uncategorized: 0,
            wayBack: null,
        });
        expect(result.mark).toBe(null);
    });

    it('marks the only candidate once everything is scanned', () => {
        const result = decideStatic({
            statics: [{ name: 'V911', leadsTo: 'c5' }],
            holes: [
                { signatureId: 1, typeName: 'V911', isStatic: false, linked: false },
                { signatureId: 2, typeName: 'D845', isStatic: false, linked: false },
            ],
            uncategorized: 0,
            wayBack: null,
        });
        expect(result.mark).toEqual({ signatureId: 1, staticName: 'V911', setType: false });
    });

    it('marks the way back as the static when nothing else can be (A1 example)', () => {
        // A1 is a C5 with a V753 (→ C6) static; Alpha's side of the hole is a K162; the other sig is a gas site.
        const result = decideStatic({
            statics: V753,
            holes: [{ signatureId: 7, typeName: null, isStatic: false, linked: true }],
            uncategorized: 0,
            wayBack: { signatureId: 7, thisSideType: null, farSideType: 'K162', leadsTo: 'c6' },
        });
        expect(result.wayBackCouldBe).toEqual(['V753']);
        expect(result.mark).toEqual({ signatureId: 7, staticName: 'V753', setType: true });
    });

    it('is ambiguous when the way back and a scanned hole could both be the static', () => {
        const result = decideStatic({
            statics: V753,
            holes: [
                { signatureId: 7, typeName: null, isStatic: false, linked: true },
                { signatureId: 8, typeName: 'V753', isStatic: false, linked: false },
            ],
            uncategorized: 0,
            wayBack: { signatureId: 7, thisSideType: null, farSideType: null, leadsTo: 'c6' },
        });
        expect(result.mark).toBe(null);
        expect(result.ambiguous).toEqual({ staticName: 'V753', signatureIds: [8, 7] });
    });

    it('does not guess while the way back could be it but its signature is not pasted yet', () => {
        const result = decideStatic({
            statics: V753,
            holes: [{ signatureId: 8, typeName: 'V753', isStatic: false, linked: false }],
            uncategorized: 0,
            wayBack: { signatureId: null, thisSideType: null, farSideType: null, leadsTo: 'c6' },
        });
        expect(result.mark).toBe(null);
    });

    it('knows when the way back cannot be the static', () => {
        // Came in through the other side's own static (a known non-K162 type there): this side is the K162.
        expect(wayBackCouldBe(V753, { signatureId: 7, thisSideType: null, farSideType: 'H296', leadsTo: 'c6' })).toEqual([]);
        // Leads to the wrong class.
        expect(wayBackCouldBe(V753, { signatureId: 7, thisSideType: null, farSideType: null, leadsTo: 'c5' })).toEqual([]);
        // This side is known to be a K162.
        expect(wayBackCouldBe(V753, { signatureId: 7, thisSideType: 'K162', farSideType: null, leadsTo: 'c6' })).toEqual([]);
        expect(classCode('5')).toBe('c5');
        expect(classCode('h')).toBe('hs');
    });

    it('a jumped hole with no type could be the static when it leads to the static class', () => {
        const typedAndUntyped = decideStatic({
            statics: V753,
            holes: [
                { signatureId: 8, typeName: 'V753', isStatic: false, linked: false },
                { signatureId: 9, typeName: null, isStatic: false, linked: true, leadsTo: 'c6' },
            ],
            uncategorized: 0,
            wayBack: null,
        });
        expect(typedAndUntyped.mark).toBe(null);
        expect(typedAndUntyped.ambiguous).toEqual({ staticName: 'V753', signatureIds: [8, 9] });

        const otherClass = decideStatic({
            statics: V753,
            holes: [
                { signatureId: 8, typeName: 'V753', isStatic: false, linked: false },
                { signatureId: 9, typeName: null, isStatic: false, linked: true, leadsTo: 'c3' },
            ],
            uncategorized: 0,
            wayBack: null,
        });
        expect(otherClass.mark?.signatureId).toBe(8);
    });

    it('a hole jumped from here but never pasted could be the static', () => {
        const result = decideStatic({
            statics: V753,
            holes: [{ signatureId: 8, typeName: 'V753', isStatic: false, linked: false }],
            uncategorized: 0,
            wayBack: null,
            unpastedLeadsTo: ['c6'],
        });
        expect(result.mark).toBe(null);
    });

    it('marks the way back once the last other hole turns out to be a K162 (D2 → Delta)', () => {
        const result = decideStatic({
            statics: [{ name: 'H296', leadsTo: 'c5' }],
            holes: [
                { signatureId: 1, typeName: 'H296', isStatic: false, linked: true, leadsTo: 'c5' },
                { signatureId: 2, typeName: 'K162', isStatic: false, linked: false },
            ],
            uncategorized: 0,
            wayBack: { signatureId: 1, thisSideType: 'H296', farSideType: null, leadsTo: 'c5' },
        });
        expect(result.mark).toEqual({ signatureId: 1, staticName: 'H296', setType: false });
    });
});

describe('patch 18b: the only untyped wormhole is the static', () => {
    it('marks the only wormhole in a fully scanned system (Daisy, IHJ) and sets its type', () => {
        const result = decideStatic({ statics: V753, holes: [{ signatureId: 7, typeName: null, isStatic: false, linked: false }], uncategorized: 0, wayBack: null });
        expect(result.mark).toEqual({ signatureId: 7, staticName: 'V753', setType: true });
    });

    it('not while a signature is uncategorised, nor with two untyped holes (and no "ambiguous" message then)', () => {
        expect(decideStatic({ statics: V753, holes: [{ signatureId: 7, typeName: null, isStatic: false, linked: false }], uncategorized: 1, wayBack: null }).mark).toBe(null);
        const two = decideStatic({
            statics: V753,
            holes: [
                { signatureId: 7, typeName: null, isStatic: false, linked: false },
                { signatureId: 8, typeName: null, isStatic: false, linked: false },
            ],
            uncategorized: 0,
            wayBack: null,
        });
        expect(two.mark).toBe(null);
        expect(two.ambiguous).toBe(null);
    });

    it('an untyped hole known to lead elsewhere is not a candidate', () => {
        const result = decideStatic({
            statics: V753,
            holes: [
                { signatureId: 7, typeName: null, isStatic: false, linked: false, leadsTo: 'hs' },
                { signatureId: 8, typeName: null, isStatic: false, linked: false },
            ],
            uncategorized: 0,
            wayBack: null,
        });
        expect(result.mark?.signatureId).toBe(8);
    });

    it('down chain: not while the way back could be the static', () => {
        const result = decideStatic({
            statics: V753,
            holes: [{ signatureId: 7, typeName: null, isStatic: false, linked: false }],
            uncategorized: 0,
            wayBack: { signatureId: 3, thisSideType: null, farSideType: null, leadsTo: 'c6' },
        });
        expect(result.mark).toBe(null);
    });

    it('down chain: marks it once the way back is known not to be the static', () => {
        const result = decideStatic({
            statics: V753,
            holes: [
                { signatureId: 3, typeName: 'K162', isStatic: false, linked: true, leadsTo: 'c6' },
                { signatureId: 7, typeName: null, isStatic: false, linked: false },
            ],
            uncategorized: 0,
            wayBack: { signatureId: 3, thisSideType: 'K162', farSideType: 'H296', leadsTo: 'c6' },
        });
        expect(result.mark?.signatureId).toBe(7);
    });
});
