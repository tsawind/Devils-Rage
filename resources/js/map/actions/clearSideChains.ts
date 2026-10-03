import { displayAlias } from '@/lib/alias';
import type { MapStore } from '@/map/store/mapStore';
import MapSelection from '@/routes/map-selection';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Patch 19: right-click the map → Clear side chains ▸ (all of them, or one
 * lettered chain). The confirmation lists each chain with a checkbox; pinned
 * systems and the map's home stay, like Clear <color> chain.
 */
export type TSideChainSummary = { rootId: number; name: string; removeIds: number[]; pinned: number };

/** The side chains on the map (from the layout), with what clearing each removes. */
export function sideChainSummaries(store: MapStore): TSideChainSummary[] {
    const layout = store.bandLayout.value;
    if (!layout) return [];
    const homeSolarsystemId = store.meta.value?.home_solarsystem_id ?? null;
    return layout.sideChains
        .map((chain) => {
            const members = chain.memberIds.map((id) => store.systems.get(id)).filter((system) => system !== undefined);
            const root = store.systems.get(chain.rootId);
            const removeIds = members.filter((system) => !system.pinned && system.solarsystem_id !== homeSolarsystemId).map((system) => system.id);
            return {
                rootId: chain.rootId,
                name: root ? displayAlias(root.alias) || root.solarsystem.name : 'Side chain',
                removeIds,
                pinned: members.length - removeIds.length,
            };
        })
        .filter((chain) => chain.removeIds.length > 0 || chain.pinned > 0);
}

/** The chains the confirmation opens with ticked (null = closed). */
export const clear_side_chains = ref<number[] | null>(null);

export function openClearSideChains(rootIds: number[]): void {
    clear_side_chains.value = rootIds;
}

export function clearSideChainSystems(ids: number[], label: string): void {
    if (ids.length === 0) return;
    router.delete(MapSelection.destroy().url, {
        data: { map_solarsystem_ids: ids },
        preserveState: true,
        preserveScroll: true,
        only: ['map', 'map_navigation', 'selected_map_solarsystem'],
        onSuccess: () => toast.success(`Cleared ${label}`, { description: `${ids.length} system${ids.length === 1 ? '' : 's'} removed.` }),
        onError: () => router.reload({ only: ['map'] }),
    });
}
