import { recordUndo, restoreSaved, undoToken } from '@/composables/undo/mapUndo';
import { displayAlias } from '@/lib/alias';
import { useMapStore } from '@/map/store/mapStore';
import { TMapSolarsystem } from '@/pages/maps';
import MapSolarsystems from '@/routes/map-solarsystems';
import { router } from '@inertiajs/vue3';
import { deleteSelectedMapSolarsystems } from './deleteSelectedMapSolarsystems';
import { getSelectedMapSolarsystems } from './selectedMapSolarsystems';

/** Patch 21: how a system is named in Undo ("A1", or its system name). */
export function systemLabel(system: Pick<TMapSolarsystem, 'alias'> & { solarsystem?: { name?: string | null } | null }): string {
    return displayAlias(system.alias) || system.solarsystem?.name || 'a system';
}

export function deleteMapSolarsystem(map_solarsystem: TMapSolarsystem, options: { undoable?: boolean } = {}): void {
    if (map_solarsystem.pinned) return;

    const store = useMapStore();

    if (options.undoable !== false && getSelectedMapSolarsystems(store).length) {
        return deleteSelectedMapSolarsystems();
    }

    // Patch 21: the server saves it (signatures, pipes) first, so Undo can put it back.
    const token = undoToken();
    const id = map_solarsystem.id;
    return router.delete(MapSolarsystems.destroy(id).url, {
        data: { undo_token: token },
        preserveState: true,
        preserveScroll: true,
        only: ['map', 'map_navigation', 'selected_map_solarsystem'],
        onSuccess: () => {
            if (options.undoable === false) return;
            let saved = token;
            recordUndo({
                label: `deleted ${systemLabel(map_solarsystem)}`,
                undo: () => {
                    restoreSaved(saved);
                    return true;
                },
                redo: () => {
                    const again = store.systems.get(id);
                    if (!again) return false;
                    saved = deleteAgain(id);
                    return true;
                },
            });
        },
        onError: () => router.reload({ only: ['map'] }),
    });
}

/** Redo of a delete: delete it again, saved under a new token (for the next Undo). */
export function deleteAgain(id: number): string {
    const token = undoToken();
    router.delete(MapSolarsystems.destroy(id).url, {
        data: { undo_token: token },
        preserveState: true,
        preserveScroll: true,
        only: ['map', 'map_navigation', 'selected_map_solarsystem'],
        onError: () => router.reload({ only: ['map'] }),
    });
    return token;
}
