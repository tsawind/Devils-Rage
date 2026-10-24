import { estimateConnectionMass } from '@/lib/massEstimate';
import { describe, expect, it } from 'vitest';

/** Patch 52: a mass session's range on the pipe; later marks only narrow it. */
const M = 1_000_000;
const total = 2_000 * M;

describe('patch 52: kept range', () => {
    it('crit marked: the kept top (size − everything logged) beats the crit band', () => {
        const estimate = estimateConnectionMass(total, {
            mass_status: 'critical',
            jumps_mass_sum: 1_621 * M,
            jumps_mass_since_status: 0,
            kept_size_high: 1_800 * M,
            kept_size_from: 'kept',
        });
        expect(estimate?.max).toBe(179 * M);
        expect(estimate?.min).toBe(0);
        expect(estimate?.kept).toEqual({ from: 'kept', conflict: false, top: 179 * M });
    });

    it('keeps both ends while the session runs, minus jumps logged since', () => {
        const estimate = estimateConnectionMass(total, {
            mass_status: 'critical',
            jumps_mass_sum: 1_631 * M,
            jumps_mass_since_status: 10 * M,
            kept_size_low: 1_799 * M,
            kept_size_high: 1_800 * M,
            kept_size_from: 'session',
        });
        expect(estimate?.min).toBe(168 * M);
        expect(estimate?.max).toBe(169 * M);
    });

    it('a Reduced mark that says more is left never raises it: flagged instead', () => {
        const estimate = estimateConnectionMass(total, {
            mass_status: 'reduced',
            mass_status_updated_at: '2026-10-24T18:00:00Z',
            jumps_mass_sum: 1_621 * M,
            jumps_mass_since_status: 0,
            kept_size_high: 1_800 * M,
            kept_size_from: 'kept',
        });
        expect(estimate?.max).toBe(179 * M);
        expect(estimate?.kept?.conflict).toBe(true);
    });

    it('"Keep the range" after that mark stops the question', () => {
        const estimate = estimateConnectionMass(total, {
            mass_status: 'reduced',
            mass_status_updated_at: '2026-10-24T18:00:00Z',
            kept_ack_at: '2026-10-24T18:01:00Z',
            jumps_mass_sum: 1_621 * M,
            jumps_mass_since_status: 0,
            kept_size_high: 1_800 * M,
            kept_size_from: 'kept',
        });
        expect(estimate?.kept?.conflict).toBe(false);
        expect(estimate?.max).toBe(179 * M);
    });

    it('nothing kept: the plain estimate', () => {
        expect(estimateConnectionMass(total, { mass_status: 'critical', jumps_mass_sum: 0 })?.kept).toBeUndefined();
    });
});
