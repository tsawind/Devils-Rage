import { describe, expect, it } from 'vitest';
import { displayAlias, guessNextAlias, planSignatureAliases, splitStamp, suggestAlias, type TAliasPlanSignature } from '@/lib/alias';
import { buildSignatureBookmark, DEFAULT_BOOKMARK_FORMAT_RETURN, formatBookmarkName } from '@/lib/bookmark';
import { floatingNames, forwardSuffix, nameSeenThrough } from '@/lib/floatingNames';
import { findNumberingHubs } from '@/lib/numberingHubs';
import { oldChainRows } from '@/lib/oldChainRows';
import { endRecopyWindow, noteHoleCopy, recopyOpen } from '@/lib/recopy';
import { renameHolder } from '@/lib/renameHolder';
import { isTheraName, specialClassWord } from '@/lib/specialSystems';
import type { TStringedSolarsystemClass } from '@/types/models';

/** The Devil's Rage bookmark settings (Map settings → Bookmark Format). */
const FORMATS = {
    bookmark_format_wormhole: '{_}{alias} {sig} {class} {mass} {life}',
    bookmark_format_kspace: '{_}{alias} {sig} {class} {mass} {life} {name} {region}',
    bookmark_format_return: DEFAULT_BOOKMARK_FORMAT_RETURN,
    bookmark_ignored_alias: 'Daisy',
    bookmark_alias_scheme: 'numeric' as const,
};

const hole = (id: number, extra: Partial<TAliasPlanSignature> = {}): TAliasPlanSignature => ({ id, isWormhole: true, isConnected: false, ...extra });
const solarsystem = (cls: string, name = 'J100000') => ({ class: cls as TStringedSolarsystemClass, name });

describe('patch 39: stamps use "~"', () => {
    it('splits and shows a stamped name', () => {
        expect(splitStamp('A1~1917')).toEqual({ base: 'A1', stamp: '1917' });
        expect(displayAlias('A~1917')).toBe('Alpha~1917');
        expect(guessNextAlias('A1~1917', ['A11~1917'])).toBe('A12~1917');
    });
});

describe('patch 39: hole numbers only count up', () => {
    it('skips a freed number while 9 is not reached', () => {
        // A1 had A1, A2, A3; A2 was freed. The next hole is A4, not A2.
        const planned = planSignatureAliases({ parentAlias: 'A', originIsWormhole: true, aliases: ['A1', 'A3'], countUp: true, slotHigh: '3', signatures: [hole(1)] });
        expect(planned.get(1)).toBe('A4');
    });

    it('refills the gaps once 9 was reached, then letters', () => {
        const planned = planSignatureAliases({ parentAlias: 'A', originIsWormhole: true, aliases: ['A1', 'A3', 'A9'], countUp: true, slotHigh: '9', signatures: [hole(1), hole(2)] });
        expect(planned.get(1)).toBe('A2');
        expect(planned.get(2)).toBe('A4');
        const full = planSignatureAliases({ parentAlias: 'A', originIsWormhole: true, aliases: ['A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8', 'A9'], countUp: true, signatures: [hole(1)] });
        expect(full.get(1)).toBe('AA');
    });

    it('hubs and home refill at once', () => {
        expect(planSignatureAliases({ parentAlias: 'A', originIsWormhole: true, aliases: ['A1', 'A3'], countUp: false, slotHigh: '3', signatures: [hole(1)] }).get(1)).toBe('A2');
        expect(planSignatureAliases({ parentAlias: 'Daisy', ignoredAlias: 'Daisy', originIsWormhole: true, aliases: ['B', 'G'], countUp: true, slotHigh: '9', signatures: [hole(1)] }).get(1)).toBe('D');
    });

    it('a guess counts up too', () => {
        expect(guessNextAlias('A1', ['A11'], { countUp: true, slotHigh: '4' })).toBe('A15');
    });

    it('finds the hubs: home, pinned, rage homes and side chain starts', () => {
        const hubs = findNumberingHubs(
            [{ id: 1 }, { id: 2 }, { id: 3, pinned: true }, { id: 4 }, { id: 5, combat_home: true }],
            new Map([
                [2, 1],
                [3, 2],
                [5, 2],
            ]),
            1,
        );
        expect([...hubs].toSorted()).toEqual([1, 3, 4, 5]);
    });
});

describe('patch 39: special systems', () => {
    it('names special classes in words', () => {
        expect(specialClassWord('12')).toBe('Thera');
        expect(specialClassWord('13')).toBe('C13 frig');
        expect(specialClassWord('16')).toBe('Vidette');
        expect(specialClassWord('p')).toBe('Pochven');
        expect(specialClassWord('4', { isShattered: true })).toBe('C4shattered');
        expect(specialClassWord('4')).toBeNull();
    });

    it('Thera has its own name and numbers its holes after it', () => {
        expect(suggestAlias({ parentAlias: 'Daisy', targetIsWormhole: true, originIsWormhole: true, aliases: [], ignoredAlias: 'Daisy', targetClass: '12' })).toBe('Thera');
        expect(guessNextAlias('Thera', ['Thera1'])).toBe('Thera2');
        expect(guessNextAlias('Thera2', [])).toBe('Thera21');
        expect(isTheraName('Thera21')).toBe(true);
        expect(isTheraName('Therapist')).toBe(false);
        const planned = planSignatureAliases({ parentAlias: 'A', originIsWormhole: true, aliases: [], signatures: [hole(1, { targetClass: '12' }), hole(2)] });
        expect(planned.get(1)).toBe('Thera');
        expect(planned.get(2)).toBe('A1');
    });

    it('bookmarks into Thera drop the class, like a callsign', () => {
        const name = buildSignatureBookmark({
            signature: { signature_id: 'AUX-123', ship_size: null, mass_status: null, lifetime: 'healthy', alias: 'Thera' },
            currentSystem: { alias: 'Daisy', class: '5' as TStringedSolarsystemClass },
            connectionTarget: { alias: 'Thera', solarsystem: solarsystem('12', 'Thera') },
            aliases: [],
            formats: FORMATS,
        });
        expect(name).toBe('  Thera AUX');
        const back = formatBookmarkName({ alias: 'Daisy', solarsystem: solarsystem('5') }, { signatureId: 'XYZ-123' }, FORMATS, 'Thera', 'Thera', '12' as TStringedSolarsystemClass);
        expect(back).toBe('  *@Thera XYZ');
    });
});

