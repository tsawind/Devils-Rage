import { useMapStore } from '@/map/store/mapStore';
import { deleteSystemsUndoable } from './deleteSystemsUndoable';
import { getSelectedMapSolarsystems } from './selectedMapSolarsystems';

export function deleteSelectedMapSolarsystems(): void {
    const store = useMapStore();
    const selected = getSelectedMapSolarsystems(store);

    if (selected.length === 0) return;

    deleteSystemsUndoable(
        selected.map((system) => system.id),
        `deleted ${selected.length} system${selected.length === 1 ? '' : 's'}`,
    );
}
