import AppChatLogController from '@/actions/App/Http/Controllers/AppChatLogController';
import AppLocationController from '@/actions/App/Http/Controllers/AppLocationController';
import { inDesktopApp } from '@/lib/desktopApp';
import { jsonFetch } from '@/lib/jsonFetch';
import { useEventListener } from '@vueuse/core';

type TAppLocation = { character_id: number; solarsystem: string };
type TAppChatLogs = { characters: { name: string; logging: boolean }[] };

/**
 * Patch 46: the desktop app reads EVE's Local chat logs and tells this page the moment one of
 * your characters changes system ("devils-rage-app:location"). The page passes it on to the
 * mapper, which moves the character straight away instead of waiting for ESI.
 *
 * Patch 54: the app also says which EVE clients open on the PC write chat logs at all
 * ("devils-rage-app:chat-logs"); Your accounts warns about the ones with Log Chat to File off.
 */
export function useDesktopAppLocation(): void {
    if (!inDesktopApp()) return;
    useEventListener(window, 'devils-rage-app:location', (event: Event) => {
        const detail = (event as CustomEvent<TAppLocation>).detail;
        if (!detail || !detail.character_id || !detail.solarsystem) return;
        void jsonFetch(AppLocationController.store().url, 'POST', { character_id: detail.character_id, solarsystem: detail.solarsystem });
    });
    useEventListener(window, 'devils-rage-app:chat-logs', (event: Event) => {
        const detail = (event as CustomEvent<TAppChatLogs>).detail;
        if (!detail || !Array.isArray(detail.characters)) return;
        void jsonFetch(AppChatLogController.store().url, 'POST', { characters: detail.characters.slice(0, 50) });
    });
}
