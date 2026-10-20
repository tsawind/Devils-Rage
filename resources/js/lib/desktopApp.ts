/**
 * Patch 46: the Devil's Rage desktop app (tsawind/devils-rage-app) marks the pages it shows
 * with window.__DEVILS_RAGE_APP__ (version and update channel), so the mapper can use what
 * only the app can do (instant location from EVE's chat logs, pop-outs as app windows).
 */
export type TDesktopApp = { version: string; channel: string };

export function desktopApp(): TDesktopApp | null {
    if (typeof window === 'undefined') return null;
    const marker = (window as unknown as { __DEVILS_RAGE_APP__?: TDesktopApp }).__DEVILS_RAGE_APP__;
    return marker ?? null;
}

export function inDesktopApp(): boolean {
    return desktopApp() !== null;
}

/**
 * Patch 47: pages ask the app for things by opening a fake address (https://dr-app.invalid/...):
 * the app catches it and opens nothing, so the mapper never gets access to the app itself.
 */
const APP_SIGNAL = 'https://dr-app.invalid/';

export function appSignal(what: string): boolean {
    if (!inDesktopApp()) return false;
    try {
        window.open(APP_SIGNAL + what, '_blank');
        return true;
    } catch {
        return false;
    }
}

/**
 * Patch 47: the app writes the clipboard for us, even while EVE has focus (a browser can only
 * write it from the focused window). Returns false outside the app.
 */
export function appWriteClipboard(text: string): boolean {
    return appSignal(`clipboard?text=${encodeURIComponent(text)}`);
}

/** Patch 47: keep this window on top of EVE (without taking focus) while a question waits for you. */
export function appAttention(on: boolean): void {
    appSignal(`attention?on=${on ? 1 : 0}`);
}

/**
 * Patch 47: the app saw a probe scan copied in EVE ("devils-rage-app:scan"). The character is the
 * one whose EVE window had focus, matched to a character id through the chat logs.
 */
export type TAppScan = { character_id: number; character_name: string; text: string; claimed?: boolean };
export const APP_SCAN_EVENT = 'devils-rage-app:scan';

/** The last scan the app pasted, so a Ctrl+V of the same text right after doesn't paste it twice. */
let lastAppScan: { text: string; at: number } | null = null;

export function rememberAppScan(text: string): void {
    lastAppScan = { text, at: Date.now() };
}

export function pastedByAppJustNow(text: string): boolean {
    return Boolean(lastAppScan && lastAppScan.text.trim() === text.trim() && Date.now() - lastAppScan.at < 120_000);
}
