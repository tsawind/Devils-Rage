import { recordUndo, restoreSaved } from '@/composables/undo/mapUndo';
import type { MapStore } from '@/map/store/mapStore';
import { deleteConnectionSaved } from './deleteMapConnection';
import { deleteAgain } from './deleteMapSolarsystem';

/**
 * Patch 21: adding a system or a pipe by hand is an Undo step: Undo deletes it
 * (saved on the server, so Redo puts it back as it was).
 */
export function recordAddedSystem(store: MapStore, solarsystemId: number, label: string): void {
    // Looked up when undoing: the map may not have the new system yet when the save returns.
    let saved: string | null = null;
    recordUndo({
        label,
        undo: () => {
            const added = [...store.systems.values()].find((system) => system.solarsystem_id === solarsystemId);
            if (!added) return false;
            saved = deleteAgain(added.id);
            return true;
        },
        redo: () => {
            if (!saved) return false;
            restoreSaved(saved);
            return true;
        },
    });
}

export function recordAddedConnection(store: MapStore, fromId: number, toId: number): void {
    let saved: string | null = null;
    recordUndo({
        label: 'added a pipe',
        undo: () => {
            const added = [...store.connections.values()].find(
                (connection) =>
                    (connection.from_map_solarsystem_id === fromId && connection.to_map_solarsystem_id === toId) ||
                    (connection.from_map_solarsystem_id === toId && connection.to_map_solarsystem_id === fromId),
            );
            if (!added) return false;
            saved = deleteConnectionSaved(added.id);
            return true;
        },
        redo: () => {
            if (!saved) return false;
            restoreSaved(saved);
            return true;
        },
    });
}
