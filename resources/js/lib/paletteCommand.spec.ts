import { lightyears, parsePaletteCommand } from '@/lib/paletteCommand';
import { describe, expect, it } from 'vitest';

describe('patch 37: search commands', () => {
    it('reads a command and the system after it', () => {
        expect(parsePaletteCommand('blops Amamake')).toEqual({ command: 'blops', query: 'Amamake' });
        expect(parsePaletteCommand('cap  Amamake')).toEqual({ command: 'carrier', query: 'Amamake' });
        expect(parsePaletteCommand('dest Jita')).toEqual({ command: 'dest', query: 'Jita' });
    });

    it('leaves a plain search alone (a system with a space in its name too)', () => {
        expect(parsePaletteCommand('Amamake')).toEqual({ command: null, query: 'Amamake' });
        expect(parsePaletteCommand('New Caldari')).toEqual({ command: null, query: 'New Caldari' });
    });

    it('writes light years short', () => {
        expect(lightyears(5.12)).toBe('5.1 LY');
        expect(lightyears(11.8)).toBe('12 LY');
    });
});
