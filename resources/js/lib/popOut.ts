import { appPopOutIds, appSignal, appWriteClipboard, inDesktopApp } from '@/lib/desktopApp';
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
export async function openPopOut(characterId: number, name: string, color: string = POP_OUT_COLOR): Promise<boolean> {
    if (!canPopOut()) return false;
    // Patch 41: the pop-out wears its character's colour.
    popOut.value = { characterId, name, color };
    if (popOutWindow.value && !popOutWindow.value.closed) return true;

    const pip = (window as unknown as { documentPictureInPicture: TDocumentPip }).documentPictureInPicture;
    // The signature panel is 392 px; the map takes the rest (or none when it was folded away last time).
    const mapOpen = (() => {
        try {
            return window.localStorage.getItem('popout-map-open') !== 'false';
        } catch {
            return true;
        }
    })();
    // Patch 42f: the signature panel's width follows its zoom.
    const zoom = (() => {
        try {
            const stored = Number(window.localStorage.getItem('popout-signatures-zoom'));
            return stored >= 0.5 && stored <= 1.2 ? stored : 0.8;
        } catch {
            return 0.8;
        }
    })();
    const signatures = Math.round(490 * zoom) + 18;
    const opened = await pip.requestWindow({ width: mapOpen ? signatures + 488 : signatures, height: 440 });
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
    return (popOut.value?.characterId === characterId && popOutWindow.value !== null) || appPopOutIds.value.includes(characterId);
}

/** Patch 51b: closes a character's pop-out, this window's or (in the app) its own window's. */
export function closeCharacterPopOut(characterId: number): void {
    if (popOut.value?.characterId === characterId && popOutWindow.value) closePopOut();
    if (appPopOutIds.value.includes(characterId)) appSignal(`popout-close?character=${characterId}`);
}

/** Patch 51b: brings a character's pop-out to the front. */
export function focusCharacterPopOut(characterId: number): void {
    if (popOut.value?.characterId === characterId && popOutWindow.value) {
        try {
            popOutWindow.value.focus();
        } catch {
            // Gone already.
        }
        if (inDesktopApp()) appSignal('popout-focus?own=1');
        return;
    }
    if (appPopOutIds.value.includes(characterId)) appSignal(`popout-focus?character=${characterId}`);
}

const appClipboard = {
    writeText: async (text: string): Promise<void> => {
        if (!appWriteClipboard(text)) throw new Error('The app could not write the clipboard.');
    },
    readText: (): Promise<string> => navigator.clipboard.readText(),
} as unknown as Clipboard;

/**
 * The clipboard to write to: the pop-out's while it has focus (a browser only lets the
 * focused window write the clipboard), else this window's.
 */
export function focusedClipboard(): Clipboard {
    // Patch 47: in the desktop app the app writes the clipboard (works while EVE has focus).
    if (inDesktopApp()) return appClipboard;
    const pop = popOutWindow.value;
    try {
        // Patch 42d: the pop-out's whenever this window hasn't got focus (its own hasFocus can say no while you're in it).
        if (pop && !pop.closed && (pop.document.hasFocus() || !document.hasFocus())) return pop.navigator.clipboard;
    } catch {
        // Pop-out gone: use this window.
    }
    return navigator.clipboard;
}

/** Whether this window or the pop-out has focus. */
export function anyWindowFocused(): boolean {
    try {
        return document.hasFocus() || Boolean(popOutWindow.value && !popOutWindow.value.closed && popOutWindow.value.document.hasFocus());
    } catch {
        return document.hasFocus();
    }
}
