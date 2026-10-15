import { describeKg, parseMillionKg } from '@/map/actions/addMass';
import { describe, expect, it } from 'vitest';

describe('Add mass: what was typed', () => {
    it('reads million kg with commas', () => {
        expect(parseMillionKg('1,200')).toBe(1_200_000_000);
        expect(parseMillionKg('132.4')).toBe(132_400_000);
    });

    it('patch 37: a minus takes mass off', () => {
        expect(parseMillionKg('-132.4')).toBe(-132_400_000);
        expect(parseMillionKg('−32.4')).toBe(-32_400_000);
        expect(describeKg(-132_400_000)).toBe('minus 132.4 million kg');
    });

    it('refuses nothing, zero, words and too much', () => {
        expect(parseMillionKg('')).toBeNull();
        expect(parseMillionKg('0')).toBeNull();
        expect(parseMillionKg('abc')).toBeNull();
        expect(parseMillionKg('-200000')).toBeNull();
    });
});
