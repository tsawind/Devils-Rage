import { useOnClient } from '@/composables/useOnClient';
import useUser from '@/composables/useUser';
import { getUserChannelName } from '@/const/channels';
import { CombatModeTurnedOffEvent, UserCharacterStatusUpdatedEvent } from '@/const/events';
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
    });
}
