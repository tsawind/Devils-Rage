import { useMapStore } from '@/map/store/mapStore';
import { deleteSystemsUndoable } from './deleteSystemsUndoable';
import { getOrphanedMapSolarsystems } from './orphanedMapSolarsystems';

export function cleanMapSolarsystems(): void {
    const store = useMapStore();
    const orphaned = getOrphanedMapSolarsystems(store);

    if (orphaned.length === 0) return;

    deleteSystemsUndoable(
        orphaned.map((system) => system.id),
        `removed ${orphaned.length} unconnected system${orphaned.length === 1 ? '' : 's'}`,
    );
}
