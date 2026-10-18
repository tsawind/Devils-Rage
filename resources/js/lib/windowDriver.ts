import { ref } from 'vue';

/**
 * Patch 39: every browser window or tab has its own main (the "driver"), independent of
 * the login's main. It is kept in this tab's sessionStorage (a refresh keeps it; a new tab
 * starts from the login's main, or from ?as=<characterId> in its address) and sent with
 * every request as X-Map-Driver, so the server acts as this window's main.
 */
export const DRIVER_HEADER = 'X-Map-Driver';
const KEY = 'map-driver-id';

export const driverId = ref<number | null>(null);

function storage(): Storage | null {
    try {
        return typeof window === 'undefined' ? null : window.sessionStorage;
    } catch {
        return null;
    }
}

/** ?as=<id> in an address, or null. */
export function driverFromUrl(href: string): number | null {
    try {
        const value = new URL(href, 'http://local').searchParams.get('as');
        return value && /^\d+$/.test(value) ? Number(value) : null;
    } catch {
        return null;
    }
}

/** This window's main: ?as= first, then what this tab remembered, then the login's main. */
export function resolveDriver(urlDriver: number | null, stored: string | null, loginMain: number | null): number | null {
    if (urlDriver !== null) return urlDriver;
    if (stored && /^\d+$/.test(stored)) return Number(stored);
    return loginMain;
}

/** Patch 39: a "full tab" has one main and no Alt pill (?solo=1 when it was opened; kept for the tab). */
export const soloTab = ref(false);
const SOLO_KEY = 'map-solo-tab';

export function initDriver(loginMain: number | null): number | null {
    try {
        const solo = typeof window !== 'undefined' && new URL(window.location.href).searchParams.get('solo') === '1';
        if (solo) storage()?.setItem(SOLO_KEY, '1');
        soloTab.value = solo || storage()?.getItem(SOLO_KEY) === '1';
    } catch {
        soloTab.value = false;
    }
    const id = resolveDriver(typeof window === 'undefined' ? null : driverFromUrl(window.location.href), storage()?.getItem(KEY) ?? null, loginMain);
    setDriver(id);
    return id;
}

export function setDriver(id: number | null): void {
    driverId.value = id;
    try {
        if (id === null) storage()?.removeItem(KEY);
        else storage()?.setItem(KEY, String(id));
    } catch {
        // No sessionStorage: it holds for this page.
    }
}

/** The headers to send with a request from this window. */
export function driverHeaders(): Record<string, string> {
    return driverId.value === null ? {} : { [DRIVER_HEADER]: String(driverId.value) };
}

/** The map's address opened as another character ("Open as window" / "Full tab"). */
export function urlAs(href: string, characterId: number, solo = false): string {
    const url = new URL(href, typeof window === 'undefined' ? 'http://local' : window.location.origin);
    url.searchParams.set('as', String(characterId));
    if (solo) url.searchParams.set('solo', '1');
    return url.pathname + url.search + url.hash;
}

/** Switch this window's main (the Main pill): kept for this tab, and in the address when it has ?as=. */
export function switchDriver(id: number): void {
    setDriver(id);
    if (typeof window === 'undefined') return;
    if (driverFromUrl(window.location.href) !== null) {
        window.history.replaceState(window.history.state, '', urlAs(window.location.href, id));
    }
}
