import { recordUndo, restoreSaved, undoToken } from '@/composables/undo/mapUndo';
import { TMapConnection } from '@/pages/maps';
import MapConnections from '@/routes/map-connections';
import { router } from '@inertiajs/vue3';

/** Patch 21: the server saves the pipe (with its signatures and logged jumps) first, so Undo can put it back. */
export function deleteMapConnection(map_connection: TMapConnection, keepChain = false): void {
    let saved = deleteConnectionSaved(
        map_connection.id,
        () =>
            recordUndo({
                label: keepChain ? 'disconnected a pipe' : 'deleted a pipe',
                undo: () => {
                    restoreSaved(saved);
                    return true;
                },
                redo: () => {
                    saved = deleteConnectionSaved(map_connection.id, undefined, keepChain);
                    return true;
                },
            }),
        keepChain,
    );
}

/** Patch 37: keepChain: what the pipe linked to home stays on the map (Side chains) instead of leaving with it. */
export function deleteConnectionSaved(id: number, record?: () => void, keepChain = false): string {
    const token = undoToken();
    router.delete(MapConnections.destroy(id).url, {
        data: { undo_token: token, keep_chain: keepChain },
        preserveScroll: true,
        preserveState: true,
        only: ['map', 'map_navigation'],
        onSuccess: () => record?.(),
        onError: () => router.reload({ only: ['map'] }),
    });
    return token;
}