describe('patch 39: loops and floating names', () => {
    const connections = [
        // Alpha (1) → A01 (3) through Alpha's hole A2: a loop.
        { id: 10, from_map_solarsystem_id: 1, to_map_solarsystem_id: 3, signatures: [{ id: 100, map_solarsystem_id: 1, alias: 'A2' }] },
        // A0 (2) → A01 (3), how it was found.
        { id: 11, from_map_solarsystem_id: 2, to_map_solarsystem_id: 3, signatures: [{ id: 101, map_solarsystem_id: 2, alias: 'A01' }] },
    ];
    const system = { id: 3, alias: 'A01', old_aliases: ['A1~1917'] };

    it('lists the loop and earlier names', () => {
        expect(floatingNames(system, connections).map((name) => `${name.kind}:${name.name}`)).toEqual(['loop:A2', 'was:A1~1917']);
    });

    it('names the way back after the hole it goes through', () => {
        expect(nameSeenThrough(system, connections[0])).toBe('A2');
        expect(nameSeenThrough(system, connections[1])).toBe('A01');
        const back = formatBookmarkName({ alias: 'A', solarsystem: solarsystem('6') }, { signatureId: 'KQX-123' }, FORMATS, 'A01', nameSeenThrough(system, connections[0]), '4' as TStringedSolarsystemClass);
        expect(back).toBe('  *@A2 KQX C4');
    });

    it('adds loop= / old= to forward bookmarks', () => {
        expect(forwardSuffix({ mainName: 'A01', holeName: 'A2' })).toEqual({ name: 'A01', suffix: 'loop=A2' });
        expect(forwardSuffix({ mainName: 'A1~1917', holeName: 'Z2111' })).toEqual({ name: 'Z2111', suffix: 'old=A1~1917' });
        const name = buildSignatureBookmark({
            signature: { signature_id: 'QRS-123', ship_size: null, mass_status: null, lifetime: 'healthy', alias: 'A2' },
            currentSystem: { alias: 'A', class: '6' as TStringedSolarsystemClass },
            connectionTarget: { alias: 'A01', solarsystem: solarsystem('4') },
            aliases: [],
            formats: FORMATS,
            detectReturn: true,
        });
        expect(name).toBe(' A01 QRS C4 loop=A2');
    });

    it('offers rename rows for an old chain', () => {
        const old = { id: 3, alias: 'A1~1917' };
        const reconnected = [{ id: 20, from_map_solarsystem_id: 9, to_map_solarsystem_id: 3, signatures: [{ id: 200, map_solarsystem_id: 9, alias: 'Z2111' }] }];
        expect(oldChainRows({ here: old, systems: [old], connections: reconnected, parentOf: new Map([[3, 9]]), taken: [] })).toEqual([
            { kind: 'self', from: 'A1~1917', to: 'Z2111', signatureId: 200, viaSystemId: 9 },
        ]);
        expect(oldChainRows({ here: { ...old, name_kept: true }, systems: [old], connections: reconnected, parentOf: new Map(), taken: [] })).toEqual([]);

        // Renamed: its hole into the old chain beyond is next.
        const renamed = { id: 3, alias: 'Z2111' };
        const beyond = { id: 4, alias: 'A11~1917' };
        const pipes = [...reconnected, { id: 21, from_map_solarsystem_id: 3, to_map_solarsystem_id: 4, signatures: [{ id: 201, map_solarsystem_id: 3, alias: 'A11~1917' }] }];
        expect(oldChainRows({ here: renamed, systems: [renamed, beyond], connections: pipes, parentOf: new Map([[3, 9]]), taken: [] })).toEqual([
            { kind: 'hole', from: 'A11~1917', to: 'Z21111', signatureId: 201, systemId: 4 },
        ]);
    });

    it('finds who already has a name', () => {
        const holder = renameHolder({
            name: 'A2',
            signatureId: 1,
            targetId: null,
            parent: { id: 1, alias: 'A' },
            parentHoles: [
                { id: 1, alias: 'A3' },
                { id: 2, alias: 'A2', signature_id: 'LIH-123' },
            ],
            systems: [{ id: 1, alias: 'A' }],
        });
        expect(holder).toEqual({ label: 'hole LIH', next: 'A1' });
        expect(renameHolder({ name: 'A9', signatureId: 1, targetId: null, parent: { id: 1, alias: 'A' }, parentHoles: [], systems: [] })).toBeNull();
    });
});

describe('patch 39: re-copy window', () => {
    it('stays open 20 s for the same hole only', () => {
        noteHoleCopy('sig:1', ' A1 LIH C2', () => ' A1 LIH C2', 1000);
        expect(recopyOpen('sig:1', 20_000)).toBe(true);
        expect(recopyOpen('sig:2', 2000)).toBe(false);
        expect(recopyOpen('sig:1', 21_001)).toBe(false);
        endRecopyWindow();
        expect(recopyOpen('sig:1', 2000)).toBe(false);
    });
});
