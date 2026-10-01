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
});
