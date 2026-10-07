import { displayAlias, guessNextAlias, planSignatureAliases, splitStamp, staticSlotAlias } from '@/lib/alias';
import { getBookmarkTokenValues } from '@/lib/bookmark';
import { describeKspacePath, describeStatics, formatElapsed, nearestKspacePath, newStaticQuestion, parseTargets, staticPipes, targetHits } from '@/lib/rageRoll';
import type { TStringedSolarsystemClass } from '@/types/models';
import { describe, expect, it } from 'vitest';

type TSys = {
    id: number;
    solarsystem_id: number;
    alias: string | null;
    solarsystem: { name: string; class: TStringedSolarsystemClass; region?: { name: string } | null; statics?: { name: string; leads_to: string }[] | null };
};

const sys = (id: number, alias: string | null, name: string, cls: string, extra: Partial<TSys['solarsystem']> = {}): TSys => ({
    id,
    solarsystem_id: 30000000 + id,
    alias,
    solarsystem: { name, class: cls as TStringedSolarsystemClass, ...extra },
});

// Daisy (C5) → Alpha (C6), Daisy → Bravo (C3) → Tama (lowsec)
const daisy = sys(1, 'Daisy', 'J145735', '5', { statics: [{ name: 'V753', leads_to: 'c6' }] });
const alpha = sys(2, 'A', 'J100002', '6');
const bravo = sys(3, 'B', 'J100003', '3');
const tama = sys(4, 'B1', 'Tama', 'l', { region: { name: 'The Citadel' } });
const systems = new Map<number, TSys>([daisy, alpha, bravo, tama].map((system) => [system.id, system]));
const connections = [
    {
        id: 10,
        from_map_solarsystem_id: 1,
        to_map_solarsystem_id: 2,
        created_at: '2026-10-07T20:00:00Z',
        signatures: [{ map_solarsystem_id: 1, signature_id: 'ZGB-123', is_static: true, wormhole: { name: 'V753' } }],
    },
    { id: 11, from_map_solarsystem_id: 1, to_map_solarsystem_id: 3, created_at: '2026-10-07T19:00:00Z', signatures: [] },
    { id: 12, from_map_solarsystem_id: 3, to_map_solarsystem_id: 4, created_at: '2026-10-07T19:00:00Z', signatures: [] },
];

describe('patch 35: rage roll ping text', () => {
    it('finds the nearest way into k-space along the map', () => {
        const path = nearestKspacePath(1, systems, connections);
        expect(path?.map((system) => system.id)).toEqual([3, 4]);
        expect(describeKspacePath(path)).toBe('Bravo → Tama (lowsec, The Citadel) · 2 jumps');
    });

    it('says nothing when there is no way out, or the start is k-space', () => {
        expect(nearestKspacePath(2, systems, [connections[0]])).toBeNull();
        expect(nearestKspacePath(4, systems, connections)).toBeNull();
        expect(describeKspacePath(null)).toBeNull();
    });

    it('names the static and the hole mapped for it', () => {
        expect(describeStatics(daisy, systems, connections)).toBe('V753 → C6 (Alpha, ZGB)');
        expect(describeStatics(daisy, systems, connections.slice(1))).toBe('V753 → C6');
        expect(staticPipes(1, connections).map((pipe) => pipe.id)).toEqual([10]);
    });
});

