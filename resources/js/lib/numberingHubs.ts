import { shallowRef } from 'vue';

/**
 * Patch 39: hubs refill freed hole numbers at once (home, pinned systems, the start of a
 * side chain, rage homes); every other system only counts up. The map store fills this in
 * from the chain it has, so the signature list, the dotted boxes and the jump prompt all
 * hand out the same numbers.
 */
export const numberingHubIds = shallowRef<ReadonlySet<number>>(new Set());

/** Whether a system's numbers only count up (not a hub). */
export function countsUp(system: { id?: number | null } | null | undefined): boolean {
    return system?.id != null && !numberingHubIds.value.has(system.id);
}

/** Hubs from the way each system was reached: home, pinned, rage homes, and systems reached from nothing (side chain starts). */
export function findNumberingHubs(
    systems: readonly { id: number; pinned?: boolean | null; combat_home?: boolean | null }[],
    parentOf: ReadonlyMap<number, number>,
    homeId: number | null,
): Set<number> {
    return new Set(systems.filter((system) => system.id === homeId || system.pinned || system.combat_home || !parentOf.has(system.id)).map((system) => system.id));
}
