import { displayAlias } from '@/lib/alias';
import { signatureToast as toast } from '@/lib/signatureToast';
import { updateMapConnection } from '@/map/actions/updateMapConnection';
import type { MapStore } from '@/map/store/mapStore';
import type { TMapConnection } from '@/pages/maps';
import { watch } from 'vue';

/**
 * Patch 33: tell everyone on the map when the jump log marks a hole reduced or critical
 * (with Undo, which sets it back by hand), and when it says a hole must have rolled.
 */
export function useMassLogToasts(store: MapStore): void {
    const seen = new Map<number, { status: string; rolled: boolean }>();
    let primed = false;

    const nameOf = (connection: TMapConnection): string => {
        const end = (id: number) => {
            const system = store.systems.get(id);
            return displayAlias(system?.alias) || system?.solarsystem?.name || '?';
        };
        return `${end(connection.from_map_solarsystem_id)}↔${end(connection.to_map_solarsystem_id)}`;
    };

    watch(
        () => [...store.connections.values()].map((connection) => `${connection.id}:${connection.mass_status}:${connection.should_have_rolled ? 1 : 0}`).join('|'),
        () => {
            for (const connection of store.connections.values()) {
                const status = String(connection.mass_status ?? 'fresh');
                const rolled = Boolean(connection.should_have_rolled);
                const before = seen.get(connection.id);
                seen.set(connection.id, { status, rolled });
                if (!primed || !before) continue;

                if (before.status !== status && connection.mass_status_from_log && (status === 'reduced' || status === 'critical')) {
                    const previous = before.status;
                    toast.warning(`${nameOf(connection)} ${status === 'critical' ? 'is critical' : 'is reduced'} by the jump log`, {
                        description: 'The mass logged through it proves it (base ship mass, so it is at least this far gone).',
                        action: { label: 'Undo', onClick: () => updateMapConnection(connection, { mass_status: previous }, { undoable: false }) },
                        duration: 15_000,
                    });
                }
                if (!before.rolled && rolled) {
                    toast.error(`${nameOf(connection)} should have rolled`, {
                        description: 'More mass is logged through it than it can ever hold. Check it in game; routes skip it until then.',
                        duration: 20_000,
                    });
                }
            }
            primed = true;
        },
        { immediate: true },
    );
}
