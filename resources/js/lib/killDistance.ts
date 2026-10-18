/**
 * Patch 40: how far a kill is from home, for its sound. Jumps are counted along the map's
 * pipes from the home system. A kill in a side chain (a chain not connected to home, or a
 * coloured rage lane) counts from the start of that chain (or from home for a lane), and
 * gets the side-chain sound.
 */
export type TKillPlace = { jumps: number; side: boolean };

type TSystem = { id: number; solarsystem_id: number; combat_color?: string | null };
type TPipe = { from_map_solarsystem_id: number; to_map_solarsystem_id: number };

function distances(starts: readonly number[], pipes: readonly TPipe[]): Map<number, number> {
    const neighbours = new Map<number, number[]>();
    for (const pipe of pipes) {
        neighbours.set(pipe.from_map_solarsystem_id, [...(neighbours.get(pipe.from_map_solarsystem_id) ?? []), pipe.to_map_solarsystem_id]);
        neighbours.set(pipe.to_map_solarsystem_id, [...(neighbours.get(pipe.to_map_solarsystem_id) ?? []), pipe.from_map_solarsystem_id]);
    }
    const seen = new Map<number, number>(starts.map((id) => [id, 0]));
    let frontier = [...starts];
    while (frontier.length) {
        const next: number[] = [];
        for (const id of frontier) {
            for (const other of neighbours.get(id) ?? []) {
                if (seen.has(other)) continue;
                seen.set(other, (seen.get(id) ?? 0) + 1);
                next.push(other);
            }
        }
        frontier = next;
    }
    return seen;
}

/**
 * Where a kill in `solarsystemId` is, or null when the system isn't on the map.
 * `hubIds`: side chain starts (map system ids) to count from when home can't be reached.
 */
export function killPlace(
    solarsystemId: number,
    systems: readonly TSystem[],
    pipes: readonly TPipe[],
    homeSolarsystemId: number | null,
    hubIds: Iterable<number> = [],
): TKillPlace | null {
    const system = systems.find((candidate) => candidate.solarsystem_id === solarsystemId);
    if (!system) return null;
    const home = homeSolarsystemId === null ? undefined : systems.find((candidate) => candidate.solarsystem_id === homeSolarsystemId);

    if (home) {
        const fromHome = distances([home.id], pipes).get(system.id);
        if (fromHome !== undefined) return { jumps: fromHome, side: Boolean(system.combat_color) };
    }

    const hubs = [...hubIds].filter((id) => id !== home?.id);
    const fromHub = hubs.length ? distances(hubs, pipes).get(system.id) : undefined;
    return { jumps: fromHub ?? 0, side: true };
}
