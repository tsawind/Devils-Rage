import { aliasForSlot, displayAlias, formatAliasPath, localSlot, planSignatureAliases, staticSlotAlias, suggestAlias } from '@/lib/alias';
import { buildSignatureBookmark, formatBookmarkName, isCombatHomeReturn } from '@/lib/bookmark';
import { chainAliases, combatColorHex, combatColorLabel, DEAD_END_STALE_MS, describeChainRoute, describeRoute, isDeadEnd } from '@/lib/combat';
import { describe, expect, it } from 'vitest';

const FORMATS = {
    bookmark_format_wormhole: '{_}{alias} {sig} {class} {mass} {life}',
    bookmark_format_kspace: '{_}{alias} {sig} {class} {mass} {life} {name} {region}',
    bookmark_format_return: '{_}{_}*{_}{here} {sig} {hereclass}',
    bookmark_ignored_alias: 'Daisy',
    bookmark_alias_scheme: 'numeric' as const,
};

describe('combat home numbering', () => {
    it('numbers the combat home holes 1, 2, 3 with the static 0', () => {
        const planned = planSignatureAliases({
            parentAlias: 'A1',
            originIsWormhole: true,
            combatHome: true,
            aliases: [],
            ignoredAlias: 'Daisy',
            signatures: [
                { id: 1, isWormhole: true, isConnected: false },
                { id: 2, isWormhole: true, isConnected: false, isStatic: true },
                { id: 3, isWormhole: true, isConnected: false },
            ],
        });
        expect(planned.get(1)).toBe('1');
        expect(planned.get(2)).toBe('0');
        expect(planned.get(3)).toBe('2');
    });

    it('continues the chain under a combat system as usual', () => {
        expect(suggestAlias({ parentAlias: '1', targetIsWormhole: true, originIsWormhole: true, aliases: ['1', '11'] })).toBe('12');
    });

    it('suggests 1 for the first system off a combat home', () => {
        expect(suggestAlias({ parentAlias: 'A1', targetIsWormhole: true, originIsWormhole: true, aliases: ['A1'], combatHome: true })).toBe('1');
        expect(suggestAlias({ parentAlias: 'A1', targetIsWormhole: true, originIsWormhole: true, aliases: ['A1', '1'], combatHome: true })).toBe('2');
    });

    it('gives a combat home the static slot 0 and plain slots', () => {
        expect(staticSlotAlias('A1', 'Daisy', true)).toBe('0');
        expect(aliasForSlot('A1', '3', 'Daisy', true)).toBe('3');
        expect(staticSlotAlias('A1', 'Daisy')).toBe('A10');
    });

    it('only counts aliases of the same chain', () => {
        const systems = [
            { alias: 'Daisy', combat_color: null },
            { alias: 'A', combat_color: null },
            { alias: 'A1', combat_color: 'red', combat_home: true },
            { alias: '1', combat_color: 'red' },
            { alias: 'B', combat_color: null },
            { alias: 'B1', combat_color: 'blue', combat_home: true },
            { alias: '1', combat_color: 'blue' },
            { alias: '2', combat_color: 'blue' },
        ];
        expect(chainAliases(systems, systems[2])).toEqual(['A1', '1']);
        expect(chainAliases(systems, systems[6])).toEqual(['B1', '1', '2']);
        expect(chainAliases(systems, systems[1])).toEqual(['Daisy', 'A', 'A1', 'B', 'B1']);
        expect(chainAliases(systems, null)).toEqual(['Daisy', 'A', 'A1', 'B', 'B1']);
    });
});

describe('returns into a combat home', () => {
    const home = { alias: 'A1', combat_home: true, solarsystem: { class: '5' as const, name: 'J100001' } };

    it('treats the hole back into the combat home as a return', () => {
        expect(isCombatHomeReturn(home, '1', 'Daisy')).toBe(true);
        expect(isCombatHomeReturn(home, '2', 'Daisy')).toBe(true);
        expect(formatBookmarkName(home, { signatureId: 'JOW-123' }, FORMATS, '1', '1', '3')).toBe('  * 1 JOW C3');
    });

    it('keeps the forward name from the combat home parent', () => {
        expect(isCombatHomeReturn(home, 'A', 'Daisy')).toBe(false);
        expect(isCombatHomeReturn({ ...home, alias: 'A' }, 'Daisy', 'Daisy')).toBe(false);
        expect(formatBookmarkName(home, { signatureId: 'KXR-123' }, FORMATS, 'A', 'A', '6')).toBe(' A1 KXR C5');
    });

    it('keeps the combat home own return with its real alias', () => {
        const parent = { alias: 'A', solarsystem: { class: '6' as const, name: 'J100000' } };
        expect(formatBookmarkName(parent, { signatureId: 'JOW-123' }, FORMATS, 'A1', 'A1', '5')).toBe('  * A1 JOW C5');
    });

    it('uses the chain color when it is known', () => {
        const redHome = { ...home, combat_color: 'red' };
        // The 10th hole off the combat home is "A": same chain, so still a return.
        expect(isCombatHomeReturn(redHome, 'A', 'Daisy', 'red')).toBe(true);
        expect(isCombatHomeReturn(redHome, 'A', 'Daisy', null)).toBe(false);
        expect(isCombatHomeReturn(redHome, '12', 'Daisy', 'blue')).toBe(false);
        expect(formatBookmarkName(redHome, { signatureId: 'JOW-123' }, FORMATS, '3', '3', '3', 'red')).toBe('  * 3 JOW C3');
    });

    it('is not a return for a system that is not a combat home', () => {
        expect(isCombatHomeReturn({ alias: 'A1' }, '1', 'Daisy')).toBe(false);
    });
});

