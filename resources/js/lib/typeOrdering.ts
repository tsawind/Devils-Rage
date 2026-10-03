import { matchesQuickKey } from '@/lib/arming';
import { isK162Frigate, k162Classes, k162Order } from '@/lib/k162';
import { isFrigateHole } from '@/lib/massEstimate';
import { wormholeMass } from '@/lib/wormholeMass';

/**
 * Patch 20: the order of the Type lists.
 *
 * Default: the system's statics, the K162 options for where you stand, the
 * hole types often seen on this map from this class, every other hole (hs,
 * ls, ns, C1 … C6), and the rare ones last (frigate holes, Thera, Turnur,
 * drifters). A quick key (1–6, h, l, n, f) doesn't hide anything: it lifts the
 * matching types to the top — "5" puts K162 C5 first, then K162 ranges with a
 * C5 in them, the static that goes to a C5, then the other holes to a C5.
 */

type TOrderType = { id: number; signature: string; target_class?: string | null; extra?: string | null };

const QUICK = new Set(['1', '2', '3', '4', '5', '6', 'h', 'l', 'n', 'f']);

export function isQuickKey(query: string): boolean {
    return QUICK.has(query.trim().toLowerCase());
}

/** Rare holes: frigate-only, or special ones (Thera, Turnur, drifters, Pochven…). */
export function isRareType(type: TOrderType): boolean {
    if (type.signature.toUpperCase() === 'K162') return false;
    if (type.extra) return true;
    const mass = wormholeMass(type.signature);
    return Boolean(mass && isFrigateHole(mass.maxJump));
}

/** Where a type ranks for a quick key (lower = higher in the list), or null when it doesn't match. */
export function quickKeyRank(key: string, type: TOrderType, staticNames: readonly string[]): number | null {
    const normalized = key.trim().toLowerCase();
    const k162 = type.signature.toUpperCase() === 'K162';
    if (normalized === 'f') {
        if (k162) return isK162Frigate(type) ? 0 : null;
        return matchesQuickKey('f', type) ? 3 : null;
    }
    if (k162) {
        const classes = k162Classes(type);
        if (classes.length === 1 && classes[0] === normalized) return 0;
        if (classes.length > 1 && classes.includes(normalized)) return 1;
        return null;
    }
    if (!matchesQuickKey(normalized, type)) return null;
    return staticNames.includes(type.signature.toUpperCase()) ? 2 : 3;
}

/**
 * The hole types seen most on the map from systems of the class you stand in
 * (statics and K162s left out): name → times seen. Learns by itself.
 */
export function oftenSeenCounts(
    systems: Iterable<{ id: number; solarsystem?: { class?: string | null } | null; pending_holes?: { wormhole?: string | null }[] | null }>,
    connections: Iterable<{ signatures?: { map_solarsystem_id: number; wormhole?: { name?: string | null } | null }[] | null }>,
    standingClass: string | null | undefined,
): Map<string, number> {
    const counts = new Map<string, number>();
    if (!standingClass) return counts;
    const classOf = new Map<number, string | null | undefined>();
    const add = (name: string | null | undefined) => {
        const value = (name ?? '').trim().toUpperCase();
        if (!value || value === 'K162') return;
        counts.set(value, (counts.get(value) ?? 0) + 1);
    };
    for (const system of systems) {
        classOf.set(system.id, system.solarsystem?.class);
        if (system.solarsystem?.class !== standingClass) continue;
        for (const hole of system.pending_holes ?? []) add(hole.wormhole);
    }
    for (const connection of connections) {
        for (const signature of connection.signatures ?? []) {
            if (classOf.get(signature.map_solarsystem_id) === standingClass) add(signature.wormhole?.name);
        }
    }
    return counts;
}

/** The "often seen" group: types seen at least twice, most first, at most `limit`. */
export function oftenSeen<T extends TOrderType>(types: readonly T[], counts: ReadonlyMap<string, number>, staticNames: readonly string[], limit = 4): T[] {
    return types
        .filter((type) => type.signature.toUpperCase() !== 'K162' && !staticNames.includes(type.signature.toUpperCase()) && (counts.get(type.signature.toUpperCase()) ?? 0) >= 2)
        .toSorted((a, b) => (counts.get(b.signature.toUpperCase()) ?? 0) - (counts.get(a.signature.toUpperCase()) ?? 0))
        .slice(0, limit);
}

export { k162Order };

/**
 * Patch 20: the Type list as labelled groups, the same order everywhere
 * (signature list, a dotted system's Type menu). `here` = types that spawn
 * where you stand; `elsewhere` = every other type. A quick key lifts its
 * matches into a first group; any other text filters by name.
 */
export function typeSections<T extends TOrderType & { name?: string | null; spawn_areas?: readonly string[] | null }>(params: {
    here: readonly T[];
    elsewhere: readonly T[];
    staticNames: readonly string[];
    standingClass: string | null | undefined;
    query: string;
    counts: ReadonlyMap<string, number>;
    offers: (type: T) => boolean;
    matches: (query: string, type: T) => boolean;
}): { key: string; label: string; items: T[] }[] {
    const upperStatics = params.staticNames.map((name) => name.toUpperCase());
    const isStatic = (type: T) => upperStatics.includes(type.signature.toUpperCase());
    const k162 = params.here.filter((type) => type.signature.toUpperCase() === 'K162' && params.offers(type)).toSorted((a, b) => k162Order(a) - k162Order(b));
    const statics = params.here.filter((type) => type.signature.toUpperCase() !== 'K162' && isStatic(type));
    const plain = params.here.filter((type) => type.signature.toUpperCase() !== 'K162' && !isStatic(type));
    const often = oftenSeen(plain, params.counts, upperStatics);
    const common = plain.filter((type) => !isRareType(type) && !often.includes(type));
    const otherCommon = params.elsewhere.filter((type) => type.signature.toUpperCase() !== 'K162' && !isRareType(type));
    const rare = [...plain, ...params.elsewhere.filter((type) => type.signature.toUpperCase() !== 'K162')].filter((type) => isRareType(type));
    const groups = [
        { key: 'statics', label: 'Statics', items: statics },
        { key: 'k162', label: 'K162', items: k162 },
        { key: 'often', label: 'Often seen on this map', items: often },
        { key: 'wormholes', label: 'Wormholes', items: common },
        { key: 'other', label: 'Other wormholes (not listed for this class)', items: otherCommon },
        { key: 'rare', label: 'Rare: frigate, Thera, Turnur, drifter', items: rare },
    ];
    const needle = params.query.trim().toLowerCase();
    if (isQuickKey(needle)) {
        const all = groups.flatMap((group) => group.items);
        const ranked = all
            .map((type, index) => ({ type, index, rank: quickKeyRank(needle, type, upperStatics) }))
            .filter((entry) => entry.rank !== null)
            .toSorted((a, b) => (a.rank as number) - (b.rank as number) || a.index - b.index)
            .map((entry) => entry.type);
        const lifted = new Set(ranked);
        return [{ key: 'match', label: `Matches ${needle.toUpperCase()}`, items: ranked }, ...groups.map((group) => ({ ...group, items: group.items.filter((type) => !lifted.has(type)) }))].filter(
            (group) => group.items.length,
        );
    }
    return groups.map((group) => ({ ...group, items: group.items.filter((type) => params.matches(needle, type)) })).filter((group) => group.items.length);
}
