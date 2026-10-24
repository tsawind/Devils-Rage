import RallyPointController from '@/actions/App/Http/Controllers/RallyPointController';
import { useMap } from '@/composables/useMap';
import { router } from '@inertiajs/vue3';
import { computed, type MaybeRefOrGetter, ref, toValue } from 'vue';

/**
 * Patch 53: right-click → 📣 PING: the system becomes the rally point and the ping dialog opens
 * with the message box ready (type, Enter sends). `note` starts the message (a dotted hole says which).
 */
export const pingRequest = ref<{ note: string; at: number } | null>(null);

export function useRallyPoint(solarsystemId: MaybeRefOrGetter<number>) {
    const map = useMap();

    const isRally = computed(() => map.value.rally_solarsystem_id === toValue(solarsystemId));

    function toggleRallyPoint() {
        const id = toValue(solarsystemId);
        router.post(
            RallyPointController.store(map.value.slug).url,
            { solarsystem_id: isRally.value ? null : id },
            { preserveScroll: true, preserveState: true },
        );
    }

    /** Patch 53: make this the rally point (if it isn't) and open the ping dialog. */
    function pingHere(note = ''): void {
        const id = toValue(solarsystemId);
        const open = () => (pingRequest.value = { note, at: Date.now() });
        if (map.value.rally_solarsystem_id === id) {
            open();
            return;
        }
        router.post(RallyPointController.store(map.value.slug).url, { solarsystem_id: id }, { preserveScroll: true, preserveState: true, onSuccess: open });
    }

    return {
        isRally,
        toggleRallyPoint,
        pingHere,
    };
}
