import { lastCheckedAt, uncheckedFor } from '@/lib/holeChecked';
import { describe, expect, it } from 'vitest';

describe('patch 37: unchecked holes', () => {
    const base = Date.parse('2026-10-09T10:00:00Z');
    const hour = 60 * 60 * 1000;

    it('takes the latest of creation, status picks, ✓ Checked and logged jumps', () => {
        const connection = {
            created_at: '2026-10-09T10:00:00Z',
            lifetime_status_updated_at: '2026-10-09T11:00:00Z',
            mass_status_updated_at: null,
            checked_at: '2026-10-09T12:00:00Z',
            jumps: [{ created_at: '2026-10-09T13:00:00Z' }],
        };
        expect(lastCheckedAt(connection)).toBe(base + 3 * hour);
    });

    it('shows nothing before 4 h, then how long it has been', () => {
        const connection = { created_at: '2026-10-09T10:00:00Z' };
        expect(uncheckedFor(connection, base + 3 * hour)).toBeNull();
        expect(uncheckedFor(connection, base + 4 * hour + 12 * 60_000)).toBe('4 h 12 min');
    });

    it('knows nothing without any time', () => {
        expect(uncheckedFor({}, base)).toBeNull();
    });
});
