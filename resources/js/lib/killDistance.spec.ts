import { killPlace } from '@/lib/killDistance';
import { distanceSoundShape } from '@/lib/distanceSound';
import { describe, expect, it } from 'vitest';

// Daisy(1) – Alpha(2) – A1(3) – A11(4); a rage lane Red1(5) off Alpha; a side chain Zulu(6) – Z1(7).
const systems = [
    { id: 1, solarsystem_id: 101 },
    { id: 2, solarsystem_id: 102 },
    { id: 3, solarsystem_id: 103 },
    { id: 4, solarsystem_id: 104 },
    { id: 5, solarsystem_id: 105, combat_color: 'red' },
    { id: 6, solarsystem_id: 106 },
    { id: 7, solarsystem_id: 107 },
];
const pipes = [
    { from_map_solarsystem_id: 1, to_map_solarsystem_id: 2 },
    { from_map_solarsystem_id: 2, to_map_solarsystem_id: 3 },
    { from_map_solarsystem_id: 3, to_map_solarsystem_id: 4 },
    { from_map_solarsystem_id: 2, to_map_solarsystem_id: 5 },
    { from_map_solarsystem_id: 6, to_map_solarsystem_id: 7 },
];

describe('patch 40: kill sounds by distance from home', () => {
    it('counts jumps from home along the pipes', () => {
        expect(killPlace(101, systems, pipes, 101)).toEqual({ jumps: 0, side: false });
        expect(killPlace(104, systems, pipes, 101)).toEqual({ jumps: 3, side: false });
    });

    it('treats rage lanes and unconnected chains as side chains', () => {
        expect(killPlace(105, systems, pipes, 101)).toEqual({ jumps: 2, side: true });
        expect(killPlace(107, systems, pipes, 101, [1, 6])).toEqual({ jumps: 1, side: true });
    });

    it('knows nothing about systems off the map', () => {
        expect(killPlace(999, systems, pipes, 101)).toBeNull();
    });

    it('gets lower, slower and a little quieter further out', () => {
        const home = distanceSoundShape(0);
        const three = distanceSoundShape(3);
        const far = distanceSoundShape(12);
        expect(home.pitch).toBeGreaterThan(three.pitch);
        expect(three.pitch).toBeGreaterThan(far.pitch);
        expect(home.gap).toBeLessThan(far.gap);
        expect(home.pulses).toBeGreaterThan(far.pulses);
        expect(three.volume).toBe(1);
        expect(far.volume).toBeLessThan(1);
        expect(far.volume).toBeGreaterThan(0.6);
        expect(distanceSoundShape(40)).toEqual(far);
    });
});
