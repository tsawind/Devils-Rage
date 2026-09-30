/**
 * The per-map alias suggestion convention. Kept in sync with the `AliasScheme`
 * enum on the backend.
 */
export type TAliasScheme = 'numeric' | 'alphabetical';

/**
 * The kind of system an alphabetical suggestion is being generated for. K-space
 * targets get a reserved letter (H/L/N/P) instead of continuing the plain
 * letter sequence.
 */
export type TAliasTargetKind = 'wormhole' | 'h' | 'l' | 'n' | 'p';

type TGuessNextAliasOptions = {
    scheme?: TAliasScheme;
    targetKind?: TAliasTargetKind;
    ignoredAlias?: string;
};

/**
 * Whether `alias` is the map's ignored alias (e.g. "HOME"), case-insensitive and
 * trimmed. An empty `ignoredAlias` disables the feature, so nothing ever matches.
 */
export function isIgnoredAlias(alias: string | null | undefined, ignoredAlias: string | null | undefined): boolean {
    const trimmedIgnored = (ignoredAlias ?? '').trim();
    if (!trimmedIgnored) return false;
    return (alias ?? '').trim().toLowerCase() === trimmedIgnored.toLowerCase();
}

const KSPACE_ALIAS_TARGET_KINDS: readonly string[] = ['h', 'l', 'n', 'p'];

/** The reserved k-space letter for a target's class, or undefined for wormholes/unrecognized classes. */
export function aliasTargetKind(isTargetWormhole: boolean, targetClass: string | null | undefined): TAliasTargetKind | undefined {
    if (isTargetWormhole) return 'wormhole';
    return targetClass && KSPACE_ALIAS_TARGET_KINDS.includes(targetClass) ? (targetClass as TAliasTargetKind) : undefined;
}

/**
 * The alphabetical scheme's 22-letter alphabet: A-Z with H, L, N and P removed,
 * since those are reserved for k-space exits (high/low/null-sec, Pochven).
 */
const WORMHOLE_LETTERS = 'ABCDEFGIJKMOQRSTUVWXYZ';

/**
 * The next letter after `index`, capped at the last letter once the 22-letter
 * alphabet is exhausted. This mirrors numeric's unhandled ">9 children"
 * ambiguity rather than throwing: a map with more than 22 direct wormhole
 * children of one system will see duplicate suggestions past that point.
 */
function letterAtIndex(index: number): string {
    return WORMHOLE_LETTERS[Math.min(index, WORMHOLE_LETTERS.length - 1)];
}

/** The smallest positive integer not present in `used`. */
function lowestFreeIndex(used: Set<number>): number {
    let index = 1;
    while (used.has(index)) {
        index++;
    }
    return index;
}

/**
 * The lowest unused letter extending `prefix`, skipping reserved k-space
 * letters, so a letter freed by a deleted system is reused before the
 * sequence grows. Only aliases that are exactly one letter longer than
 * `prefix` count as direct children, so k-space exits (`AH1`) and deeper
 * descendants (`ABA`) are naturally excluded.
 * Expects `prefix` and `aliases` already upper-cased by `guessNextAlias`.
 */
function nextWormholeLetter(prefix: string, aliases: string[]): string {
    const used = new Set<number>();
    for (const alias of aliases) {
        if (alias.length !== prefix.length + 1 || !alias.startsWith(prefix)) continue;

        const index = WORMHOLE_LETTERS.indexOf(alias.slice(prefix.length));
        if (index !== -1) {
            used.add(index);
        }
    }

    let index = 0;
    while (used.has(index)) {
        index++;
    }
    return letterAtIndex(index);
}

/**
 * The lowest unused per-type index for a k-space exit, e.g. `AH1`, `AH2` for
 * high-sec children of `A`. Each reserved letter keeps its own counter, gaps
 * are filled first, and the anchored digit match excludes anything branching
 * further off a k-space node (`AH1A` is not counted as an `AH` index).
 * Expects `prefix` and `aliases` already upper-cased by `guessNextAlias`.
 */
function nextKspaceIndex(prefix: string, letter: string, aliases: string[]): number {
    const marker = `${prefix}${letter}`;

    const used = new Set<number>();
    for (const alias of aliases) {
        if (!alias.startsWith(marker)) continue;

        const tail = alias.slice(marker.length);
        if (/^\d+$/.test(tail)) {
            used.add(Number.parseInt(tail, 10));
        }
    }

    return lowestFreeIndex(used);
}

/**
 * The alphabetical counterpart to the numeric digit logic: wormhole children
 * extend the prefix with a single non-reserved letter, k-space exits extend it
 * with a reserved letter and a per-type index.
 */
