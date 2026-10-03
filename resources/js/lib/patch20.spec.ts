import { orderStatics, planSignatureAliases, staticSlotAliasAt, staticSlotFor } from '@/lib/alias';
import { formatBookmarkName, isFrigateOnly } from '@/lib/bookmark';
import { isK162Frigate, k162Classes, k162Hint, k162RangeLabel, k162ShipSize, k162Table, offersK162 } from '@/lib/k162';
import { decideStatic } from '@/lib/staticCertainty';
import { quickKeyRank, typeSections } from '@/lib/typeOrdering';
import { signatureTypes } from '@/const/signatures';
import { describe, expect, it } from 'vitest';

const BRAVO_STATICS = [
    { name: 'H900', leads_to: 'c5' },
    { name: 'X877', leads_to: 'c4' },
];

describe('patch 20: one reserved slot per static, in hs, ls, ns, C1 … C6 order', () => {
    it('orders statics by where they lead', () => {
        expect(orderStatics(BRAVO_STATICS).map((value) => value.name)).toEqual(['X877', 'H900']);
        expect(orderStatics([{ name: 'V753', leads_to: 'c6' }, { name: 'D845', leads_to: 'hs' }, { name: 'U210', leads_to: 'ls' }]).map((value) => value.name)).toEqual([
            'D845',
            'U210',
            'V753',
        ]);
    });

    it('gives each static its own slot (B0, B1) and starts the other holes after them', () => {
        expect(staticSlotAliasAt('B', 0)).toBe('B0');
        expect(staticSlotAliasAt('B', 1)).toBe('B1');
        expect(staticSlotFor('B', BRAVO_STATICS, 'X877')).toBe('B0');
        expect(staticSlotFor('B', BRAVO_STATICS, 'H900')).toBe('B1');
        expect(staticSlotFor('B', BRAVO_STATICS, 'N290')).toBe('B0');

        const planned = planSignatureAliases({
            parentAlias: 'B',
            originIsWormhole: true,
            aliases: [],
            staticCount: 2,
            signatures: [
                { id: 1, isWormhole: true, isConnected: false },
                { id: 2, isWormhole: true, isConnected: false },
                { id: 3, isWormhole: true, isConnected: false },
            ],
        });
        expect([...planned.values()]).toEqual(['B2', 'B3', 'B4']);
    });

    it('a marked static takes its own slot, by type', () => {
        const planned = planSignatureAliases({
            parentAlias: 'B',
            originIsWormhole: true,
            aliases: [],
            staticCount: 2,
            signatures: [
                { id: 1, isWormhole: true, isConnected: false },
                { id: 2, isWormhole: true, isConnected: false, isStatic: true, staticIndex: 1 },
                { id: 3, isWormhole: true, isConnected: false },
            ],
        });
        expect(planned.get(2)).toBe('B1');
        expect(planned.get(1)).toBe('B2');
        expect(planned.get(3)).toBe('B3');
    });

    it('a single-static system works as before (static 0, holes from 1)', () => {
        const planned = planSignatureAliases({
            parentAlias: 'A',
            originIsWormhole: true,
            aliases: [],
            signatures: [
                { id: 1, isWormhole: true, isConnected: false },
                { id: 2, isWormhole: true, isConnected: false, isStatic: true },
            ],
        });
        expect(planned.get(2)).toBe('A0');
        expect(planned.get(1)).toBe('A1');
    });
});

describe('patch 20: two statics settle with one scan', () => {
    it('marks the way back as the C5 static and NRW as the C4 static (Bravo)', () => {
        const result = decideStatic({
            statics: [
                { name: 'H900', leadsTo: 'c5' },
                { name: 'X877', leadsTo: 'c4' },
            ],
            holes: [
                { signatureId: 10, typeName: 'N290', isStatic: false, linked: false },
                { signatureId: 11, typeName: 'H900', isStatic: false, linked: true, leadsTo: 'c5' },
                { signatureId: 12, typeName: 'X877', isStatic: false, linked: false },
                { signatureId: 13, typeName: 'K162', isStatic: false, linked: false },
            ],
            uncategorized: 0,
            wayBack: { signatureId: 11, thisSideType: 'H900', farSideType: null, leadsTo: 'c5' },
        });
        expect(result.marks.map((mark) => [mark.signatureId, mark.staticName]).sort()).toEqual([
            [11, 'H900'],
            [12, 'X877'],
        ]);
    });

    it('checks the second static when the first one is already marked', () => {
        const result = decideStatic({
            statics: [
                { name: 'H900', leadsTo: 'c5' },
                { name: 'X877', leadsTo: 'c4' },
            ],
            holes: [
                { signatureId: 11, typeName: 'H900', isStatic: true, linked: true, leadsTo: 'c5' },
                { signatureId: 12, typeName: 'X877', isStatic: false, linked: true, leadsTo: 'c4' },
                { signatureId: 13, typeName: 'K162', isStatic: false, linked: false },
            ],
            uncategorized: 0,
            wayBack: { signatureId: 11, thisSideType: 'H900', farSideType: null, leadsTo: 'c5' },
        });
        expect(result.mark).toEqual({ signatureId: 12, staticName: 'X877', setType: false });
    });

    it('nothing left to do once both statics are marked', () => {
        const result = decideStatic({
            statics: [
                { name: 'H900', leadsTo: 'c5' },
                { name: 'X877', leadsTo: 'c4' },
            ],
            holes: [
                { signatureId: 11, typeName: 'H900', isStatic: true, linked: true },
                { signatureId: 12, typeName: 'X877', isStatic: true, linked: false },
            ],
            uncategorized: 0,
            wayBack: null,
        });
        expect(result.marks).toEqual([]);
    });
});

