import MapPresenceController from '@/actions/App/Http/Controllers/MapPresenceController';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Patch 37: who has the map open right now. Every open map (pilots and viewer-link
 * visitors) checks in with the server every 45 s; only managers fetch the list, so it
 * never reaches anyone else's browser. Someone counts as here for 100 s after their
 * last check-in; several tabs count once (with a tab count).
 */
export type TPresentUser = { id: string; name: string; main: string | null; permission: string | null; kind: 'pilot' | 'link'; tabs: number };

const CHECK_IN_MS = 45_000;
const REFRESH_MS = 30_000;

const present = ref<TPresentUser[]>([]);

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function checkIn(mapSlug: string): void {
    fetch(MapPresenceController.store(mapSlug).url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
    }).catch(() => undefined);
}

async function refresh(mapSlug: string): Promise<void> {
    try {
        const response = await fetch(MapPresenceController.index(mapSlug).url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const body = (await response.json()) as { present?: TPresentUser[] };
        present.value = body.present ?? [];
    } catch {
        // Offline for a moment: keep the last list.
    }
}

export function useMapPresence(mapSlug: string | null, isManager: () => boolean) {
    if (mapSlug === null) return { present };
    const timers: number[] = [];
    onMounted(() => {
        checkIn(mapSlug);
        timers.push(window.setInterval(() => checkIn(mapSlug), CHECK_IN_MS));
        if (isManager()) {
            window.setTimeout(() => void refresh(mapSlug), 1_000);
            timers.push(window.setInterval(() => void refresh(mapSlug), REFRESH_MS));
        }
    });
    onBeforeUnmount(() => {
        timers.forEach((timer) => window.clearInterval(timer));
        present.value = [];
    });
    return { present };
}

export function useMapPresent() {
    return { present };
}
