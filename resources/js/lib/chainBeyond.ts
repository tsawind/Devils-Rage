/**
 * Patch 37: right-click a system → "Delete system and chain beyond". What
 * goes: the system and every system that only reaches the home through it.
 * The home, pinned systems and the rally point always stay (pinned and rally
 * systems beyond keep their own side of the chain).
 */
type TSystem = { id: number; solarsystem_id: number; pinned?: boolean };
type TLink = { from_map_solarsystem_id: number; to_map_solarsystem_id: number };

export type TChainBeyondPlan = {
    /** The systems hidden (the clicked one first). */
    removed: number[];
    /** Systems beyond that stay (pinned / rally) — with what still hangs off them. */
    kept: number[];
};

export function planChainBeyond(
    targetId: number,
    systems: TSystem[],
    links: TLink[],
    homeSolarsystemId: number | null,
    rallySolarsystemId: number | null = null,
): TChainBeyondPlan {
    const byId = new Map(systems.map((system) => [system.id, system]));
    const target = byId.get(targetId);
    if (!target || target.solarsystem_id === homeSolarsystemId) return { removed: [], kept: [] };

    const neighbours = new Map<number, number[]>();
    for (const link of links) {
        if (!byId.has(link.from_map_solarsystem_id) || !byId.has(link.to_map_solarsystem_id)) continue;
        neighbours.set(link.from_map_solarsystem_id, [...(neighbours.get(link.from_map_solarsystem_id) ?? []), link.to_map_solarsystem_id]);
        neighbours.set(link.to_map_solarsystem_id, [...(neighbours.get(link.to_map_solarsystem_id) ?? []), link.from_map_solarsystem_id]);
    }

    const walk = (start: number[], blocked: Set<number>): Set<number> => {
        const seen = new Set<number>(start);
        const queue = [...start];
        while (queue.length) {
            const id = queue.shift()!;
            for (const next of neighbours.get(id) ?? []) {
                if (seen.has(next) || blocked.has(next)) continue;
                seen.add(next);
                queue.push(next);
            }
        }
        return seen;
    };

    const isAnchor = (system: TSystem): boolean =>
        Boolean(system.pinned) || system.solarsystem_id === homeSolarsystemId || (rallySolarsystemId !== null && system.solarsystem_id === rallySolarsystemId);

    // Reaches home (or, with no home on the map, any kept system) without passing the target.
    const anchors = systems.filter((system) => system.id !== targetId && isAnchor(system)).map((system) => system.id);
    const home = systems.find((system) => system.solarsystem_id === homeSolarsystemId);
    const safe = home ? walk([home.id], new Set([targetId])) : new Set<number>();

    const beyond = [...walk([targetId], new Set(safe))].filter((id) => id !== targetId);
    const kept = beyond.filter((id) => anchors.includes(id));
    // What hangs off a kept system (without passing the target) stays with it.
    const keptReach = walk(kept, new Set([targetId, ...safe]));
    const removed = [targetId, ...beyond.filter((id) => !keptReach.has(id))];

    return { removed, kept };
}
