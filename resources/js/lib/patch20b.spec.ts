import { signatureTypes } from '@/const/signatures';
import { guessFromCandidates, possibleHoleTypes, type TPossibleType } from '@/lib/massEstimate';
import { isNonSignatureRow, signatureParser } from '@/lib/SignatureParser';
import { wormholeMass } from '@/lib/wormholeMass';
import { describe, expect, it, vi } from 'vitest';

vi.mock('vue-sonner', () => ({ toast: { info: vi.fn(), error: vi.fn() } }));

const types: TPossibleType[] = signatureTypes
    .filter((type) => type.signature_category_id === 1)
    .flatMap((type) => {
        const mass = wormholeMass(type.signature);
        return mass ? [{ name: type.signature, target_class: type.target_class, total: mass.total, maxJump: mass.maxJump, spawn_areas: type.spawn_areas, extra: type.extra }] : [];
    });

const guess = (params: Parameters<typeof possibleHoleTypes>[0]) => guessFromCandidates(possibleHoleTypes(params));

describe('patch 20: pipes sized from the holes that fit', () => {
    it('K162 in a C5 from nullsec is probably an N432', () => {
        expect(guess({ k162Class: '5', spawnClasses: ['n'], types })?.name).toBe('N432');
    });

    it('K162 in a C5 from a C5 is probably an H296', () => {
        expect(guess({ k162Class: '5', spawnClasses: ['5'], types })?.name).toBe('H296');
    });

    it('K162 C2/3 in a C5 is sized from the C2/C3 holes into a C5 (not a C1 medium hole)', () => {
        const result = guess({ k162Class: '5', spawnClasses: ['2', '3'], types });
        expect(result?.total).toBeGreaterThanOrEqual(2_000_000_000);
    });

    it('C5 ↔ nullsec with no K162 side known: sizes differ, no guess', () => {
        expect(guess({ endClasses: ['5', 'n'], types })).toBe(null);
    });
});

describe('patch 20: ships and deployables in a paste are ignored', () => {
    it('spots non-signature rows', () => {
        expect(isNonSignatureRow(['ABC-123', 'Cosmic Signature', 'Wormhole', 'Unstable Wormhole'])).toBe(false);
        expect(isNonSignatureRow(['ABC-123', 'Cosmic Anomaly', 'Combat Site', 'Guristas Hideaway'])).toBe(false);
        expect(isNonSignatureRow(['XYZ-987', 'Ship', 'Frigate', 'Buzzard'])).toBe(true);
        expect(isNonSignatureRow(['QWE-111', 'Deployable', 'Mobile Depot', 'Mobile Depot'])).toBe(true);
    });

    it('keeps only signatures from a mixed paste', () => {
        const paste = ['ABC-123\tCosmic Signature\tWormhole\tUnstable Wormhole\t100,0%\t1,00 AU', 'XYZ-987\tShip\tFrigate\tBuzzard\t100,0%\t5 km', 'QWE-111\tDeployable\tMobile Depot\tMobile Depot\t100,0%\t9 km'].join('\n');
        expect(signatureParser.parseSignatures(paste).map((row) => row.signature_id)).toEqual(['ABC-123']);
    });
});
