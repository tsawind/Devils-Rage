import { displayAlias, guessNextAlias, isCustomName, type TAliasScheme } from '@/lib/alias';

/**
 * Patch 39: who already has a name a hole is being renamed to, and the next free number
 * they could take instead ("Give A2 the next number (A5)"). Null when the name is free.
 */
export function renameHolder(params: {
    name: string;
    /** The hole being renamed (excluded). */
    signatureId: number;
    /** The system it leads to, when jumped (excluded). */
    targetId: number | null;
    /** The system the hole is in, and its holes (jumped and not). */
    parent: { id: number; alias?: string | null };
    parentHoles: readonly { id: number; alias?: string | null; signature_id?: string | null }[];
    systems: readonly { id: number; alias?: string | null }[];
    scheme?: TAliasScheme;
    ignoredAlias?: string;
}): { label: string; next: string | null } | null {
    const upper = params.name.trim().toUpperCase();
    if (!upper) return null;
    const names = params.systems.map((system) => system.alias).filter((alias): alias is string => Boolean(alias));
    const taken = [...names, ...params.parentHoles.map((hole) => hole.alias).filter((alias): alias is string => Boolean(alias))];

    const hole = params.parentHoles.find((candidate) => candidate.id !== params.signatureId && (candidate.alias ?? '').toUpperCase() === upper);
    if (hole) {
        return {
            label: `hole ${hole.signature_id?.slice(0, 3) ?? displayAlias(hole.alias, params.scheme)}`,
            next: guessNextAlias(params.parent.alias, taken, { scheme: params.scheme, ignoredAlias: params.ignoredAlias }),
        };
    }

    const system = params.systems.find((candidate) => candidate.id !== params.targetId && (candidate.alias ?? '').toUpperCase() === upper);
    if (!system) return null;
    const name = (system.alias ?? '').trim();
    // The chain the holder hangs in: its name without the last character, or home for a single letter.
    const parentAlias = isCustomName(name) ? null : name.length > 1 ? name.slice(0, -1) : (params.ignoredAlias ?? null);
    return {
        label: displayAlias(name, params.scheme),
        next: parentAlias !== null ? guessNextAlias(parentAlias, taken, { scheme: params.scheme, ignoredAlias: params.ignoredAlias }) : null,
    };
}
