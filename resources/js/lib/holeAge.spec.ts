import { formatSeenAgo, holeAge } from '@/lib/holeAge';
import { describe, expect, it } from 'vitest';

const H = 3600 * 1000;
const start = Date.parse('2026-10-01T00:00:00Z');
const iso = (ms: number) => new Date(ms).toISOString();

describe('holeAge', () => {
    it('labels the age from the earliest sighting', () => {
        const age = holeAge({ seen: [iso(start + 2 * H), iso(start)], now: start + 14 * H + 20 * 60 * 1000 });
        expect(age?.label).toBe('seen 14 h ago');
        expect(formatSeenAgo(25 * 60)).toBe('seen 25 min ago');
        expect(formatSeenAgo(3 * 86400)).toBe('seen 3 d ago');
    });

    it('nothing known: no age', () => {
        expect(holeAge({ seen: [null, undefined], now: start })).toBeNull();
    });

    it('likely EOL within 4 h of a 16 h hole, not before', () => {
        const base = { seen: [iso(start)], maximumLifetime: 16 * 3600 };
        expect(holeAge({ ...base, now: start + 11 * H })?.likelyEol).toBe(false);
        expect(holeAge({ ...base, now: start + 12 * H })?.likelyEol).toBe(true);
    });

    it('a check after it should have gone EOL hides the mark; one before does not', () => {
        const base = { seen: [iso(start)], maximumLifetime: 24 * 3600, now: start + 21 * H };
        expect(holeAge({ ...base, lifetimeStatus: 'healthy', lifetimeUpdatedAt: iso(start + 20.5 * H) })?.likelyEol).toBe(false);
        expect(holeAge({ ...base, lifetimeStatus: 'healthy', lifetimeUpdatedAt: iso(start + 5 * H) })?.likelyEol).toBe(true);
    });

    it('already marked EOL, or unknown lifetime: no faint mark', () => {
        expect(holeAge({ seen: [iso(start)], maximumLifetime: 16 * 3600, lifetimeStatus: 'eol', now: start + 13 * H })?.likelyEol).toBe(false);
        expect(holeAge({ seen: [iso(start)], now: start + 50 * H })?.likelyEol).toBe(false);
    });
});
