import { displayAlias, splitStamp, type TAliasScheme } from '@/lib/alias';

/**
 * Patch 39: floating names. A system has ONE main name (its alias); the other names it is
 * reached by float in a small violet tag above its card:
 * - "also A2": a loop. The hole from Alpha into it keeps its own number (A2) while the
 *   system is A01. Its forward bookmark reads `·A01 QRS C4 loop=A2`, its way back through
 *   that hole `··*@A2 KQX C4`.
 * - "also Z2111": an old chain coming back. The system still has its stamped old name
 *   (A1~1917) and the new hole's number floats until someone renames it from the rows.
 * - "was A1~1917": the name it had before a rename (stored on the system).
 * Floating names never hold a number: only main names do.
 */
export type TFloatingName = {
    name: string;
    /** loop: another hole into a live system; new: a hole into an old (stamped) chain; was: an earlier name. */
    kind: 'loop' | 'new' | 'was';
    /** The hole on the far side that carries the name (loop / new). */
    signatureId?: number;
    connectionId?: number;
    /** The system that hole is in. */
    viaSystemId?: number;
};

type TNamedSystem = { id: number; alias?: string | null; old_aliases?: readonly string[] | null };
type TNamedConnection = {
    id: number;
    type?: string | null;
    from_map_solarsystem_id: number;
    to_map_solarsystem_id: number;
    signatures?: readonly { id: number; map_solarsystem_id: number; alias?: string | null }[] | null;
};

function sameName(a: string | null | undefined, b: string | null | undefined): boolean {
    return (a ?? '').trim().toUpperCase() === (b ?? '').trim().toUpperCase();
}

/** Whether a name carries a stamp (an old chain's name). */
export function isStampedName(alias: string | null | undefined): boolean {
    return Boolean(splitStamp(alias).stamp);
}

/** The floating names of one system, holes first (in connection order), then earlier names. */
export function floatingNames(system: TNamedSystem, connections: Iterable<TNamedConnection>): TFloatingName[] {
    const main = system.alias ?? null;
    const result: TFloatingName[] = [];
    const seen = new Set<string>();
    for (const connection of connections) {
        if (connection.type === 'stargate') continue;
        if (connection.from_map_solarsystem_id !== system.id && connection.to_map_solarsystem_id !== system.id) continue;
        const viaSystemId = connection.from_map_solarsystem_id === system.id ? connection.to_map_solarsystem_id : connection.from_map_solarsystem_id;
        for (const signature of connection.signatures ?? []) {
            if (signature.map_solarsystem_id !== viaSystemId) continue;
            const name = (signature.alias ?? '').trim();
            if (!name || sameName(name, main) || seen.has(name.toUpperCase())) continue;
            seen.add(name.toUpperCase());
            result.push({ name, kind: isStampedName(main) ? 'new' : 'loop', signatureId: signature.id, connectionId: connection.id, viaSystemId });
        }
    }
    for (const name of system.old_aliases ?? []) {
        if (!name || sameName(name, main) || seen.has(name.toUpperCase())) continue;
        seen.add(name.toUpperCase());
        result.push({ name, kind: 'was' });
    }
    return result;
}

/** The tag text: "also A2", "was A1~1917". */
export function floatingLabel(floating: TFloatingName, scheme?: TAliasScheme): string {
    return `${floating.kind === 'was' ? 'was' : 'also'} ${displayAlias(floating.name, scheme)}`;
}

/** The tag's tooltip. */
export function floatingTooltip(floating: TFloatingName, main: string | null | undefined, via: string | null | undefined, scheme?: TAliasScheme): string {
    const name = displayAlias(floating.name, scheme);
    const here = displayAlias(main, scheme) || 'this system';
    if (floating.kind === 'was') return `${here} was called ${name} before it was renamed. Old bookmarks in game may still say ${name}. ✕ forgets it.`;
    const from = via ? ` from ${displayAlias(via, scheme)}` : '';
    if (floating.kind === 'new') return `The hole${from} numbers this system ${name}; it still has its old chain's name ${here}. Rename it from the rows in the signature list, or keep the old name. ✕ drops ${name}.`;
    return `A loop: the hole${from} is ${name}, but this system is ${here}. Bookmarks through that hole say "loop=${name}", its way back "*@${name}". ✕ drops ${name} (that hole then uses ${here}).`;
}

/**
 * The name a system goes by as seen through one hole (`{here}` of a way back): the hole's own
 * number when it floats on the system (a loop), otherwise the main name.
 */
export function nameSeenThrough(system: TNamedSystem, connection: TNamedConnection | null | undefined): string | null {
    if (!connection) return system.alias ?? null;
    const floating = floatingNames(system, [connection]).find((name) => name.connectionId === connection.id && name.kind !== 'was');
    return floating?.name ?? system.alias ?? null;
}

/**
 * The suffix of a forward bookmark into a system that has another name: "loop=A2" (a loop),
 * "old=A1~1917" (an old chain's name). The old name wins when there are both.
 */
export function forwardSuffix(params: { mainName: string | null | undefined; holeName: string | null | undefined; oldAliases?: readonly string[] | null; scheme?: TAliasScheme }): {
    name: string | null;
    suffix: string | null;
} {
    const main = (params.mainName ?? '').trim();
    const hole = (params.holeName ?? '').trim();
    if (isStampedName(main)) {
        // An old chain: the bookmark says the hole's new number, and the old name after it.
        return { name: hole || main, suffix: hole && !sameName(hole, main) ? `old=${displayAlias(main, params.scheme)}` : null };
    }
    const old = (params.oldAliases ?? []).find((name) => isStampedName(name));
    if (old) return { name: main || hole, suffix: `old=${displayAlias(old, params.scheme)}` };
    if (hole && main && !sameName(hole, main)) return { name: main, suffix: `loop=${displayAlias(hole, params.scheme)}` };
    return { name: main || hole || null, suffix: null };
}
