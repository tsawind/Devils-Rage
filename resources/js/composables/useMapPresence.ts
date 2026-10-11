import { echo } from '@laravel/echo-vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Patch 37: who has the map open right now. Every signed-in viewer joins the map's presence
 * channel; managers see the list in the pilots area. Live: in when the map opens, out when
 * it closes (several tabs count once).
 */
export type TPresentUser = { id: number; name: string; main: string | null; permission: string | null };

const present = ref<TPresentUser[]>([]);

export function useMapPresence(mapId: number | null) {
    if (mapId === null) return { present };
    const channel = `MapPresence.${mapId}`;
    onMounted(() => {
        try {
            echo()
                .join(channel)
                .here((users: TPresentUser[]) => (present.value = users))
                .joining((user: TPresentUser) => (present.value = [...present.value.filter((other) => other.id !== user.id), user]))
                .leaving((user: TPresentUser) => (present.value = present.value.filter((other) => other.id !== user.id)));
        } catch {
            // Not signed in (viewer link) or no connection: no list.
        }
    });
    onBeforeUnmount(() => {
        try {
            echo().leave(channel);
        } catch {
            // Nothing to leave.
        }
        present.value = [];
    });
    return { present };
}

export function useMapPresent() {
    return { present };
}
