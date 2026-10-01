import { aliasForSlot, suggestAlias, type TAliasScheme } from '@/lib/alias';

/**
 * Patch 14: cleaning a combat chain up into another chain, one system at a
 * time. Cleanup starts on the combat home (it loses its color, so the chain
 * has no home any more). Then, standing in a system that isn't in the chain
 * (or was converted already), each chain system hanging off it is a row:
 * "1 → D12". Pressing Done converts only that system; the systems further
 * out keep their combat names until someone stands in their parent.
 *
 * The new name is this system's name + the digit the scanner typed in game
 * (the last digit of the combat name), or the next free number if taken.
 */

export type TCleanupSystem = {
    id: number;
    alias: string | null;
    combat_color?: string | null;
    combat_home?: boolean | null;
};

export type TCleanupRow = {
    systemId: number;
    /** The digit typed in game ("1"), from the combat name. */
    digit: string;
    /** The combat name now ("11"). */
    from: string;
    /** The name it gets ("D121"). */
    to: string;
};

export function cleanupRows(params: {
    here: TCleanupSystem;
    systems: readonly TCleanupSystem[];
    connections: readonly { from_map_solarsystem_id: number; to_map_solarsystem_id: number; type?: string | null }[];
    parentOf: ReadonlyMap<number, number>;
    /** Names already used in this system's chain. */
    taken: readonly string[];
    scheme?: TAliasScheme;
    ignoredAlias?: string;
}): TCleanupRow[] {
    const { here } = params;
    if (here.combat_color) return [];
    const colorsWithHome = new Set(params.systems.filter((system) => system.combat_home && system.combat_color).map((system) => system.combat_color as string));
    const byId = new Map(params.systems.map((system) => [system.id, system]));

    const children: TCleanupSystem[] = [];
    for (const connection of params.connections) {
        if (connection.type === 'stargate') continue;
        const otherId =
            connection.from_map_solarsystem_id === here.id
                ? connection.to_map_solarsystem_id
                : connection.to_map_solarsystem_id === here.id
                  ? connection.from_map_solarsystem_id
                  : null;
        if (otherId === null) continue;
        const other = byId.get(otherId);
        if (!other?.combat_color || other.combat_home || colorsWithHome.has(other.combat_color)) continue;
        // Every chain system hanging straight off here is a row (not the one here hangs off).
        if (params.parentOf.get(here.id) === otherId) continue;
        if (!children.some((child) => child.id === otherId)) children.push(other);
    }

    const digitOf = (alias: string | null): string => (alias ?? '').trim().slice(-1).toUpperCase() || '?';
    const taken = [...params.taken];
    return children
        .toSorted((a, b) => digitOf(a.alias).localeCompare(digitOf(b.alias), undefined, { numeric: true }) || a.id - b.id)
        .map((child) => {
            const digit = digitOf(child.alias);
            const direct = digit !== '?' ? aliasForSlot(here.alias, digit, params.ignoredAlias, false) : null;
            const free = (alias: string | null): boolean => Boolean(alias) && !taken.some((name) => name.toUpperCase() === alias!.toUpperCase());
            const to =
                (free(direct) ? direct : null) ??
                suggestAlias({
                    parentAlias: here.alias,
                    targetIsWormhole: true,
                    originIsWormhole: true,
                    aliases: taken,
                    scheme: params.scheme,
                    ignoredAlias: params.ignoredAlias,
                }) ??
                '';
            if (to) taken.push(to);
            return { systemId: child.id, digit, from: child.alias ?? '', to };
        })
        .filter((row) => row.to !== '');
}
