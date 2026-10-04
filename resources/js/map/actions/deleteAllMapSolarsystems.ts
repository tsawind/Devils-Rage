import { useMapStore } from '@/map/store/mapStore';
import { getClearableMapSolarsystems } from './clearableMapSolarsystems';
import { deleteSystemsUndoable } from './deleteSystemsUndoable';

export function deleteAllMapSolarsystems(): void {
    const store = useMapStore();
    const clearable = getClearableMapSolarsystems(store);

    if (clearable.length === 0) return;

    deleteSystemsUndoable(
        clearable.map((system) => system.id),
        `cleared the map (${clearable.length} system${clearable.length === 1 ? '' : 's'})`,
    );
}
