import { isServerDownResponse, retryDelay } from '@/lib/serverReconnect';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({ router: { on: vi.fn() } }));
vi.mock('vue-sonner', () => ({ toast: { loading: vi.fn(), success: vi.fn() } }));

describe('patch 48: the mapper restarting', () => {
    it("takes the proxy's answers for the mapper being down", () => {
        expect(isServerDownResponse(404, '404 page not found\n')).toBe(true);
        expect(isServerDownResponse(502, '')).toBe(true);
        expect(isServerDownResponse(503, '<html>Service Unavailable</html>')).toBe(true);
        expect(isServerDownResponse(504, null)).toBe(true);
    });

    it("leaves the mapper's own errors alone", () => {
        expect(isServerDownResponse(404, '<!DOCTYPE html><html><title>Not Found</title></html>')).toBe(false);
        expect(isServerDownResponse(500, 'Server Error')).toBe(false);
        expect(isServerDownResponse(403, '404 page not found')).toBe(false);
        expect(isServerDownResponse(undefined, undefined)).toBe(false);
    });

    it('checks often at first, then less often', () => {
        expect(retryDelay(0)).toBe(5_000);
        expect(retryDelay(119_000)).toBe(5_000);
        expect(retryDelay(10 * 60_000)).toBe(15_000);
    });
});