describe('patch 35: targets', () => {
    it('parses J-codes and names, upper-casing J-codes and dropping repeats', () => {
        expect(parseTargets('j123456, Thera  J123456;J234567')).toEqual(['J123456', 'Thera', 'J234567']);
        expect(parseTargets('   ')).toEqual([]);
    });

    it('a target hit is a pipe off the rolling system into a target, made after the roll started', () => {
        const hits = targetHits(1, [alpha.solarsystem_id], systems, connections, '2026-10-07T19:30:00Z');
        expect(hits).toEqual([{ connectionId: 10, mapSolarsystemId: 2, solarsystemId: alpha.solarsystem_id }]);
        expect(targetHits(1, [bravo.solarsystem_id], systems, connections, '2026-10-07T19:30:00Z')).toEqual([]);
        expect(targetHits(1, [tama.solarsystem_id], systems, connections, null)).toEqual([]);
        expect(targetHits(1, [], systems, connections, null)).toEqual([]);
    });

    it('formats the time since the roll started', () => {
        const start = '2026-10-07T20:00:00Z';
        expect(formatElapsed(start, Date.parse('2026-10-07T20:04:07Z'))).toBe('4:07');
        expect(formatElapsed(start, Date.parse('2026-10-07T21:02:07Z'))).toBe('1:02:07');
        expect(formatElapsed(null, 0)).toBe('0:00');
    });
});

describe('patch 35: New Alpha?', () => {
    const wormhole = { code: 'wormhole', name: 'Wormhole' };
    const old = { id: 1, signature_id: 'ZGB-123', signature_category_id: 5, signature_category: wormhole, map_connection_id: 10, is_static: true, alias: 'A' };
    const fresh = { id: 2, signature_id: 'XYZ-456', signature_category_id: 5, signature_category: wormhole, map_connection_id: null, is_static: false };
    const site = { id: 3, signature_id: 'GAS-111', signature_category_id: 2, signature_category: { code: 'gas', name: 'Gas Site' }, map_connection_id: null };

    it('asks when the paste added a wormhole and a static is already marked', () => {
        expect(newStaticQuestion(new Set([1]), [old, fresh, site])).toEqual({ olds: [old], candidates: [fresh] });
    });

    it('does not ask without an old static or without a new hole', () => {
        expect(newStaticQuestion(new Set([1, 2]), [old, fresh])).toBeNull();
        expect(newStaticQuestion(new Set(), [fresh])).toBeNull();
        expect(newStaticQuestion(new Set([1]), [old, site])).toBeNull();
    });
});

describe('patch 35: stamped old chains', () => {
    it('splits and shows a stamp', () => {
        expect(splitStamp('A1@1958')).toEqual({ base: 'A1', stamp: '1958' });
        expect(splitStamp('A1')).toEqual({ base: 'A1', stamp: null });
        expect(displayAlias('A@1958')).toBe('Alpha@1958');
        expect(displayAlias('A111102@1958')).toBe('A111-102@1958');
    });

    it('a stamped Alpha never blocks the new Alpha', () => {
        const planned = planSignatureAliases({
            parentAlias: 'Daisy',
            originIsWormhole: true,
            ignoredAlias: 'Daisy',
            aliases: ['A@1958', 'A1@1958'],
            signatures: [{ id: 7, isWormhole: true, isConnected: false, isStatic: true, staticIndex: 0 }],
        });
        expect(planned.get(7)).toBe('A');
    });

    it('holes found from a stamped system carry the stamp on', () => {
        expect(guessNextAlias('A1@1958', ['A11@1958', 'A11'])).toBe('A12@1958');
        expect(staticSlotAlias('A1@1958')).toBe('A10@1958');
        const planned = planSignatureAliases({
            parentAlias: 'A1@1958',
            originIsWormhole: true,
            aliases: ['A11@1958'],
            signatures: [
                { id: 1, isWormhole: true, isConnected: false },
                { id: 2, isWormhole: true, isConnected: false, lockedAlias: 'A13@1958' },
            ],
        });
        expect(planned.get(1)).toBe('A12@1958');
        expect(planned.get(2)).toBe('A13@1958');
    });

    it('bookmarks keep the stamp ({alias} and {here})', () => {
        const system = { alias: 'A@1958', occupier_alias: null, solarsystem: { name: 'J100002', class: '6' as const, region: null } };
        const values = getBookmarkTokenValues(system as never, { signatureId: 'ZGB-123' } as never, 'A1@1958', '3');
        expect(values.alias).toBe('Alpha@1958');
        expect(values.here).toBe('A1@1958');
    });
});
