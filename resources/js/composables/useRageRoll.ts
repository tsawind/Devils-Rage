import MapRageRollController from '@/actions/App/Http/Controllers/MapRageRollController';
import { useShowMap } from '@/composables/useShowMap';
import type { TRageRoll } from '@/lib/rageRoll';
import { tryUseMapStore } from '@/map/store/mapStore';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/** Your last paste (map system id + the signature ids there before it), for the "New Alpha?" question. */
export const lastPaste = ref<{ mapSolarsystemId: number; beforeIds: Set<number>; at: number } | null>(null);

/** The system the RAGE ROLL popup is open for (a solarsystem id), shared by the menu and the dialog. */
const dialogSolarsystemId = ref<number | null>(null);

/**
 * Patch 35: the map's rage roll. Read from the live map store (patched by the
 * metadata broadcast, so everyone sees it start and end), falling back to the page.
 */
export function useRageRoll() {
    const page = useShowMap();

    const rageRoll = computed<TRageRoll | null>(() => {
        const store = tryUseMapStore();
        const meta = store?.meta.value;
        if (meta) return meta.rage_roll ?? null;
        return page.props.map?.rage_roll ?? null;
    });

    const mapSlug = computed(() => page.props.map?.slug ?? '');

    function isRolling(solarsystemId: number | null | undefined): boolean {
        return solarsystemId != null && rageRoll.value?.solarsystem_id === solarsystemId;
    }

    function openRageRoll(solarsystemId: number): void {
        dialogSolarsystemId.value = solarsystemId;
    }

    function closeRageRoll(): void {
        dialogSolarsystemId.value = null;
    }

    function stopRageRoll(): void {
        router.delete(MapRageRollController.destroy(mapSlug.value).url, { preserveScroll: true, preserveState: true, only: ['map'] });
    }

    function setTargets(targets: string[], onError?: (message: string) => void): void {
        router.put(
            MapRageRollController.targets(mapSlug.value).url,
            { targets },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['map'],
                onError: (errors) => onError?.(errors.targets ?? 'Could not save the targets.'),
            },
        );
    }

    function newStatic(oldSignatureId: number, signatureId: number): void {
        router.post(
            MapRageRollController.newStatic(mapSlug.value).url,
            { old_signature_id: oldSignatureId, signature_id: signatureId },
            { preserveScroll: true, preserveState: true, only: ['map', 'selected_map_solarsystem'] },
        );
    }

    return { rageRoll, isRolling, dialogSolarsystemId, openRageRoll, closeRageRoll, stopRageRoll, setTargets, newStatic, mapSlug };
}
