type TChainSystem = {
    id: number;
    combat_color?: string | null;
    combat_home?: boolean | null;
    pinned?: boolean | null;
    solarsystem_id: number;
};

type TChainConnection = { from_map_solarsystem_id: number; to_map_solarsystem_id: number };

/**
 * What clearing a chain does, the same way the server decides it: a system
 * still attached to something outside the chain stays (the home included),
 * pinned systems and the map's home always stay, the rest is removed.
 */
export function planChainClear(
    color: string,
    systems: readonly TChainSystem[],
    connections: readonly TChainConnection[],
    homeSolarsystemId: number | null,
): { removed: number[]; kept: number[]; homeId: number | null; homeStays: boolean } {
    const chain = systems.filter((system) => system.combat_color === color);
    const chainIds = new Set(chain.map((system) => system.id));
    const attached = new Set<number>();
    for (const connection of connections) {
        const fromIn = chainIds.has(connection.from_map_solarsystem_id);
        const toIn = chainIds.has(connection.to_map_solarsystem_id);
        if (fromIn && !toIn) attached.add(connection.from_map_solarsystem_id);
        if (toIn && !fromIn) attached.add(connection.to_map_solarsystem_id);
    }

    const removed: number[] = [];
    const kept: number[] = [];
    for (const system of chain) {
        const stays = attached.has(system.id) || Boolean(system.pinned) || system.solarsystem_id === homeSolarsystemId;
        (stays ? kept : removed).push(system.id);
    }

    const home = chain.find((system) => system.combat_home) ?? null;
    return { removed, kept, homeId: home?.id ?? null, homeStays: home !== null && kept.includes(home.id) };
}
