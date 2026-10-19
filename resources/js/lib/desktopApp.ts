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
