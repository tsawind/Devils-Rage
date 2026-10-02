import { describeEstimate, estimateMass, formatMass, estimateHoleMass, isFrigateHole, pipeWidth } from '@/lib/massEstimate';
import { describe, expect, it } from 'vitest';

const D845 = 5_000_000_000;

describe('patch 12: mass left on a wormhole', () => {
    it('starts at total mass ±10%', () => {
        expect(estimateMass({ totalMass: D845, jumped: 0, status: 'fresh' })).toEqual({ capacity: 5.5e9, min: 4.5e9, max: 5.5e9 });
    });

    it('takes logged jumps off', () => {
        const estimate = estimateMass({ totalMass: D845, jumped: 1.2e9, status: 'fresh' })!;
        expect(estimate.min).toBe(3.3e9);
        expect(Math.round(estimate.max)).toBe(4.3e9);
    });

    it('limits reduced to 2.75 B or less (and at least 10%)', () => {
        const flagOnly = estimateMass({ totalMass: D845, jumped: 0, status: 'reduced' })!;
        expect(flagOnly.max).toBe(2.75e9);
        expect(flagOnly.min).toBe(4.5e8);
        const withJumps = estimateMass({ totalMass: D845, jumped: 2.9e9, status: 'reduced' })!;
        expect(Math.round(withJumps.min)).toBe(1.6e9);
        expect(Math.round(withJumps.max)).toBe(2.6e9);
    });

    it('limits critical to 550 M or less', () => {
        const estimate = estimateMass({ totalMass: D845, jumped: 0, status: 'critical' })!;
        expect(estimate.max).toBe(5.5e8);
        expect(estimate.min).toBe(0);
    });

    it('never goes negative, and has no estimate without a known type', () => {
        const estimate = estimateMass({ totalMass: 2e9, jumped: 3e9, status: 'critical' })!;
        expect(estimate.min).toBe(0);
        expect(estimate.max).toBe(0);
        expect(estimateMass({ totalMass: 0, jumped: 0, status: 'fresh' })).toBe(null);
    });

    it('draws big holes much thicker than small ones (patch 13: straight scale)', () => {
        expect(pipeWidth(5.5e9)).toBe(32);
        expect(Math.round(pipeWidth(2.2e9))).toBe(14);
        expect(Math.round(pipeWidth(1.1e9))).toBe(8);
        expect(Math.round(pipeWidth(5.5e6))).toBe(2);
        expect(pipeWidth(0)).toBe(0);
        expect(isFrigateHole(5_000_000)).toBe(true);
        expect(isFrigateHole(1_000_000_000)).toBe(false);
        expect(isFrigateHole(null)).toBe(false);
    });

    it('describes the estimate', () => {
        expect(formatMass(1.6e9)).toBe('1.6 B');
        expect(formatMass(4.5e8)).toBe('450 M');
        expect(describeEstimate({ capacity: 5.5e9, min: 1.6e9, max: 2.6e9 })).toBe('about 1.6–2.6 B kg left');
    });
});

describe('estimateHoleMass (patch 18)', () => {
    const types = [
        { name: 'H296', target_class: '5', total: 3_300_000_000, maxJump: 1_350_000_000 },
        { name: 'N062', target_class: '5', total: 3_000_000_000, maxJump: 300_000_000 },
        { name: 'E004', target_class: '5', total: 1_000_000_000, maxJump: 5_000_000 },
        { name: 'D845', target_class: 'h', total: 5_000_000_000, maxJump: 375_000_000 },
        { name: 'B274', target_class: 'h', total: 2_000_000_000, maxJump: 300_000_000 },
        { name: 'K162', target_class: '5', total: 1, maxJump: 1 },
    ];

    it('a K162 takes the smallest non-frigate hole leading into its own system', () => {
        expect(estimateHoleMass({ k162Class: '5', classes: ['5', 'h'], types })).toBe(3_000_000_000);
    });

    it('an untyped hole takes the smaller of both ends', () => {
        expect(estimateHoleMass({ classes: ['5', 'h'], types })).toBe(2_000_000_000);
    });

    it('nothing known: no guess', () => {
        expect(estimateHoleMass({ classes: [null, 'unknown'], types })).toBeNull();
        expect(estimateHoleMass({ classes: ['3'], types })).toBeNull();
    });
});
