import { ref, shallowRef } from 'vue';

/**
 * Patch 39: the scanner pop-out. A small always-on-top window (Chrome / Edge Document
 * Picture-in-Picture) for one character: where it is, its way back, the systems around it
 * and its system's signatures (paste with Ctrl+V into it). One per browser tab. Browsers
 * without Document Picture-in-Picture open the character in its own window instead.
 */
export type TPopOut = { characterId: number; name: string; color: string };

export const popOut = ref<TPopOut | null>(null);
export const popOutWindow = shallowRef<Window | null>(null);

/** Edge colour of the pop-out (and its pin on the main map). */
export const POP_OUT_COLOR = '#22d3ee';

type TDocumentPip = { requestWindow: (options: { width: number; height: number }) => Promise<Window> };

export function canPopOut(): boolean {
    return typeof window !== 'undefined' && 'documentPictureInPicture' in window;
}

/** Copies the page's styles into the pop-out so it looks like the mapper. */
function copyStyles(target: Window): void {
    for (const sheet of [...document.styleSheets]) {
        try {
            const style = target.document.createElement('style');
            style.textContent = [...sheet.cssRules].map((rule) => rule.cssText).join('\n');
            target.document.head.appendChild(style);
        } catch {
            if (sheet.href) {
                const link = target.document.createElement('link');
                link.rel = 'stylesheet';
                link.href = sheet.href;
                target.document.head.appendChild(link);
            }
        }
    }
    target.document.documentElement.className = document.documentElement.className;
    target.document.body.className = 'bg-black text-foreground antialiased';
}

/** Opens (or moves) the pop-out to this character. Must run from a click. Returns false when not supported. */
export async function openPopOut(characterId: number, name: string): Promise<boolean> {
    if (!canPopOut()) return false;
    popOut.value = { characterId, name, color: POP_OUT_COLOR };
    if (popOutWindow.value && !popOutWindow.value.closed) return true;

    const pip = (window as unknown as { documentPictureInPicture: TDocumentPip }).documentPictureInPicture;
    const opened = await pip.requestWindow({ width: 640, height: 400 });
    copyStyles(opened);
    opened.document.title = `${name} · scanner`;
    opened.addEventListener('pagehide', () => {
        popOutWindow.value = null;
        popOut.value = null;
    });
    popOutWindow.value = opened;
    return true;
}

export function closePopOut(): void {
    popOutWindow.value?.close();
    popOutWindow.value = null;
    popOut.value = null;
}

/** Whether a character is the one in the pop-out (the main map shows a pin for it). */
export function isPoppedOut(characterId: number): boolean {
    return popOut.value?.characterId === characterId && popOutWindow.value !== null;
}
