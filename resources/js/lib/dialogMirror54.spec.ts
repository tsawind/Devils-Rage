import { mirrorTargetFor, openPopOutBody } from '@/lib/portalTarget';
import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * Patch 54: a question shows in the main window and the pop-out alike. The copy goes to
 * whichever window the dialog didn't open in.
 */
describe('patch 54: dialogs show in both windows', () => {
    const mainDocument = { name: 'main' } as unknown as Document;
    const popDocument = { name: 'pop-out' } as unknown as Document;
    const mainBody = { ownerDocument: mainDocument, isConnected: true } as unknown as HTMLElement;
    const popBody = { ownerDocument: popDocument, isConnected: true } as unknown as HTMLElement;
    (mainDocument as unknown as { body: HTMLElement }).body = mainBody;

    afterEach(() => {
        openPopOutBody.value = null;
        vi.unstubAllGlobals();
    });

    it('copies a dialog opened in the main window into the pop-out', () => {
        vi.stubGlobal('document', mainDocument);
        openPopOutBody.value = popBody;
        expect(mirrorTargetFor(undefined)).toBe(popBody);
        expect(mirrorTargetFor(mainBody)).toBe(popBody);
    });

    it('copies a dialog opened in the pop-out into the main window', () => {
        vi.stubGlobal('document', mainDocument);
        openPopOutBody.value = popBody;
        expect(mirrorTargetFor(popBody)).toBe(mainBody);
    });

    it('makes no copy while no pop-out is open', () => {
        vi.stubGlobal('document', mainDocument);
        expect(mirrorTargetFor(undefined)).toBeUndefined();
        openPopOutBody.value = { ownerDocument: popDocument, isConnected: false } as unknown as HTMLElement;
        expect(mirrorTargetFor(undefined)).toBeUndefined();
    });
});
