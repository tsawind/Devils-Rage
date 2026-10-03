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

export type TCertaintyMark = { signatureId: number; staticName: string; setType: boolean };

export type TCertaintyResult = {
    /** Mark this signature as the static (setType: also save the static's type on it). The first of `marks`. */
    mark: TCertaintyMark | null;
    /** Patch 20: every static that is certain now (a system can have two or three). */
    marks: TCertaintyMark[];
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
    const result: TCertaintyResult = { mark: null, marks: [], ambiguous: null, wayBackCouldBe: maybeBack };

    // Patch 20: a system can have two or three statics. Each one marked already is accounted for
    // (by its type; a marked hole with no type accounts for any one), the rest are still checked.
    const markedTypes = input.holes.filter((hole) => hole.isStatic).map((hole) => (hole.typeName ? hole.typeName.toUpperCase() : null));
    const open: TCertaintyStatic[] = [];
    {
        const typed = markedTypes.filter((name): name is string => name !== null);
        let untyped = markedTypes.length - typed.length;
        const seenNames = new Set<string>();
        for (const candidate of input.statics) {
            const name = candidate.name.toUpperCase();
            if (seenNames.has(name)) continue;
            seenNames.add(name);
            const index = typed.indexOf(name);
            if (index !== -1) {
                typed.splice(index, 1);
                continue;
            }
            open.push(candidate);
        }
        // Marked holes with no type cover the open statics one each (in order).
        while (untyped > 0 && open.length) {
            open.shift();
            untyped--;
        }
    }
    if (open.length === 0) return result;

    // Patch 18b: every signature must be categorised; untyped wormholes now count as candidates
    // (a static always exists, so the only untyped hole left is it), instead of blocking the check.
    if (input.uncategorized !== 0) return result;
    const allTyped = input.holes.every((hole) => hole.linked || Boolean(hole.typeName));

    const wayBackId = input.wayBack?.signatureId ?? null;
    // Holes already marked (or just marked for another static) can't be this one.
    const used = new Set<number>(input.holes.filter((hole) => hole.isStatic).map((hole) => hole.signatureId));
    const free = (signatureId: number) => !used.has(signatureId);

    let progress = true;
    const remaining = [...open];
    // Settle one static at a time and look again: marking one can leave the next with a single candidate.
    while (progress && remaining.length) {
        progress = false;
        for (const staticHole of [...remaining]) {
            const name = staticHole.name.toUpperCase();
            const candidates = input.holes
                .filter((hole) => free(hole.signatureId))
                .filter((hole) =>
                    hole.typeName
                        ? hole.typeName.toUpperCase() === name && !isK162(hole.typeName)
                        : // A jumped hole with no type could be it when it leads to the static's class.
                          hole.linked && Boolean(hole.leadsTo) && hole.leadsTo === staticHole.leadsTo,
                )
                .map((hole) => hole.signatureId);
            // Patch 18b: an unjumped hole with no type could be it, unless it's known to lead elsewhere.
            for (const hole of input.holes) {
                if (!free(hole.signatureId)) continue;
                if (!hole.linked && !hole.typeName && (!hole.leadsTo || hole.leadsTo === staticHole.leadsTo)) candidates.push(hole.signatureId);
            }
            const backIsCandidate = maybeBack.some((candidate) => candidate.toUpperCase() === name);
            // The way back could be it: a candidate too, even before its signature is pasted (-1 then).
            if (backIsCandidate && (wayBackId === null || free(wayBackId)) && !candidates.includes(wayBackId ?? -1)) candidates.push(wayBackId ?? -1);
            // So could a hole jumped from here whose signature was never pasted.
            if ((input.unpastedLeadsTo ?? []).includes(staticHole.leadsTo)) candidates.push(-1);

            if (candidates.length === 1 && candidates[0] !== -1) {
                const signatureId = candidates[0];
                const hole = input.holes.find((candidate) => candidate.signatureId === signatureId);
                result.marks.push({ signatureId, staticName: staticHole.name, setType: !hole?.typeName });
                used.add(signatureId);
                remaining.splice(remaining.indexOf(staticHole), 1);
                progress = true;
                continue;
            }
            // Only said when every hole has a type (untyped holes are usually just not typed yet).
            if (candidates.length > 1 && allTyped && !result.ambiguous) {
                result.ambiguous = { staticName: staticHole.name, signatureIds: candidates.filter((id) => id !== -1) };
            }
        }
    }
    result.mark = result.marks[0] ?? null;
    if (result.ambiguous && result.marks.some((mark) => mark.staticName === result.ambiguous?.staticName)) result.ambiguous = null;
    return result;
}
