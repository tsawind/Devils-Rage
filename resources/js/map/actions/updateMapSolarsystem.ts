import { recordUndo } from '@/composables/undo/mapUndo';
import { signatureToast as toast } from '@/lib/signatureToast';
import { useMapStore } from '@/map/store/mapStore';
import type { TMapSolarsystem, TShowMapProps } from '@/pages/maps';
import MapSolarsystems from '@/routes/map-solarsystems';
import type { AppPageProps } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { systemLabel } from './deleteMapSolarsystem';

type TSystemEdit = {
    position_x?: number;
    position_y?: number;
    alias?: string;
    occupier_alias?: string;
    status?: string;
    pinned?: boolean;
};

/** Patch 21: the edits Undo puts back (moves are their own step, see nodeDrag). */
const UNDOABLE = ['alias', 'occupier_alias', 'status', 'pinned'] as const;

export function updateMapSolarsystem(map_solarsystem: TMapSolarsystem, data: TSystemEdit, options: { undoable?: boolean } = {}): void {
    const page = usePage<AppPageProps<TShowMapProps>>();
    const isDetailPanelTarget = page.props.selected_map_solarsystem?.id === map_solarsystem.id;

    if (options.undoable !== false) recordSystemEdit(map_solarsystem, data);

    return router.put(MapSolarsystems.update(map_solarsystem.id).url, data, {
        preserveState: true,
        preserveScroll: true,
        only: isDetailPanelTarget ? ['map', 'selected_map_solarsystem'] : ['map'],
        onError: () => router.reload({ only: ['map'] }),
    });
}

function recordSystemEdit(system: TMapSolarsystem, data: TSystemEdit): void {
    const keys = UNDOABLE.filter((key) => key in data);
    if (keys.length === 0) return;
    const read = (source: unknown, key: string) => ((source as Record<string, unknown> | null)?.[key] ?? null) as string | boolean | null;
    const before = Object.fromEntries(keys.map((key) => [key, read(system, key)]));
    const after = Object.fromEntries(keys.map((key) => [key, read(data, key)]));
    if (keys.every((key) => before[key] === after[key])) return;
    const what = keys.includes('alias') ? 'renamed' : keys.includes('pinned') ? (after.pinned ? 'pinned' : 'unpinned') : 'changed';
    const label = `${what} ${systemLabel(system)}`;
    const store = useMapStore();
    const put = (target: Record<string, unknown>, expected: Record<string, unknown>, direction: 'undo' | 'redo'): boolean => {
        const now = store.systems.get(system.id);
        if (!now) return false;
        if (keys.some((key) => read(now, key) !== expected[key])) {
            toast.warning(`Not ${direction === 'undo' ? 'undone' : 'redone'}: ${label}`, { description: 'Someone changed that system after you.' });
            return false;
        }
        updateMapSolarsystem(now, target as TSystemEdit, { undoable: false });
        return true;
    };
    recordUndo({ label, undo: () => put(before, after, 'undo'), redo: () => put(after, before, 'redo') });
}
