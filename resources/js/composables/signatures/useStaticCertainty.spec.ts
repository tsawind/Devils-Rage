import { certaintyInputFor } from '@/composables/signatures/useStaticCertainty';
import type { MapStore } from '@/map/store/mapStore';
import type { TMapConnection, TMapSolarsystem } from '@/pages/maps';
import { describe, expect, it } from 'vitest';

const system = (id: number, cls: string, extra: Partial<TMapSolarsystem> = {}) =>
    ({ id, solarsystem: { class: cls, statics: [{ name: 'K346', leads_to: 'ns' }] }, pending_holes: [], signatures_count: 5, ...extra }) as unknown as TMapSolarsystem;

describe('patch 37: the certain-static check', () => {
    it('a hole with a real type on the far side (Z1 K329) is a K162 here: it is not a static candidate', () => {
        const z11 = system(11, '4');
        const z1 = system(1, 'n');
        const z113 = system(113, '6');
        const toZ1 = { id: 1, from_map_solarsystem_id: 1, to_map_solarsystem_id: 11, type: 'wormhole', signatures: [{ id: 50, map_solarsystem_id: 1, wormhole: { name: 'K329' } }] };
        const toZ113 = { id: 2, from_map_solarsystem_id: 11, to_map_solarsystem_id: 113, type: 'wormhole', signatures: [] };
        const store = {
            systems: new Map([
                [11, z11],
                [1, z1],
                [113, z113],
            ]),
            connections: new Map([
                [1, toZ1 as unknown as TMapConnection],
                [2, toZ113 as unknown as TMapConnection],
            ]),
            // The layout hangs Z11 off Z113 here, so Z1's hole isn't "the way back".
            bandLayout: { value: { parentOf: new Map([[11, 113]]) } },
        } as unknown as MapStore;

        expect(certaintyInputFor(store, z11).unpastedLeadsTo).toEqual([]);
    });
});
