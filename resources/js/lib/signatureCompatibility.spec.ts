import { groupSignatureOptions, signatureCanBeConnection, signatureCanLeadToClass } from '@/lib/signatureCompatibility';
import type { TSignature, TSignatureCategory, TSignatureType, TStringedSolarsystemClass } from '@/types/models';
import { describe, expect, it } from 'vitest';

function signature(overrides: {
    id?: number;
    categoryCode?: string;
    targetClass?: TStringedSolarsystemClass | 'unknown' | null;
    hasType?: boolean;
    connected?: boolean;
}): TSignature {
    const { id = 1, categoryCode, targetClass = null, hasType = targetClass !== null, connected = false } = overrides;

    return {
        id,
        signature_id: 'ABC-123',
        map_connection_id: connected ? id : null,
        signature_category: categoryCode ? ({ id: 1, name: categoryCode, code: categoryCode } as TSignatureCategory) : null,
        signature_type: hasType ? ({ id: 1, name: 'Test', signature: 'X702', target_class: targetClass } as TSignatureType) : null,
    } as TSignature;
}

describe('signatureCanBeConnection', () => {
    it('accepts wormhole-category signatures', () => {
        expect(signatureCanBeConnection(signature({ categoryCode: 'wormhole' }))).toBe(true);
    });

    it('accepts uncategorised signatures', () => {
        expect(signatureCanBeConnection(signature({}))).toBe(true);
    });

    it('rejects site categories', () => {
        for (const categoryCode of ['gas', 'data', 'relic', 'combat', 'ore']) {
            expect(signatureCanBeConnection(signature({ categoryCode }))).toBe(false);
        }
    });
});

describe('signatureCanLeadToClass', () => {
    it('accepts signatures without a resolved type', () => {
        expect(signatureCanLeadToClass(signature({ hasType: false }), '4')).toBe(true);
    });

    it('accepts wormhole types leading to the target class', () => {
        expect(signatureCanLeadToClass(signature({ targetClass: '4' }), '4')).toBe(true);
    });

    it('rejects wormhole types leading to a different class', () => {
        expect(signatureCanLeadToClass(signature({ targetClass: 'n' }), '4')).toBe(false);
    });

    it('accepts wormhole types with an unknown destination', () => {
        expect(signatureCanLeadToClass(signature({ targetClass: 'unknown' }), '4')).toBe(true);
    });

    it('accepts typed wormholes when the target class is not known', () => {
        expect(signatureCanLeadToClass(signature({ targetClass: 'n' }), null)).toBe(true);
    });

    it('matches known-space classes exactly', () => {
        expect(signatureCanLeadToClass(signature({ targetClass: 'h' }), 'h')).toBe(true);
        expect(signatureCanLeadToClass(signature({ targetClass: 'h' }), 'l')).toBe(false);
    });
});

describe('groupSignatureOptions', () => {
    it('splits signatures into likely, connected and unlikely sections', () => {
        const matching = signature({ id: 1, categoryCode: 'wormhole', targetClass: '4' });
        const unscanned = signature({ id: 2 });
        const connected = signature({ id: 3, categoryCode: 'wormhole', targetClass: '4', connected: true });
        const wrongClass = signature({ id: 4, categoryCode: 'wormhole', targetClass: 'n' });
        const site = signature({ id: 5, categoryCode: 'gas' });

        const groups = groupSignatureOptions([matching, unscanned, connected, wrongClass, site], '4');

        expect(groups.likely).toEqual([matching, unscanned]);
        expect(groups.connected).toEqual([connected]);
        expect(groups.unlikely).toEqual([wrongClass]);
    });

    it('puts connected signatures into the connected section even when their class cannot match', () => {
        const connectedWrongClass = signature({ id: 1, categoryCode: 'wormhole', targetClass: 'n', connected: true });

        const groups = groupSignatureOptions([connectedWrongClass], '4');

        expect(groups.connected).toEqual([connectedWrongClass]);
        expect(groups.unlikely).toEqual([]);
    });

    it('drops site signatures entirely', () => {
        const groups = groupSignatureOptions([signature({ id: 1, categoryCode: 'relic' })], '4');

        expect(groups).toEqual({ likely: [], connected: [], unlikely: [] });
    });
});

describe('groupSignatureOptions (likely order)', () => {
    it('puts a wormhole typed to the landed class first, then other wormholes, then uncategorised', () => {
        const uncategorised = signature({ id: 1 });
        const untypedWormhole = signature({ id: 2, categoryCode: 'wormhole' });
        const matchingStatic = signature({ id: 3, categoryCode: 'wormhole', targetClass: '6' });
        const groups = groupSignatureOptions([uncategorised, untypedWormhole, matchingStatic], '6');

        expect(groups.likely).toEqual([matchingStatic, untypedWormhole, uncategorised]);
    });

    it('keeps the original order among equals', () => {
        const a = signature({ id: 1, categoryCode: 'wormhole' });
        const b = signature({ id: 2, categoryCode: 'wormhole' });
        expect(groupSignatureOptions([a, b], '6').likely).toEqual([a, b]);
    });
});

describe('groupSignatureOptions (jump prompt data shape)', () => {
    it('ranks by category id and destination class when the category object has no code', () => {
        const uncategorised = { id: 1, signature_id: 'IQL-244', map_connection_id: null, signature_category: null, signature_type: null } as unknown as TSignature;
        const untypedWormhole = { id: 2, signature_id: 'MWD-240', map_connection_id: null, signature_category: null, signature_category_id: 1, signature_type: null } as unknown as TSignature;
        const staticHole = {
            id: 3,
            signature_id: 'SOF-078',
            map_connection_id: null,
            signature_category: { id: 1, name: 'Wormhole' },
            signature_category_id: 1,
            signature_type: { id: 9, name: 'V753', target_class: '6' },
        } as unknown as TSignature;

        expect(groupSignatureOptions([uncategorised, untypedWormhole, staticHole], '6').likely).toEqual([staticHole, untypedWormhole, uncategorised]);
    });
});

describe('patch 28: a grouped K162 only leads to its classes', () => {
    const grouped = (extra: string): TSignature =>
        ({
            id: 7,
            signature_id: 'ELI-688',
            map_connection_id: null,
            signature_category: { id: 1, name: 'Wormhole', code: 'wormhole' } as TSignatureCategory,
            signature_type: { id: 903, name: `K162 - ${extra}`, signature: 'K162', target_class: 'unknown', extra } as TSignatureType,
        }) as TSignature;

    it('K162 C4/5 can lead to a C4 or a C5', () => {
        expect(signatureCanLeadToClass(grouped('C4/5'), '4')).toBe(true);
        expect(signatureCanLeadToClass(grouped('C4/5'), '5')).toBe(true);
    });

    it('K162 C4/5 cannot lead to highsec (Golf → Masanuh)', () => {
        expect(signatureCanLeadToClass(grouped('C4/5'), 'h')).toBe(false);
    });

    it('K162 C2/3 cannot lead to a C4', () => {
        expect(signatureCanLeadToClass(grouped('C2/3'), '4')).toBe(false);
    });

    it('K162 frigate still fits anywhere', () => {
        expect(signatureCanLeadToClass(grouped('frigate'), 'h')).toBe(true);
    });
});
