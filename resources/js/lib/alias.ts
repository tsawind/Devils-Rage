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
    /** Only unconnected wormhole signatures get a planned alias. */
    isWormhole: boolean;
    isConnected: boolean;
    /** The identified wormhole type, e.g. "V753", used to put statics first. */
    wormholeName?: string | null;
    /** Whether the hole leads to wormhole space; unknown destinations count as wormhole space. */
    targetIsWormhole?: boolean;
    /** The known destination class, if identified ("unknown"/null otherwise). */
    targetClass?: string | null;
};

/**
 * Hand out the next chain aliases to every unjumped wormhole signature in one
 * system, so two unscanned holes never both suggest "1". Aliases already on
 * the map stay taken; the system's static holes get the lowest free slots,
 * then the rest in the order they were added (signature id).
 *
 * Returns signature id → planned alias. Signatures that aren't eligible (not a
 * wormhole, already connected, or no suggestion possible) are left out.
 */
export function planSignatureAliases(params: {
    parentAlias: string | null | undefined;
    originIsWormhole: boolean;
    signatures: TAliasPlanSignature[];
    staticNames?: string[];
    aliases: string[];
    scheme?: TAliasScheme;
    ignoredAlias?: string;
}): Map<number, string> {
    const statics = new Set((params.staticNames ?? []).map((name) => name.toUpperCase()));
    const isStatic = (signature: TAliasPlanSignature) => Boolean(signature.wormholeName && statics.has(signature.wormholeName.toUpperCase()));

    const candidates = params.signatures
        .filter((signature) => signature.isWormhole && !signature.isConnected)
        .toSorted((a, b) => Number(isStatic(b)) - Number(isStatic(a)) || a.id - b.id);

    const taken = [...params.aliases];
    const planned = new Map<number, string>();

    for (const signature of candidates) {
        const targetClass = signature.targetClass && signature.targetClass !== 'unknown' ? signature.targetClass : null;
        const targetIsWormhole = signature.targetIsWormhole ?? true;

        const alias = suggestAlias({
            parentAlias: params.parentAlias,
            targetIsWormhole,
            originIsWormhole: params.originIsWormhole,
            aliases: taken,
            scheme: params.scheme,
            targetKind: aliasTargetKind(targetIsWormhole, targetClass),
            ignoredAlias: params.ignoredAlias,
        });

        if (!alias) continue;
        planned.set(signature.id, alias);
        taken.push(alias);
    }

    return planned;
}
