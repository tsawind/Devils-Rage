import { buildPlaceholders, holeDestination, placeholderNodeId } from '@/lib/placeholders';
import { describe, expect, it } from 'vitest';

const FORMATS = { bookmark_alias_scheme: 'numeric' as const, bookmark_ignored_alias: 'Daisy' };

describe('patch 12: placeholder systems', () => {
    it('names main-chain holes by the number they have or will get', () => {
        const systems: Parameters<typeof buildPlaceholders>[0] = [
            { id: 1, alias: 'Daisy', solarsystem: { class: '5' }, pending_holes: [{ id: 50, signature_id: 'KXR-123', alias: null, is_static: false, target_class: '3', wormhole: 'X877' }] },
            { id: 2, alias: 'A', solarsystem: { class: '6' }, pending_holes: [
                { id: 60, signature_id: 'JOW-111', alias: 'A1', is_static: false, target_class: '4', wormhole: null },
                { id: 61, signature_id: 'QPL-222', alias: null, is_static: false, target_class: null, wormhole: null },
            ] },
        ];
        const placeholders = buildPlaceholders(systems, FORMATS);
        expect(placeholders.map((placeholder) => placeholder.label)).toEqual(['Bravo', 'A1', 'A2']);
        expect(placeholders[0].detail).toBe('KXR · C3');
        expect(placeholders[2].detail).toBe('QPL · ?');
        expect(placeholders[0].nodeId).toBe(placeholderNodeId(50));
        expect(placeholders[0].parentId).toBe(1);
    });

    it('leaves combat chain holes blank until they are numbered', () => {
        const systems: Parameters<typeof buildPlaceholders>[0] = [
            { id: 3, alias: '11', combat_color: 'red', solarsystem: { class: '4' }, pending_holes: [
                { id: 70, signature_id: 'MVD-333', alias: null, is_static: true, target_class: 'h', wormhole: null },
                { id: 71, signature_id: 'KLR-444', alias: '112', is_static: false, target_class: '3', wormhole: null },
            ] },
        ];
        const placeholders = buildPlaceholders(systems, FORMATS);
        expect(placeholders.map((placeholder) => placeholder.label)).toEqual(['', '112']);
        expect(placeholders[0].detail).toBe('MVD · HSs');
        expect(placeholders[0].color).toBe('red');
    });

    it('describes where a hole leads', () => {
        expect(holeDestination('3')).toBe('C3');
        expect(holeDestination('h')).toBe('HS');
        expect(holeDestination('unknown')).toBe('?');
        expect(holeDestination(null)).toBe('?');
    });

    it('patch 13: shows the statics a system must have that nobody has scanned', () => {
        const systems: Parameters<typeof buildPlaceholders>[0] = [
            { id: 1, alias: 'Daisy', solarsystem: { class: '5', statics: [{ name: 'H296', leads_to: 'c5' }] }, pending_holes: [] },
            // Alpha: a C6 with a V911 (C5) static, found from Daisy through Daisy's own static (H296 on Daisy's side).
            { id: 2, alias: 'A', solarsystem: { class: '6', statics: [{ name: 'V911', leads_to: 'c5' }] }, pending_holes: [] },
        ];
        const connections = [{ from_map_solarsystem_id: 1, to_map_solarsystem_id: 2, signatures: [{ id: 9, map_solarsystem_id: 1, wormhole: { name: 'H296' } }] }];
        const placeholders = buildPlaceholders(systems, FORMATS, new Set(), { connections, parentOf: new Map([[2, 1]]), homeId: 1 });
        const expected = placeholders.filter((placeholder) => placeholder.expected);
        // Daisy's static is linked (H296 on Daisy's side) so only Alpha's is missing: A0, no note.
        expect(expected.map((placeholder) => placeholder.label)).toEqual(['A0']);
        expect(expected[0].detail).toBe('V911 → C5 static · not scanned');
        expect(expected[0].note).toBe(null);
    });

    it('patch 13: notes when the way back could be the static', () => {
        const systems: Parameters<typeof buildPlaceholders>[0] = [
            { id: 2, alias: 'A', solarsystem: { class: '6', statics: [] }, pending_holes: [] },
            // A1: a C5 with a V753 (C6) static, found through a K162 on Alpha's side.
            { id: 3, alias: 'A1', solarsystem: { class: '5', statics: [{ name: 'V753', leads_to: 'c6' }] }, pending_holes: [] },
        ];
        const connections = [{ from_map_solarsystem_id: 2, to_map_solarsystem_id: 3, signatures: [{ id: 9, map_solarsystem_id: 2, wormhole: { name: 'K162' } }] }];
        const expected = buildPlaceholders(systems, FORMATS, new Set(), { connections, parentOf: new Map([[3, 2]]), homeId: null }).filter((placeholder) => placeholder.expected);
        expect(expected).toHaveLength(1);
        expect(expected[0].label).toBe('A10');
        expect(expected[0].note).toBe('maybe *return?');
    });

    it('patch 13: a scanned hole of the static type accounts for it; unlinked systems get none', () => {
        const systems: Parameters<typeof buildPlaceholders>[0] = [
            { id: 3, alias: 'A1', solarsystem: { class: '5', statics: [{ name: 'V753', leads_to: 'c6' }] }, pending_holes: [{ id: 50, signature_id: 'QRT-111', alias: null, is_static: false, target_class: '6', wormhole: 'V753' }] },
            { id: 4, alias: null, solarsystem: { class: '3', statics: [{ name: 'D845', leads_to: 'hs' }] }, pending_holes: [] },
        ];
        const connections = [{ from_map_solarsystem_id: 2, to_map_solarsystem_id: 3, signatures: [] }];
        const expected = buildPlaceholders(systems, FORMATS, new Set(), { connections, parentOf: new Map(), homeId: null }).filter((placeholder) => placeholder.expected);
        expect(expected).toHaveLength(0);
    });
});
