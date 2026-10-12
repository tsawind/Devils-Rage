import { displayAlias } from '@/lib/alias';
import { estimateMass } from '@/lib/massEstimate';
import type { TRollHole } from '@/lib/rollPlan';
import { wormholeMass } from '@/lib/wormholeMass';
import { guessHole } from '@/map/holeGuess';
import type { MapStore } from '@/map/store/mapStore';
import type { TMapConnection } from '@/pages/maps';
import { ref } from 'vue';

/** Patch 37: the hole "Mass a hole" works on: the pipe you clicked last (or picked in the window). */
export const rollHoleId = ref<number | null>(null);

export type TRollTarget = { id: number; label: string; typeName: string | null; hole: TRollHole; connection: TMapConnection };

/** What the planner needs to know about a pipe: its size, what may be left, its marked status. */
export function rollTarget(store: MapStore, connectionId: number): TRollTarget | null {
    const connection = store.connections.get(connectionId);
    if (!connection || connection.type !== 'wormhole') return null;
    const from = store.systems.get(connection.from_map_solarsystem_id);
    const to = store.systems.get(connection.to_map_solarsystem_id);
    if (!from || !to) return null;
    const name = (system: typeof from) => displayAlias(system.alias) || system.solarsystem.name;
    const typed = (connection.signatures ?? []).find((signature) => signature.wormhole && !signature.wormhole.name.toUpperCase().startsWith('K162'))?.wormhole ?? null;
    const total = typed?.total_mass ?? wormholeMass(typed?.name)?.total ?? guessHole({ endClasses: [from.solarsystem.class, to.solarsystem.class] })?.total ?? null;
    if (!total) return null;
    const estimate = estimateMass({ totalMass: total, jumped: connection.jumps_mass_sum, jumpedSinceStatus: connection.jumps_mass_since_status, status: connection.mass_status });
    if (!estimate) return null;
    const maxJump = typed?.maximum_jump_mass ?? wormholeMass(typed?.name)?.maxJump ?? null;
    const status = connection.mass_status === 'critical' || connection.mass_status === 'reduced' ? connection.mass_status : 'fresh';
    return {
        id: connection.id,
        label: `${name(from)} ↔ ${name(to)}${typed ? ` "${typed.name}"` : ''}`,
        typeName: typed?.name ?? null,
        hole: { total, low: estimate.min, high: estimate.max, maxJump, status },
        connection,
    };
}

/** Every wormhole pipe on the map, for the hole picker. */
export function rollTargets(store: MapStore): TRollTarget[] {
    return [...store.connections.values()]
        .map((connection) => rollTarget(store, connection.id))
        .filter((target): target is TRollTarget => target !== null)
        .sort((a, b) => a.label.localeCompare(b.label));
}
