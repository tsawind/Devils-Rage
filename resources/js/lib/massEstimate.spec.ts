import { describeEstimate, estimateMass, formatMass, guessedHoleMass, isFrigateHole, pipeWidth } from '@/lib/massEstimate';
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

describe('guessedHoleMass (patch 17)', () => {
    it('C5 / C6 to C5 / C6, lowsec or nullsec: 3,300 M', () => {
        expect(guessedHoleMass('5', '6')).toBe(3_300_000_000);
        expect(guessedHoleMass('6', '6')).toBe(3_300_000_000);
        expect(guessedHoleMass('l', '5')).toBe(3_300_000_000);
        expect(guessedHoleMass('6', 'n')).toBe(3_300_000_000);
        expect(guessedHoleMass(5, 'C5')).toBe(3_300_000_000);
    });

    it('C5 / C6 to highsec: 3,000 M', () => {
        expect(guessedHoleMass('5', 'h')).toBe(3_000_000_000);
        expect(guessedHoleMass('h', '6')).toBe(3_000_000_000);
    });

    it('anything else: no guess', () => {
        expect(guessedHoleMass('4', '5')).toBeNull();
        expect(guessedHoleMass('3', 'h')).toBeNull();
        expect(guessedHoleMass('l', 'n')).toBeNull();
        expect(guessedHoleMass('5', 'unknown')).toBeNull();
        expect(guessedHoleMass('5', null)).toBeNull();
        expect(guessedHoleMass('12', '5')).toBeNull();
    });
});

