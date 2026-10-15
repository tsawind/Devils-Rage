// @vitest-environment happy-dom
import { heldWayBack, offerWayBack } from '@/composables/signatures/wayBack';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/signatureToast', () => ({ signatureToast: { success: vi.fn(), info: vi.fn() } }));

describe('patch 37: an alt jumped', () => {
    it('holds the way back with who jumped and where, even when the mapper has focus', async () => {
        vi.spyOn(document, 'hasFocus').mockReturnValue(true);
        Object.assign(navigator, { clipboard: { writeText: vi.fn().mockResolvedValue(undefined) } });

        await offerWayBack('  * A2 C5', 'way back', { characterName: 'Tsawind', solarsystemId: 31000123 });

        expect(heldWayBack.value?.alt).toEqual({ characterName: 'Tsawind', solarsystemId: 31000123 });
        expect(heldWayBack.value?.name).toBe('  * A2 C5');
    });

    it("copies the main's own way back straight away when the mapper has focus", async () => {
        vi.spyOn(document, 'hasFocus').mockReturnValue(true);
        Object.assign(navigator, { clipboard: { writeText: vi.fn().mockResolvedValue(undefined) } });

        await offerWayBack('  * A2 C5');

        expect(heldWayBack.value).toBeNull();
    });
});
