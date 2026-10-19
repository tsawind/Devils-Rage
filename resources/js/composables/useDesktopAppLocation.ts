import AppLocationController from '@/actions/App/Http/Controllers/AppLocationController';
import { inDesktopApp } from '@/lib/desktopApp';
import { jsonFetch } from '@/lib/jsonFetch';
import { useEventListener } from '@vueuse/core';

type TAppLocation = { character_id: number; solarsystem: string };

/**
 * Patch 46: the desktop app reads EVE's Local chat logs and tells this page the moment one of
 * your characters changes system ("devils-rage-app:location"). The page passes it on to the
 * mapper, which moves the character straight away instead of waiting for ESI.
 */
export function useDesktopAppLocation(): void {
    if (!inDesktopApp()) return;
    useEventListener(window, 'devils-rage-app:location', (event: Event) => {
        const detail = (event as CustomEvent<TAppLocation>).detail;
        if (!detail || !detail.character_id || !detail.solarsystem) return;
        void jsonFetch(AppLocationController.store().url, 'POST', { character_id: detail.character_id, solarsystem: detail.solarsystem });
    });
}
