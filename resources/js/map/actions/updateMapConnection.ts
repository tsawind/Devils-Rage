import { recordUndo } from '@/composables/undo/mapUndo';
import { signatureToast as toast } from '@/lib/signatureToast';
import { useMapStore } from '@/map/store/mapStore';
import { TMapConnection } from '@/pages/maps';
import MapConnections from '@/routes/map-connections';
import { TConnectionType, TLifetimeStatus, TMassStatus, TShipSize } from '@/types/models';
import { router } from '@inertiajs/vue3';

type TConnectionEdit = {
    type?: TConnectionType | string;
    preserve_mass?: boolean;
    mass_status?: TMassStatus | string;
    ship_size?: TShipSize | string | null;
    lifetime?: TLifetimeStatus | string;
    lifetime_updated_at?: string | null | Date;
};

/** Patch 21: the pipe edits Undo puts back, and where the map keeps each one. */
const UNDOABLE: Record<string, keyof TMapConnection> = {
    type: 'type',
    preserve_mass: 'preserve_mass',
    mass_status: 'mass_status',
    ship_size: 'ship_size',
    lifetime: 'lifetime_status',
};

export function updateMapConnection(map_connection: TMapConnection, data: TConnectionEdit, options: { undoable?: boolean } = {}): void {
    if (options.undoable !== false) recordConnectionEdit(map_connection, data);

    return router.put(MapConnections.update(map_connection.id).url, data, {
        preserveScroll: true,
        preserveState: true,
        only: ['map', 'map_navigation', 'selected_map_solarsystem'],
        onError: () => router.reload({ only: ['map'] }),
    });
}

function recordConnectionEdit(connection: TMapConnection, data: TConnectionEdit): void {
    const keys = Object.keys(UNDOABLE).filter((key) => key in data);
    if (keys.length === 0) return;
    const now = (source: TMapConnection, key: string) => (source[UNDOABLE[key]] ?? null) as unknown;
    const before = Object.fromEntries(keys.map((key) => [key, now(connection, key)]));
    const after = Object.fromEntries(keys.map((key) => [key, (data as Record<string, unknown>)[key] ?? null]));
    if (keys.every((key) => before[key] === after[key])) return;
    const what = keys.includes('mass_status') ? 'pipe mass' : keys.includes('lifetime') ? 'pipe life' : keys.includes('ship_size') ? 'pipe size' : 'pipe';
    const label = `changed ${what}`;
    const store = useMapStore();
    const put = (target: Record<string, unknown>, expected: Record<string, unknown>, direction: 'undo' | 'redo'): boolean => {
        const current = store.connections.get(connection.id);
        if (!current) return false;
        if (keys.some((key) => now(current, key) !== expected[key])) {
            toast.warning(`Not ${direction === 'undo' ? 'undone' : 'redone'}: ${label}`, { description: 'Someone changed that pipe after you.' });
            return false;
        }
        updateMapConnection(current, target as TConnectionEdit, { undoable: false });
        return true;
    };
    recordUndo({ label, undo: () => put(before, after, 'undo'), redo: () => put(after, before, 'redo') });
}
