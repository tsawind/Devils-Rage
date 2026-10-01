import { planChainClear } from '@/lib/chainClear';
import { describe, expect, it } from 'vitest';

describe('patch 12: clearing a combat chain', () => {
    const systems = [
        { id: 1, solarsystem_id: 100 }, // Daisy
        { id: 2, solarsystem_id: 101, combat_color: 'red', combat_home: true }, // Alpha, Red's home
        { id: 3, solarsystem_id: 102, combat_color: 'red' }, // Red 1
        { id: 4, solarsystem_id: 103, combat_color: 'red' }, // Red 11, loops into B111
        { id: 5, solarsystem_id: 104 }, // B111
        { id: 6, solarsystem_id: 105, combat_color: 'red' }, // Red 111
        { id: 7, solarsystem_id: 106, combat_color: 'blue', combat_home: true },
    ];
    const connections = [
        { from_map_solarsystem_id: 1, to_map_solarsystem_id: 2 },
        { from_map_solarsystem_id: 2, to_map_solarsystem_id: 3 },
        { from_map_solarsystem_id: 3, to_map_solarsystem_id: 4 },
        { from_map_solarsystem_id: 4, to_map_solarsystem_id: 6 },
        { from_map_solarsystem_id: 4, to_map_solarsystem_id: 5 },
    ];

    it('keeps the home and systems still attached elsewhere, removes the rest', () => {
        const plan = planChainClear('red', systems, connections, 100);
        expect(plan.homeId).toBe(2);
        expect(plan.homeStays).toBe(true);
        expect(plan.kept.sort()).toEqual([2, 4]);
        expect(plan.removed.sort()).toEqual([3, 6]);
    });

    it('removes an unlinked combat home too', () => {
        const plan = planChainClear('blue', systems, connections, 100);
        expect(plan.homeStays).toBe(false);
        expect(plan.removed).toEqual([7]);
    });
});
