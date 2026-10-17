import { guessNextAlias, type TAliasScheme } from '@/lib/alias';
import { floatingNames, isStampedName } from '@/lib/floatingNames';

/**
 * Patch 39: an old chain reconnected. The mapper never renames on its own: the system keeps
 * its old name (A1~1917) and the new hole's number floats on it ("also Z2111"). Standing in
 * it, the scanner gets rows at the top of the signature list (like the patch 14 cleanup):
 * - this system: A1~1917 → Z2111 [Copy way back] [Done] [Keep old];
 * - once it is renamed, each hole into the old chain beyond: A11~1917 → Z21111 [Copy] [Done].
 * Done renames only that row; the old name floats as "was".
 */
export type TOldChainSystem = {
    id: number;
    alias?: string | null;
    name_kept?: boolean | null;
    old_aliases?: readonly string[] | null;
    hole_slot_high?: string | null;
};

type TOldChainConnection = {
    id: number;
    type?: string | null;
    from_map_solarsystem_id: number;
    to_map_solarsystem_id: number;
    signatures?: readonly { id: number; map_solarsystem_id: number; alias?: string | null }[] | null;
};

export type TOldChainRow =
    /** Rename the system you stand in to the hole's number it was reached by. */
    | { kind: 'self'; from: string; to: string; signatureId: number; viaSystemId: number }
    /** Rename a hole here (and the old system beyond it) to a new number. */
    | { kind: 'hole'; from: string; to: string; signatureId: number; systemId: number };

export function oldChainRows(params: {
    here: TOldChainSystem;
    systems: readonly TOldChainSystem[];
    connections: readonly TOldChainConnection[];
    parentOf: ReadonlyMap<number, number>;
    /** Names already used around here (main names, locked numbers). */
    taken: readonly string[];
    scheme?: TAliasScheme;
    ignoredAlias?: string;
    countUp?: boolean;
}): TOldChainRow[] {
    const { here } = params;
    if (isStampedName(here.alias)) {
        if (here.name_kept) return [];
        const floating = floatingNames(here, params.connections).find((name) => name.kind === 'new' && name.signatureId !== undefined && !isStampedName(name.name));
        return floating && floating.signatureId !== undefined && floating.viaSystemId !== undefined
            ? [{ kind: 'self', from: here.alias ?? '', to: floating.name, signatureId: floating.signatureId, viaSystemId: floating.viaSystemId }]
            : [];
    }

    const byId = new Map(params.systems.map((system) => [system.id, system]));
    const taken = [...params.taken];
    const rows: TOldChainRow[] = [];
    for (const connection of params.connections) {
        if (connection.type === 'stargate') continue;
        const otherId =
            connection.from_map_solarsystem_id === here.id
                ? connection.to_map_solarsystem_id
                : connection.to_map_solarsystem_id === here.id
                  ? connection.from_map_solarsystem_id
                  : null;
        if (otherId === null || params.parentOf.get(here.id) === otherId) continue;
        const other = byId.get(otherId);
        if (!other || !isStampedName(other.alias)) continue;
        const signature = (connection.signatures ?? []).find((candidate) => candidate.map_solarsystem_id === here.id);
        if (!signature || !isStampedName(signature.alias)) continue;
        const to = guessNextAlias(here.alias, taken, { scheme: params.scheme, ignoredAlias: params.ignoredAlias, countUp: params.countUp, slotHigh: here.hole_slot_high });
        if (!to) continue;
        taken.push(to);
        rows.push({ kind: 'hole', from: signature.alias ?? '', to, signatureId: signature.id, systemId: otherId });
    }
    return rows;
}
