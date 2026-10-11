import { mainSuffix, pilotLabel } from '@/composables/usePilotNames';
import { describe, expect, it } from 'vitest';

describe('patch 37: Alt - Main names', () => {
    const data = { enabled: true, mains: { WickedWrench: 'Tsawind', Tsawind: 'Tsawind' } };

    it('adds the SeAT main to an alt', () => {
        expect(pilotLabel('WickedWrench', data)).toBe('WickedWrench - Tsawind');
    });

    it('shows a main on its own', () => {
        expect(pilotLabel('Tsawind', data)).toBe('Tsawind');
        expect(mainSuffix('Tsawind', data)).toBeNull();
    });

    it('marks a character SeAT does not know', () => {
        expect(pilotLabel('Girl Shady', data)).toBe('Girl Shady - Missing SeAT');
    });

    it('shows names as before until SeAT is set up', () => {
        expect(pilotLabel('Girl Shady', { enabled: false, mains: {} })).toBe('Girl Shady');
        expect(pilotLabel('Girl Shady', null)).toBe('Girl Shady');
        expect(pilotLabel(null, data)).toBe('someone');
    });
});
