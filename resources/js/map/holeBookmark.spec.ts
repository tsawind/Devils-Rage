import { buildSignatureBookmark } from '@/lib/bookmark';
import { holeAsSignature } from '@/map/holeBookmark';
import type { TPendingHole } from '@/pages/maps';
import { describe, expect, it } from 'vitest';

const hole = (signatureTypeId: number | null): TPendingHole =>
    ({
        id: 1,
        signature_id: 'ZWV-053',
        alias: 'G4',
        is_static: false,
        is_wandering: false,
        target_class: 'unknown',
        wormhole: 'K162',
        signature_type_id: signatureTypeId,
        lifetime: 'healthy',
    }) as unknown as TPendingHole;

const bookmark = (signatureTypeId: number | null): string =>
    buildSignatureBookmark({
        signature: holeAsSignature(hole(signatureTypeId)),
        currentSystem: { alias: 'G', class: '4' },
        aliases: [],
        formats: {},
        plannedAlias: 'G4',
    }).trim();

describe('patch 28: a grouped K162 on the map keeps its range in the bookmark', () => {
    it('K162 C4/5 → "G4 ZWV C4/5k"', () => {
        expect(bookmark(903)).toBe('G4 ZWV C4/5k');
    });

    it('K162 C2/3 → "G4 ZWV C2/3k"', () => {
        expect(bookmark(902)).toBe('G4 ZWV C2/3k');
    });

    it('K162 frigate gets the frigate mark', () => {
        expect(bookmark(904)).toBe('G4 ZWV k frig');
    });

    it('a plain K162 with no range stays "k"', () => {
        expect(bookmark(null)).toBe('G4 ZWV k');
    });
});
