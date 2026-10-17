import { groupByPerson, mainSuffix, personAfter, pilotDetail, pilotLabel } from '@/composables/usePilotNames';
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

describe('patch 39: people', () => {
    const data = {
        enabled: true,
        mains: { WickedWrench: 'Tsawind', Tsawind: 'Tsawind', LuigiSquirrel: 'Tsawind' },
        people: { WickedWrench: 'Tsa', Tsawind: 'Tsa', LuigiSquirrel: 'Tsa', 'Guest Pilot': 'Bob' },
    };

    it('shows the person for every who', () => {
        expect(pilotLabel('LuigiSquirrel', data)).toBe('Tsa');
        expect(pilotLabel('Guest Pilot', data)).toBe('Bob');
        expect(pilotLabel('Girl Shady', data)).toBe('Girl Shady - Missing SeAT');
    });

    it('tells the rest on hover', () => {
        expect(pilotDetail('LuigiSquirrel', data)).toBe('Tsa · main Tsawind · on LuigiSquirrel');
        expect(pilotDetail('Guest Pilot', data)).toBe('Bob · on Guest Pilot');
        expect(pilotDetail('Girl Shady', data)).toBe('Girl Shady · Missing SeAT');
    });

    it('puts the person after a character on map cards', () => {
        expect(personAfter('LuigiSquirrel', data)).toBe('Tsa');
        expect(personAfter('Girl Shady', data)).toBe('Missing SeAT');
        expect(mainSuffix('Guest Pilot', data)).toBeNull();
    });

    it('groups characters by person', () => {
        const groups = groupByPerson(['WickedWrench', 'Girl Shady', 'LuigiSquirrel'], (name) => name, data);
        expect(groups.map((group) => [group.person, group.items.length, group.known])).toEqual([
            ['Tsa', 2, true],
            ['Girl Shady', 1, false],
        ]);
    });
});
