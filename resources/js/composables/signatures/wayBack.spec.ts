// @vitest-environment happy-dom
import { copyHeldWayBack, heldWayBack, offerWayBack } from '@/composables/signatures/wayBack';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/signatureToast', () => ({ signatureToast: { success: vi.fn(), info: vi.fn() } }));

describe('patch 37: an alt jumped', () => {
    it('holds the way back with who jumped and where, even when the mapper has focus', async () => {
        vi.spyOn(document, 'hasFocus').mockReturnValue(true);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn().mockResolvedValue(undefined) }, configurable: true });

        await offerWayBack('  * A2 C5', 'way back', { characterName: 'Tsawind', solarsystemId: 31000123 });

        expect(heldWayBack.value?.alt).toEqual({ characterName: 'Tsawind', solarsystemId: 31000123 });
        expect(heldWayBack.value?.name).toBe('  * A2 C5');
    });

    it("copies the main's own way back straight away when the mapper has focus", async () => {
        vi.spyOn(document, 'hasFocus').mockReturnValue(true);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn().mockResolvedValue(undefined) }, configurable: true });

        await offerWayBack('  * A2 C5');

        expect(heldWayBack.value).toBeNull();
    });
});

describe('patch 47: in the desktop app', () => {
    it('copies the way back through the app while EVE has focus, and keeps holding it for the next paste', async () => {
        vi.spyOn(document, 'hasFocus').mockReturnValue(false);
        (window as unknown as { __DEVILS_RAGE_APP__?: object }).__DEVILS_RAGE_APP__ = { version: '0.1.4', channel: 'dev' };
        const open = vi.spyOn(window, 'open').mockReturnValue(null);

        await offerWayBack('  * A2 C5');

        expect(open).toHaveBeenCalledWith('https://dr-app.invalid/clipboard?text=%20%20*%20A2%20C5', '_blank');
        expect(heldWayBack.value).toMatchObject({ name: '  * A2 C5', copied: true });

        open.mockClear();
        expect(await copyHeldWayBack()).toBe(true);
        expect(open).toHaveBeenCalledTimes(1);
        expect(heldWayBack.value).toBeNull();
    });

    it('lets a way back the app copied go stale after a while', async () => {
        vi.spyOn(window, 'open').mockReturnValue(null);
        heldWayBack.value = { name: '  * A2 C5', label: 'way back', at: Date.now() - 16 * 60_000, alt: null, copied: true };

        expect(await copyHeldWayBack()).toBe(false);
        expect(heldWayBack.value).toBeNull();
        delete (window as unknown as { __DEVILS_RAGE_APP__?: object }).__DEVILS_RAGE_APP__;
    });
});