function guessNextAlphabeticalAlias(prefix: string, aliases: string[], targetKind: TAliasTargetKind | undefined): string {
    if (targetKind && targetKind !== 'wormhole') {
        const letter = targetKind.toUpperCase();
        return `${prefix}${letter}${nextKspaceIndex(prefix, letter, aliases)}`;
    }

    return `${prefix}${nextWormholeLetter(prefix, aliases)}`;
}

/**
 * The numeric scheme's slots: 1-9, then A-Z once a system has more than nine
 * children. Every slot is a single character, so "110" can never be confused
 * between "the tenth hole off 1" and "the first hole off 11" — the tenth hole
 * off 1 is "1A".
 */
const NUMERIC_SLOTS = '123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

/**
 * The lowest unused numeric slot extending `prefix`, so a slot freed by a
 * deleted system is reused before the sequence grows. Only aliases exactly one
 * character longer than `prefix` count as direct children, so deeper
 * descendants ("121" under "1") are never mistaken for a direct child.
 * Expects `prefix` and `aliases` already upper-cased by `guessNextAlias`.
 */
function nextNumericSlot(prefix: string, aliases: string[]): string {
    const used = new Set<number>();
    for (const alias of aliases) {
        if (alias.length !== prefix.length + 1 || !alias.startsWith(prefix)) continue;

        const index = NUMERIC_SLOTS.indexOf(alias.slice(prefix.length));
        if (index !== -1) {
            used.add(index);
        }
    }

    let index = 0;
    while (used.has(index)) {
        index++;
    }
    return NUMERIC_SLOTS[Math.min(index, NUMERIC_SLOTS.length - 1)];
}

/**
 * Work out the next concatenated child alias for a system, given its parent's
 * alias and every alias already in use on the map.
 *
 * Numeric (default): top-level systems (no parent alias) are numbered 1, 2,
 * 3…; children of "1" become 11, 12, 13…; children of "12" become 121, 122…
 * After 9 the slots continue with letters (…19, 1A, 1B). The next slot is the
 * lowest unused direct-child slot, so an alias freed by a deleted system is
 * filled before the sequence grows (1, 3, 4 suggests 2).
 *
 * Alphabetical (`opts.scheme`): children use letters instead of digits (see
 * `guessNextAlphabeticalAlias`).
 *
 * Suggestions are always upper-cased, and existing aliases are matched
 * case-insensitively, so a hand-typed lowercase alias ("ab", "ah1") still
 * counts as a taken child and the chain stays visually consistent.
 */
export function guessNextAlias(parentAlias: string | null | undefined, aliases: string[], opts?: TGuessNextAliasOptions): string {
    let prefix = (parentAlias ?? '').trim().toUpperCase();

    if (isIgnoredAlias(prefix, opts?.ignoredAlias)) {
        prefix = '';
    }

    const knownAliases = aliases.map((alias) => alias.trim().toUpperCase());

    if (opts?.scheme === 'alphabetical') {
        return guessNextAlphabeticalAlias(prefix, knownAliases, opts.targetKind);
    }

    return `${prefix}${nextNumericSlot(prefix, knownAliases)}`;
}

/**
 * Suggest an alias for a system reached by a tracked jump, or null when it
 * should not be aliased. The target is aliased when it is itself a wormhole, or
 * when the origin we jumped from is part of the chain — either a wormhole or an
 * already-aliased system. This lets a k-space exit of an aliased wormhole
 * continue the chain (e.g. jumping from "2" into k-space suggests "21").
 */
export function suggestAlias(params: {
    parentAlias: string | null | undefined;
    targetIsWormhole: boolean;
    originIsWormhole: boolean;
    aliases: string[];
    scheme?: TAliasScheme;
    targetKind?: TAliasTargetKind;
    ignoredAlias?: string;
}): string | null {
    const originIsAliased = Boolean(params.parentAlias && params.parentAlias.trim());

    if (!params.targetIsWormhole && !params.originIsWormhole && !originIsAliased) {
        return null;
    }

    return guessNextAlias(params.parentAlias, params.aliases, {
        scheme: params.scheme,
        targetKind: params.targetKind,
        ignoredAlias: params.ignoredAlias,
    });
}

/** The per-signature data `planSignatureAliases` needs. */
export type TAliasPlanSignature = {
    id: number;
    /** Only wormhole signatures get a number. */
    isWormhole: boolean;
    /** Connected holes without a locked number use the alias of the system they lead to. */
    isConnected: boolean;
    /** The number stored on the signature once it was copied or jumped; never changes by itself. */
    lockedAlias?: string | null;
    /** Marked as the system's static: takes the reserved slot 1. */
    isStatic?: boolean;
    /** Whether the hole leads to wormhole space; unknown destinations count as wormhole space. */
    targetIsWormhole?: boolean;
    /** The known destination class, if identified ("unknown"/null otherwise). */
    targetClass?: string | null;
};

