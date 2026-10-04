import { recordUndo, restoreSaved, undoToken } from '@/composables/undo/mapUndo';
import MapSelection from '@/routes/map-selection';
import { router } from '@inertiajs/vue3';

/**
 * Patch 21: delete several systems at once (a selection, side chains, orphans,
 * clear map) so Undo can put them all back in one step: the server saves them
 * (with their signatures and pipes) under a token first.
 */
export function deleteSystemsUndoable(ids: number[], label: string, options: { onSuccess?: () => void } = {}): void {
    if (ids.length === 0) return;
    let saved = send(ids, options.onSuccess, () =>
        recordUndo({
            label,
            undo: () => {
                restoreSaved(saved);
                return true;
            },
            redo: () => {
                saved = send(ids);
                return true;
            },
        }),
    );
}

function send(ids: number[], onSuccess?: () => void, record?: () => void): string {
    const token = undoToken();
    router.delete(MapSelection.destroy().url, {
        data: { map_solarsystem_ids: ids, undo_token: token },
        preserveState: true,
        preserveScroll: true,
        only: ['map', 'map_navigation', 'selected_map_solarsystem'],
        onSuccess: () => {
            record?.();
            onSuccess?.();
        },
        onError: () => router.reload({ only: ['map'] }),
    });
    return token;
}
