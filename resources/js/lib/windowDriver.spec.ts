import { driverFromUrl, resolveDriver, urlAs } from '@/lib/windowDriver';
import { describe, expect, it } from 'vitest';

describe('patch 39: each window has its own main', () => {
    it('reads ?as= from an address', () => {
        expect(driverFromUrl('/maps/daisy?as=9123&solarsystem_id=5')).toBe(9123);
        expect(driverFromUrl('/maps/daisy')).toBeNull();
        expect(driverFromUrl('/maps/daisy?as=abc')).toBeNull();
    });

    it('prefers the address, then this tab, then the login main', () => {
        expect(resolveDriver(7, '8', 9)).toBe(7);
        expect(resolveDriver(null, '8', 9)).toBe(8);
        expect(resolveDriver(null, null, 9)).toBe(9);
        expect(resolveDriver(null, 'junk', null)).toBeNull();
    });

    it('opens the map as another character', () => {
        expect(urlAs('/maps/daisy?solarsystem_id=5', 42)).toBe('/maps/daisy?solarsystem_id=5&as=42');
        expect(urlAs('/maps/daisy?as=1', 42)).toBe('/maps/daisy?as=42');
    });
});