describe('patch 20: grouped K162s', () => {
    const range = (extra: string) => ({ id: 1, signature: 'K162', target_class: 'unknown', extra });
    const plain = (target: string) => ({ id: 2, signature: 'K162', target_class: target, extra: null });

    it('reads the classes of a K162 type', () => {
        expect(k162Classes(range('C4/5'))).toEqual(['4', '5']);
        expect(k162Classes(range('C1/2/3'))).toEqual(['1', '2', '3']);
        expect(k162Classes(plain('5'))).toEqual(['5']);
        expect(k162Classes(range('frigate'))).toEqual([]);
        expect(k162RangeLabel(range('C2/3'))).toBe('C2/3');
        expect(isK162Frigate(range('frigate'))).toBe(true);
    });

    it('offers each group where Show Info can tell it apart', () => {
        expect(offersK162(range('C1/2/3'), '1')).toBe(true);
        expect(offersK162(range('C1/2/3'), '4')).toBe(false);
        expect(offersK162(range('C4/5'), '4')).toBe(true);
        expect(offersK162(range('C4/5'), '1')).toBe(true);
        expect(offersK162(range('C4/5'), '5')).toBe(false);
        expect(offersK162(range('C2/3'), '1')).toBe(false);
        expect(offersK162(range('C2/3'), '6')).toBe(true);
        expect(offersK162(range('frigate'), '3')).toBe(true);
    });

    it('hints at the Show Info reading', () => {
        expect(k162Hint(range('C4/5'), '3')).toBe('dangerous');
        expect(k162Hint(range('C2/3'), '4')).toBe('unknown · large');
        expect(k162Hint(plain('4'), '5')).toBe('dangerous · large');
        expect(k162Hint(plain('5'), '6')).toBe('dangerous · very large');
        expect(k162Hint(plain('1'), '3')).toBe('unknown · medium');
        expect(k162Hint(plain('6'), '2')).toBe('deadly');
    });

    it('knows a K162 frigate is frigate-sized, and nothing into a C4 is XL', () => {
        expect(k162ShipSize(range('frigate'), '4', signatureTypes)).toBe('frigate');
        expect(k162ShipSize(range('C4/5'), '4', signatureTypes)).not.toBe('xlarge');
    });

    it('prints a table for every class (the build check)', () => {
        const table = k162Table(signatureTypes);
        expect(table.length).toBeGreaterThan(20);
        // eslint-disable-next-line no-console
        console.log(table.map((row) => `${row.standing.padEnd(3)} ${row.option.padEnd(16)} ${(row.hint ?? '').padEnd(24)} ${row.size ?? '-'}`).join('\n'));
    });
});

describe('patch 20: bookmarks', () => {
    const system = { alias: 'B', solarsystem: { class: '4' as const, name: 'J140545' } };

    it('a way back carries its hole marker and frig', () => {
        const name = formatBookmarkName(
            system,
            { signatureId: 'RAW-401', classSuffix: 'k' },
            { bookmark_format_return: '{_}{_}*{_}{here} {sig} {hereclass}', bookmark_ignored_alias: 'Daisy' },
            'B0',
            'B0',
            '4',
        );
        expect(name).toBe('  * B0 RAW C4k');

        const frig = formatBookmarkName(
            system,
            { signatureId: 'RAW-401', classSuffix: 'k', frigate: true },
            { bookmark_format_return: '{_}{_}*{_}{here} {sig} {hereclass}', bookmark_ignored_alias: 'Daisy' },
            'B0',
            'B0',
            '4',
        );
        expect(frig).toBe('  * B0 RAW C4k frig');
    });

    it('spots frigate-only holes', () => {
        expect(isFrigateOnly({ wormholeName: 'E004' })).toBe(true);
        expect(isFrigateOnly({ wormholeName: 'K162', typeExtra: 'frigate' })).toBe(true);
        expect(isFrigateOnly({ wormholeName: 'X877' })).toBe(false);
    });
});

describe('patch 20: the Type list order', () => {
    const types = [
        { id: 1, signature: 'H900', target_class: '5', extra: null },
        { id: 2, signature: 'X877', target_class: '4', extra: null },
        { id: 3, signature: 'K162', target_class: '5', extra: null },
        { id: 4, signature: 'K162', target_class: 'unknown', extra: 'C4/5' },
        { id: 5, signature: 'H296', target_class: '5', extra: null },
        { id: 6, signature: 'N290', target_class: 'l', extra: null },
    ];

    it('"5" ranks K162 C5, then K162 C4/5, the static to a C5, then other holes to a C5', () => {
        const ranked = types
            .map((type) => ({ type, rank: quickKeyRank('5', type, ['H900', 'X877']) }))
            .filter((entry) => entry.rank !== null)
            .sort((a, b) => (a.rank as number) - (b.rank as number))
            .map((entry) => entry.type.id);
        expect(ranked).toEqual([3, 4, 1, 5]);
    });

    it('keeps everything else below the matches', () => {
        const sections = typeSections({
            here: types,
            elsewhere: [],
            staticNames: ['H900', 'X877'],
            standingClass: '4',
            query: '5',
            counts: new Map(),
            offers: () => true,
            matches: () => true,
        });
        expect(sections[0].key).toBe('match');
        expect(sections[0].items.map((type) => type.id)).toEqual([3, 4, 1, 5]);
        expect(sections.flatMap((section) => section.items).length).toBe(types.length);
    });
});
