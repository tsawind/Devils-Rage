import { describe, expect, it } from 'vitest';
import { planChainBeyond } from './chainBeyond';

const sys = (id: number, extra: { pinned?: boolean } = {}) => ({ id, solarsystem_id: 1000 + id, ...extra });
const link = (from: number, to: number) => ({ from_map_solarsystem_id: from, to_map_solarsystem_id: to });

// Daisy(1) - A(2) - A1(3) - A11(4)
//                  \ A2(5)
// Daisy(1) - B(6)
const systems = [sys(1), sys(2), sys(3), sys(4), sys(5), sys(6)];
const links = [link(1, 2), link(2, 3), link(3, 4), link(2, 5), link(1, 6)];

describe('planChainBeyond', () => {
    it('removes the system and everything that only reaches home through it', () => {
        expect(planChainBeyond(2, systems, links, 1001).removed.sort()).toEqual([2, 3, 4, 5]);
        expect(planChainBeyond(3, systems, links, 1001).removed.sort()).toEqual([3, 4]);
    });

    it('never removes the home', () => {
        expect(planChainBeyond(1, systems, links, 1001).removed).toEqual([]);
    });

    it('keeps a system with another way home', () => {
        expect(planChainBeyond(2, systems, [...links, link(5, 6)], 1001).removed.sort()).toEqual([2, 3, 4]);
    });

    it('keeps pinned systems beyond, with what hangs off them', () => {
        const pinned = [sys(1), sys(2), sys(3, { pinned: true }), sys(4), sys(5), sys(6)];
        const plan = planChainBeyond(2, pinned, links, 1001);
        expect(plan.removed.sort()).toEqual([2, 5]);
        expect(plan.kept).toEqual([3]);
    });

    it('keeps the rally point', () => {
        expect(planChainBeyond(2, systems, links, 1001, 1004).removed.sort()).toEqual([2, 5]);
    });
});
