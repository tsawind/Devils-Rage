/**
 * When is a hole certainly the system's static? (patch 13)
 *
 * A hole of the static's type is only the static once every signature in the
 * system is scanned (categorised; every wormhole has a type or is linked) and
 * nothing else can be that static, the way back included. Until then nothing
 * is marked: an unscanned signature could still be the static, and the typed
 * one a wandering hole of the same type.
 *
 * The way back (the hole you came in by) can be the static when it opened on
 * this side: the far side is a K162 or unknown (not a known non-K162 type),
 * this side isn't a K162, and it leads to the static's class.
 */

export type TCertaintyHole = {
    signatureId: number;
    /** Wormhole type on this side ("V911", "K162"), or null while unknown. */
    typeName: string | null;
    isStatic: boolean;
    /** Linked to a connection (jumped). */
    linked: boolean;
    /** Linked holes: the class code of the system it leads to ("c5"). */
    leadsTo?: string | null;
};

export type TCertaintyStatic = {
    name: string;
    /** Where it leads, as the wormhole data writes it: "c5", "hs", "ls", "ns"… */
    leadsTo: string;
};

export type TCertaintyWayBack = {
    /** This side's signature of the connection back, if it has been pasted. */
    signatureId: number | null;
    /** This side's type, if known. */
    thisSideType: string | null;
    /** The far side's type, if known. */
    farSideType: string | null;
    /** The class code of the system the way back leads to ("c5", "hs"…). */
    leadsTo: string | null;
};

export type TCertaintyInput = {
    statics: TCertaintyStatic[];
    holes: TCertaintyHole[];
    /** Signatures not categorised yet (any kind). */
    uncategorized: number;
    wayBack: TCertaintyWayBack | null;
    /** Connections from this system (not the way back) whose hole here was never pasted: where they lead. */
    unpastedLeadsTo?: string[];
};

export type TCertaintyResult = {
    /** Mark this signature as the static (setType: also save the static's type on it). */
    mark: { signatureId: number; staticName: string; setType: boolean } | null;
    /** Everything is scanned but more than one hole could be the static. */
    ambiguous: { staticName: string; signatureIds: number[] } | null;
    /** Statics the way back could be ("maybe *return?"). */
    wayBackCouldBe: string[];
};

const isK162 = (name: string | null | undefined): boolean => (name ?? '').trim().toUpperCase().startsWith('K162');

/** The class code a wormhole's "leads to" uses for a system class: "5" → "c5", "h" → "hs". */
export function classCode(solarsystemClass: string | null | undefined): string | null {
    if (!solarsystemClass) return null;
    if (/^\d+$/.test(solarsystemClass)) return `c${solarsystemClass}`;
    const kspace: Record<string, string> = { h: 'hs', l: 'ls', n: 'ns' };
    return kspace[solarsystemClass] ?? null;
}

/** The statics the way back could be: it opened on this side and leads to the static's class. */
export function wayBackCouldBe(statics: readonly TCertaintyStatic[], wayBack: TCertaintyWayBack | null): string[] {
    if (!wayBack || !wayBack.leadsTo) return [];
    // A known non-K162 type on the far side: the hole opened there, so this side is its K162.
    if (wayBack.farSideType && !isK162(wayBack.farSideType)) return [];
    if (wayBack.thisSideType && isK162(wayBack.thisSideType)) return [];
    return statics
        .filter((candidate) => candidate.leadsTo === wayBack.leadsTo)
        .filter((candidate) => !wayBack.thisSideType || wayBack.thisSideType.toUpperCase() === candidate.name.toUpperCase())
        .map((candidate) => candidate.name);
}

export function decideStatic(input: TCertaintyInput): TCertaintyResult {
    const maybeBack = wayBackCouldBe(input.statics, input.wayBack);
    const result: TCertaintyResult = { mark: null, ambiguous: null, wayBackCouldBe: maybeBack };

    // One static per system in our numbering: nothing to do once a hole is marked.
    if (input.holes.some((hole) => hole.isStatic)) return result;

    const allScanned = input.uncategorized === 0 && input.holes.every((hole) => hole.linked || Boolean(hole.typeName));
    if (!allScanned) return result;

    const wayBackId = input.wayBack?.signatureId ?? null;
    const seen = new Set<string>();
    for (const staticHole of input.statics) {
        const name = staticHole.name.toUpperCase();
        if (seen.has(name)) continue;
        seen.add(name);

        const candidates = input.holes
            .filter((hole) =>
                hole.typeName
                    ? hole.typeName.toUpperCase() === name && !isK162(hole.typeName)
                    : // A jumped hole with no type could be it when it leads to the static's class.
                      hole.linked && Boolean(hole.leadsTo) && hole.leadsTo === staticHole.leadsTo,
            )
            .map((hole) => hole.signatureId);
        const backIsCandidate = maybeBack.some((candidate) => candidate.toUpperCase() === name);
        // The way back could be it: a candidate too, even before its signature is pasted (-1 then).
        if (backIsCandidate && !candidates.includes(wayBackId ?? -1)) candidates.push(wayBackId ?? -1);
        // So could a hole jumped from here whose signature was never pasted.
        if ((input.unpastedLeadsTo ?? []).includes(staticHole.leadsTo)) candidates.push(-1);

        if (candidates.length === 1 && candidates[0] !== -1) {
            const signatureId = candidates[0];
            const hole = input.holes.find((candidate) => candidate.signatureId === signatureId);
            result.mark = { signatureId, staticName: staticHole.name, setType: !hole?.typeName };
            return result;
        }
        if (candidates.length > 1 && !result.ambiguous) {
            result.ambiguous = { staticName: staticHole.name, signatureIds: candidates.filter((id) => id !== -1) };
        }
    }
    return result;
}
