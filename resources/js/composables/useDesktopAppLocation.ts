import AppChatLogController from '@/actions/App/Http/Controllers/AppChatLogController';
import AppLocationController from '@/actions/App/Http/Controllers/AppLocationController';
import { inDesktopApp } from '@/lib/desktopApp';
import { jsonFetch } from '@/lib/jsonFetch';
import { useStaticData } from '@/composables/useStaticData';
import type { TCharacter } from '@/types/models';
import { usePage } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import { computed } from 'vue';

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
    const page = usePage();
    const { staticData } = useStaticData();
    const systemIdByName = computed(() => new Map((staticData.value?.solarsystems ?? []).map((system) => [system.name, system.id])));

    useEventListener(window, 'devils-rage-app:location', async (event: Event) => {
        const detail = (event as CustomEvent<TAppLocation>).detail;
        if (!detail || !detail.character_id || !detail.solarsystem) return;
        const answer = await jsonFetch<{ moved: boolean }>(AppLocationController.store().url, 'POST', { character_id: detail.character_id, solarsystem: detail.solarsystem });
        // Patch 54: the server has it now; this page moves the character itself instead of waiting
        // for the server's broadcast and a fresh download of everyone's location (a round trip less,
        // so the jump is mapped sooner). A later download says the same, so nothing moves twice.
        if (!answer?.moved) return;
        const systemId = systemIdByName.value.get(detail.solarsystem);
        const characters = (page.props as { map_characters?: TCharacter[] | null }).map_characters;
        const character = characters?.find((candidate) => candidate.id === detail.character_id);
        if (!systemId || !character?.status || character.status.solarsystem_id === systemId) return;
        character.status = { ...character.status, solarsystem_id: systemId, station_id: null, structure_id: null };
    });
    useEventListener(window, 'devils-rage-app:chat-logs', (event: Event) => {
        const detail = (event as CustomEvent<TAppChatLogs>).detail;
        if (!detail || !Array.isArray(detail.characters)) return;
        void jsonFetch(AppChatLogController.store().url, 'POST', { characters: detail.characters.slice(0, 50) });
    });
}