/** The chain prefix for a system: its alias, or "" for the ignored (home) alias. */
export function chainPrefix(parentAlias: string | null | undefined, ignoredAlias?: string | null): string {
    const prefix = (parentAlias ?? '').trim().toUpperCase();
    return isIgnoredAlias(prefix, ignoredAlias) ? '' : prefix;
}

/** The alias reserved for a system's static: slot 1, e.g. "1" in home, "11" in 1. */
export function staticSlotAlias(parentAlias: string | null | undefined, ignoredAlias?: string | null): string {
    return `${chainPrefix(parentAlias, ignoredAlias)}${NUMERIC_SLOTS[0]}`;
}

/** The alias for a single slot character (1-9, A-Z) in a system, or null when the slot is invalid. */
export function aliasForSlot(parentAlias: string | null | undefined, slot: string, ignoredAlias?: string | null): string | null {
    const normalized = slot.trim().toUpperCase();
    if (normalized.length !== 1 || !NUMERIC_SLOTS.includes(normalized)) return null;
    return `${chainPrefix(parentAlias, ignoredAlias)}${normalized}`;
}

/**
 * Hand out chain numbers to the wormhole signatures of one system.
 *
 * Numeric scheme (the Devil's Rage convention):
 * - a number stored on a signature (locked on copy or jump) is always kept;
 * - slot 1 is reserved for the hole marked as the static: only it can take it;
 * - every other unjumped hole gets the lowest free slot from 2 up (then A-Z),
 *   in the order the signatures were added;
 * - numbers used by systems on the map or locked on other signatures are taken.
 *
 * Returns signature id → alias. Connected holes without a locked number are
 * left out (their number is the alias of the system they lead to).
 */
export function planSignatureAliases(params: {
    parentAlias: string | null | undefined;
    originIsWormhole: boolean;
    signatures: TAliasPlanSignature[];
    aliases: string[];
    scheme?: TAliasScheme;
    ignoredAlias?: string;
}): Map<number, string> {
    const planned = new Map<number, string>();
    const wormholes = params.signatures.filter((signature) => signature.isWormhole).toSorted((a, b) => a.id - b.id);

    if (params.scheme === 'alphabetical') {
        return planAlphabetical(params, wormholes);
    }

    const prefix = chainPrefix(params.parentAlias, params.ignoredAlias);
    const staticSlot = `${prefix}${NUMERIC_SLOTS[0]}`;
    const taken = new Set(params.aliases.map((alias) => alias.trim().toUpperCase()));

    for (const signature of wormholes) {
        const locked = signature.lockedAlias?.trim().toUpperCase();
        if (locked) {
            planned.set(signature.id, locked);
            taken.add(locked);
        }
    }

    const unnumbered = wormholes.filter((signature) => !planned.has(signature.id) && !signature.isConnected);

    const staticHole = unnumbered.find((signature) => signature.isStatic);
    if (staticHole && !taken.has(staticSlot)) {
        planned.set(staticHole.id, staticSlot);
        taken.add(staticSlot);
    }

    for (const signature of unnumbered) {
        if (planned.has(signature.id)) continue;

        let index = 1;
        while (index < NUMERIC_SLOTS.length - 1 && taken.has(`${prefix}${NUMERIC_SLOTS[index]}`)) {
            index++;
        }

        const alias = `${prefix}${NUMERIC_SLOTS[index]}`;
        planned.set(signature.id, alias);
        taken.add(alias);
    }

    return planned;
}

/** The alphabetical scheme keeps the stock behaviour: locked numbers first, then the next free letter. */
function planAlphabetical(
    params: { parentAlias: string | null | undefined; originIsWormhole: boolean; aliases: string[]; ignoredAlias?: string },
    wormholes: TAliasPlanSignature[],
): Map<number, string> {
    const planned = new Map<number, string>();
    const taken = [...params.aliases];

    for (const signature of wormholes) {
        if (signature.lockedAlias) {
            planned.set(signature.id, signature.lockedAlias);
            taken.push(signature.lockedAlias);
        }
    }

    for (const signature of wormholes) {
        if (planned.has(signature.id) || signature.isConnected) continue;

        const targetClass = signature.targetClass && signature.targetClass !== 'unknown' ? signature.targetClass : null;
        const targetIsWormhole = signature.targetIsWormhole ?? true;
        const alias = suggestAlias({
            parentAlias: params.parentAlias,
            targetIsWormhole,
            originIsWormhole: params.originIsWormhole,
            aliases: taken,
            scheme: 'alphabetical',
            targetKind: aliasTargetKind(targetIsWormhole, targetClass),
            ignoredAlias: params.ignoredAlias,
        });

        if (!alias) continue;
        planned.set(signature.id, alias);
        taken.push(alias);
    }

    return planned;
}
