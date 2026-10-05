import { shortName, shortOccupier } from '@/lib/shortName';
import { describe, expect, it } from 'vitest';

describe('patch 25: names in the signature list', () => {
    it.each([
        ['A0', 'A0'],
        ['A121', 'A121'],
        ['Daisy', 'Daisy'],
        ['Charlie', 'C…lie'],
        ['A112-112-131-1', 'A…1-1'],
        ['A112-112-132-1', 'A…2-1'],
        ['', ''],
    ])('%s → %s', (name, short) => {
        expect(shortName(name)).toBe(short);
    });
});

describe('patch 27: occupier alias on a map card', () => {
    it.each([
        ['Pagids Legion', 'Pagids…'],
        ['Wormageddon', 'Wormage…'],
        ['Hard Knocks Citizens', 'Hard Kn…'],
        ['SWA', 'SWA'],
        ['Wormgedn', 'Wormgedn'],
        ['  Pagids  ', 'Pagids'],
    ])('%s → %s', (name, short) => {
        expect(shortOccupier(name)).toBe(short);
    });
});
