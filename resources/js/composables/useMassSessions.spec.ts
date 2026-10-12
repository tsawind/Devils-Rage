import { homeSideOf } from '@/composables/useMassSessions';
import type { MapStore } from '@/map/store/mapStore';
import type { TMapConnection } from '@/pages/maps';
import { shallowRef } from 'vue';
import { describe, expect, it } from 'vitest';

const pipe = (id: number, from: number, to: number) => ({ id, from_map_solarsystem_id: from, to_map_solarsystem_id: to }) as TMapConnection;

function fakeStore(pipes: TMapConnection[]): MapStore {
    const systems = new Map([1, 2, 3, 4].map((id) => [id, { id, solarsystem_id: 31000000 + id }]));
    return {
        meta: shallowRef({ home_solarsystem_id: 31000001 }),
        systems,
        connections: new Map(pipes.map((connection) => [connection.id, connection])),
    } as unknown as MapStore;
}

describe('patch 37: the home side of a hole being massed', () => {
    it('is the side nearer home, whichever way the pipe was drawn', () => {
        const pipes = [pipe(1, 1, 2), pipe(2, 3, 2), pipe(3, 3, 4)];
        const store = fakeStore(pipes);
        expect(homeSideOf(store, pipes[0])).toBe(1);
        expect(homeSideOf(store, pipes[1])).toBe(2);
        expect(homeSideOf(store, pipes[2])).toBe(3);
    });

    it('falls back to the first side when home is not on the map', () => {
        const pipes = [pipe(1, 2, 3)];
        expect(homeSideOf(fakeStore(pipes), pipes[0])).toBe(2);
    });
});