describe('dead ends', () => {
    const now = Date.parse('2026-09-30T20:00:00Z');
    const scanned = '2026-09-30T19:00:00Z';
    const base = {
        scanned_at: scanned,
        signatures_count: 4,
        wormhole_signatures_count: 1,
        uncategorized_signatures_count: 0,
        solarsystem: { class: '3' as const },
    };

    it('greys a fully scanned system whose only hole is the way in', () => {
        expect(isDeadEnd(base, 1, now, false)).toBe(true);
    });

    it('does not grey a system with unidentified signatures', () => {
        expect(isDeadEnd({ ...base, uncategorized_signatures_count: 1 }, 1, now, false)).toBe(false);
    });

    it('does not grey a system with another hole or connection', () => {
        expect(isDeadEnd({ ...base, wormhole_signatures_count: 2 }, 1, now, false)).toBe(false);
        expect(isDeadEnd(base, 2, now, false)).toBe(false);
    });

    it('does not grey without a scan, or after 4 hours', () => {
        expect(isDeadEnd({ ...base, scanned_at: null }, 1, now, false)).toBe(false);
        expect(isDeadEnd({ ...base, scanned_at: new Date(now - DEAD_END_STALE_MS - 1000).toISOString() }, 1, now, false)).toBe(false);
        expect(isDeadEnd({ ...base, scanned_at: new Date(now - DEAD_END_STALE_MS + 60_000).toISOString() }, 1, now, false)).toBe(true);
    });

    it('never greys home, combat homes, k-space or empty scans', () => {
        expect(isDeadEnd(base, 1, now, true)).toBe(false);
        expect(isDeadEnd({ ...base, combat_home: true }, 1, now, false)).toBe(false);
        expect(isDeadEnd({ ...base, solarsystem: { class: 'h' as const } }, 1, now, false)).toBe(false);
        expect(isDeadEnd({ ...base, signatures_count: 0, wormhole_signatures_count: 0 }, 1, now, false)).toBe(false);
    });
});

describe('route texts', () => {
    it('names the chain and where the system leads', () => {
        expect(
            describeChainRoute({
                alias: '1121',
                combat_color: 'red',
                solarsystem: { name: 'Amarr', class: 'h', region: { name: 'Domain' } },
            }),
        ).toBe('Red route 112-1 → highsec exit (Amarr, Domain)');
        expect(describeChainRoute({ alias: '112', combat_color: 'red', solarsystem: { name: 'J123456', class: '3' } })).toBe(
            'Red route 112 → C3 wormhole',
        );
        expect(describeChainRoute({ alias: 'A1', solarsystem: { name: 'Tama', class: 'l', region: { name: 'The Citadel' } } })).toBe(
            'Route A1 → lowsec exit (Tama, The Citadel)',
        );
    });

    it('shortens a route to the nearest highsec', () => {
        expect(
            describeRoute(
                [
                    { name: 'J100001', alias: '1121', class: '3', via: null },
                    { name: 'Tama', class: 'l', via: 'wormhole' },
                    { name: 'Nourvukaiken', class: 'l', via: 'stargate' },
                    { name: 'Kedama', class: 'l', via: 'stargate' },
                    { name: 'Hirri', class: 'h', via: 'stargate' },
                ],
                'Nearest highsec',
            ),
        ).toBe('Nearest highsec: 112-1 → Tama (lowsec) → 3 gates → Hirri — 4 jumps');
    });

    it('keeps single gate jumps and chain aliases', () => {
        expect(
            describeRoute(
                [
                    { name: 'J100001', alias: '12', class: '3', via: null },
                    { name: 'J100002', alias: '1', class: '5', via: 'wormhole' },
                    { name: 'Jita', class: 'h', via: 'wormhole' },
                ],
                'Nearest highsec',
            ),
        ).toBe('Nearest highsec: 12 → 1 → Jita — 2 jumps');
        expect(describeRoute([{ name: 'Jita', class: 'h', via: null }], 'Nearest highsec')).toBe('Nearest highsec: Jita — 0 jumps');
    });

    it('knows the chain colors', () => {
        expect(combatColorLabel('blue')).toBe('Blue');
        expect(combatColorHex('green')).toBe('#22c55e');
        expect(combatColorLabel(null)).toBeNull();
        expect(combatColorLabel('pink')).toBeNull();
    });
});

