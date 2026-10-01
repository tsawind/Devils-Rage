import { useOnClient } from '@/composables/useOnClient';
import useUser from '@/composables/useUser';
import { getUserChannelName } from '@/const/channels';
import { CombatModeTurnedOffEvent, MapNoticeEvent, UserCharacterStatusUpdatedEvent } from '@/const/events';
import { visibleBookmarkName } from '@/lib/bookmark';
import { pendingHoleBookmark } from '@/map/holeBookmark';
import { useMapStore } from '@/map/store/mapStore';
import { router, usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { toast } from 'vue-sonner';

/**
 * Subscribe to the authenticated user's private channel and refresh the shared
 * `auth` prop whenever one of their characters' status changes. This keeps the
 * character list (e.g. online state in context menus) current even for
 * characters that are not on the map being viewed.
 */
export function useUserEvents() {
    const user = useUser();
    const page = usePage();

    useOnClient(() => {
        const userId = user.value?.id;
        if (!userId) {
            return;
        }

        useEcho(getUserChannelName(userId), UserCharacterStatusUpdatedEvent, () => {
            router.reload({ only: ['auth'] });
        });

        // Patch 12: someone cleared the combat chain you were working (or the
        // last chain): your Combat button was turned off. The notice stays
        // until you close it.
        useEcho<{ map_id: number; message: string; detail: string }>(getUserChannelName(userId), CombatModeTurnedOffEvent, (event) => {
            toast.warning(event.message, { description: event.detail, duration: Infinity, closeButton: true });
            const props = page.props as unknown as { map?: { id?: number } };
            if (props.map?.id === event.map_id) router.reload({ only: ['map_user_settings'] });
        });

        // Patch 13: a notice for you alone (e.g. someone took your armed number).
        // Clicking Copy copies the hole's new bookmark name.
        useEcho<{ map_id: number; message: string; detail: string; signature_id: number | null }>(getUserChannelName(userId), MapNoticeEvent, (event) => {
            toast.warning(event.message, {
                description: event.detail,
                duration: Infinity,
                closeButton: true,
                action: event.signature_id
                    ? {
                          label: 'Copy',
                          onClick: () => copyHoleBookmark(event.signature_id as number),
                      }
                    : undefined,
            });
        });
    });
}

/** Copy an unjumped hole's bookmark name, as the map has it now. */
function copyHoleBookmark(signatureId: number): void {
    let store;
    try {
        store = useMapStore();
    } catch {
        return;
    }
    for (const system of store.systems.values()) {
        const hole = system.pending_holes?.find((candidate) => candidate.id === signatureId);
        if (!hole) continue;
        const name = pendingHoleBookmark(store, system, hole, hole.alias);
        if (!name) return;
        navigator.clipboard.writeText(name).catch(() => undefined);
        toast.success('Copied bookmark to clipboard', { description: visibleBookmarkName(name) });
        return;
    }
}
