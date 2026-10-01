import { cleanupRows } from '@/lib/chainCleanup';
import { describe, expect, it } from 'vitest';

const systems = [
    { id: 4, alias: 'D1' },
    { id: 5, alias: '1', combat_color: 'red' },
    { id: 6, alias: '11', combat_color: 'red' },
    { id: 7, alias: '12', combat_color: 'red' },
    { id: 8, alias: 'D11' },
];
const connections = [
    { from_map_solarsystem_id: 4, to_map_solarsystem_id: 5 },
    { from_map_solarsystem_id: 5, to_map_solarsystem_id: 6 },
    { from_map_solarsystem_id: 5, to_map_solarsystem_id: 7 },
    { from_map_solarsystem_id: 4, to_map_solarsystem_id: 8 },
];
const parentOf = new Map([
    [5, 4],
    [6, 5],
    [7, 5],
    [8, 4],
]);

describe('patch 14: cleaning a combat chain up, one system at a time', () => {
    it('offers only the chain systems hanging off where you stand, with the next free name', () => {
        const rows = cleanupRows({ here: systems[0], systems, connections, parentOf, taken: ['D1', 'D11'], ignoredAlias: 'Daisy' });
        // Red 1 would be D11, but D11 is taken: D12.
        expect(rows).toEqual([{ systemId: 5, digit: '1', from: '1', to: 'D12' }]);
    });

    it('leaves systems further out alone until their parent is converted', () => {
        // Red 1 not converted yet: standing in it offers nothing.
        expect(cleanupRows({ here: systems[1], systems, connections, parentOf, taken: [], ignoredAlias: 'Daisy' })).toEqual([]);
        // Once it is D12 (no color), its red children follow your in-game digits.
        const converted = systems.map((system) => (system.id === 5 ? { id: 5, alias: 'D12' } : system));
        const rows = cleanupRows({ here: converted[1], systems: converted, connections, parentOf, taken: ['D1', 'D11', 'D12'], ignoredAlias: 'Daisy' });
        expect(rows.map((row) => [row.digit, row.to])).toEqual([
            ['1', 'D121'],
            ['2', 'D122'],
        ]);
    });

    it('does nothing while the chain still has its home', () => {
        const withHome = [...systems.map((system) => (system.id === 4 ? { ...system, combat_color: 'red', combat_home: true } : system))];
        expect(cleanupRows({ here: { id: 9, alias: 'D' }, systems: withHome, connections, parentOf, taken: [] })).toEqual([]);
    });

    it('offers every chain system hanging straight off where you stand', () => {
        const twoOff = [...systems, { id: 10, alias: '2', combat_color: 'red' }];
        const links = [...connections, { from_map_solarsystem_id: 4, to_map_solarsystem_id: 10 }];
        const rows = cleanupRows({ here: twoOff[0], systems: twoOff, connections: links, parentOf: new Map([...parentOf, [10, 4]]), taken: ['D1', 'D11'], ignoredAlias: 'Daisy' });
        expect(rows.map((row) => [row.from, row.to])).toEqual([
            ['1', 'D12'],
            ['2', 'D13'],
        ]);
    });
});