describe('patch 11: names', () => {
    it('groups chain aliases in threes', () => {
        expect(formatAliasPath('A111102111140')).toBe('A111-102-111-140');
        expect(formatAliasPath('1111111')).toBe('111-111-1');
        expect(formatAliasPath('A0123')).toBe('A012-3');
        expect(formatAliasPath('A01')).toBe('A01');
        expect(formatAliasPath('112')).toBe('112');
        expect(formatAliasPath('Daisy')).toBe('Daisy');
        expect(formatAliasPath('HOMEBASE')).toBe('HOMEBASE');
    });

    it('shows home holes by callsign and never changes the alphabetical scheme', () => {
        expect(displayAlias('A')).toBe('Alpha');
        expect(displayAlias('B')).toBe('Bravo');
        expect(displayAlias('A1')).toBe('A1');
        expect(displayAlias('A1111')).toBe('A111-1');
        expect(displayAlias('A', 'alphabetical')).toBe('A');
        expect(displayAlias(null)).toBe('');
        expect(localSlot('1121')).toBe('1');
    });

    it('uses dashes in main-chain bookmarks', () => {
        const system = { alias: 'A1111', solarsystem: { class: '3' as const, name: 'J1' } };
        const parent = { alias: 'A111', solarsystem: { class: '4' as const, name: 'J0' } };
        expect(formatBookmarkName(system, { signatureId: 'ABC-123' }, FORMATS, 'A111', 'A111', '4')).toBe(' A111-1 ABC C3');
        expect(formatBookmarkName(parent, { signatureId: 'ABC-123' }, FORMATS, 'A1111', 'A1111', '3')).toBe('  * A111-1 ABC C3');
    });

    it('names combat chain holes by their own number, and returns by the full path', () => {
        const member = { alias: '1112', combat_color: 'red', solarsystem: { class: '3' as const, name: 'J2' } };
        // Forward, from 111 into 1112: just "2".
        expect(formatBookmarkName(member, { signatureId: 'LIH-655' }, FORMATS, '111', '111', '5', 'red')).toBe(' 2 LIH C3');
        // Way back, standing in 1112: the whole path.
        const parent = { alias: '111', combat_color: 'red', solarsystem: { class: '5' as const, name: 'J3' } };
        expect(formatBookmarkName(parent, { signatureId: 'MJK-060' }, FORMATS, '1112', '1112', '3', 'red')).toBe('  * 111-2 MJK C3');
        // An unjumped hole in a combat system copied with its claimed number.
        expect(
            buildSignatureBookmark({
                signature: { signature_id: 'LIH-655', ship_size: null, mass_status: null, lifetime: 'healthy' },
                currentSystem: { alias: '111', class: '5', combatColor: 'red' },
                aliases: [],
                formats: FORMATS,
                plannedAlias: '1111',
            }),
        ).toBe(' 1 LIH');
    });
});

describe('patch 11: numbering', () => {
    it('keeps numbers held by red rows and recategorised signatures', () => {
        const planned = planSignatureAliases({
            parentAlias: 'A',
            originIsWormhole: true,
            aliases: [],
            ignoredAlias: 'Daisy',
            signatures: [
                { id: 1, isWormhole: true, isConnected: false, lockedAlias: 'A1', reserveOnly: true },
                { id: 2, isWormhole: false, isConnected: false, lockedAlias: 'A2' },
                { id: 3, isWormhole: true, isConnected: false },
                { id: 4, isWormhole: true, isConnected: false, reserveOnly: true },
            ],
        });
        expect(planned.get(1)).toBe('A1');
        expect(planned.get(3)).toBe('A3');
        expect(planned.get(4)).toBe(undefined);
    });

    it('leaves combat chain holes in limbo until jumped, except the static', () => {
        const planned = planSignatureAliases({
            parentAlias: '1',
            originIsWormhole: true,
            aliases: ['1'],
            limbo: true,
            signatures: [
                { id: 1, isWormhole: true, isConnected: false },
                { id: 2, isWormhole: true, isConnected: false, isStatic: true },
                { id: 3, isWormhole: true, isConnected: false, lockedAlias: '12' },
            ],
        });
        expect(planned.get(1)).toBe(undefined);
        expect(planned.get(2)).toBe('10');
        expect(planned.get(3)).toBe('12');
        // The next jump takes the next free number.
        expect(suggestAlias({ parentAlias: '1', targetIsWormhole: true, originIsWormhole: true, aliases: ['1', '12', '10'] })).toBe('11');
    });
});

