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
    /** The parent is a combat home: its holes start a fresh chain numbered 1, 2, 3 (static 0). */
    combatHome?: boolean;
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
 * The numeric scheme's slots for holes that aren't the static: 1-9, then A-Z
 * once a system has more than nine. Every slot is a single character, so "110"
 * can never be confused between "the tenth hole off 1" and "the first hole off
 * 11" — the tenth hole off 1 is "1A". Slot 0 is the static's (see STATIC_SLOT).
 */
const NUMERIC_SLOTS = '123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

/** Outside home, the hole marked as the system's static is always slot 0. */
const STATIC_SLOT = '0';

/**
 * Home (the map's ignored alias, e.g. Daisy) uses the US military alphabet:
 * the static is always A / Alpha, other holes take the letters below in order.
 * C, E, F, H, I, L and O are skipped (hostile group name, k-space letters, and
 * letters that read like 1 / 0 inside a chain such as "A101242").
 */
const HOME_STATIC_SLOT = 'A';
const HOME_SLOTS = 'BDGJKMNPQRSTUVWXYZ';

/** The spoken name for each of home's letters, used in home's bookmarks. */
export const HOME_CALLSIGNS: Readonly<Record<string, string>> = {
    A: 'Alpha',
    B: 'Bravo',
    D: 'Delta',
    G: 'Golf',
    J: 'Juliett',
    K: 'Kilo',
    M: 'Mike',
    N: 'November',
    P: 'Papa',
    Q: 'Quebec',
    R: 'Romeo',
    S: 'Sierra',
    T: 'Tango',
    U: 'Uniform',
    V: 'Victor',
    W: 'Whiskey',
    X: 'X-ray',
    Y: 'Yankee',
    Z: 'Zulu',
};

/**
 * The callsign for a hole directly off home ("A" → "Alpha"), or null for any
 * other alias. Only single-letter aliases can be home's holes.
 */
export function homeCallsign(alias: string | null | undefined): string | null {
    const normalized = (alias ?? '').trim().toUpperCase();
    return normalized.length === 1 ? (HOME_CALLSIGNS[normalized] ?? null) : null;
}

/** The static slot and the other slots for a system, depending on whether it is home. */
function slotsFor(isHome: boolean): { staticSlot: string; slots: string } {
    return isHome ? { staticSlot: HOME_STATIC_SLOT, slots: HOME_SLOTS } : { staticSlot: STATIC_SLOT, slots: NUMERIC_SLOTS };
}

/** Whether a system uses home's military letters: the map's home alias, unless it is a combat home. */
function usesHomeLetters(alias: string | null | undefined, ignoredAlias: string | null | undefined, combatHome: boolean): boolean {
    return !combatHome && isIgnoredAlias(alias, ignoredAlias);
}

/**
 * The lowest unused numeric slot extending `prefix`, so a slot freed by a
 * deleted system is reused before the sequence grows. Only aliases exactly one
 * character longer than `prefix` count as direct children, so deeper
 * descendants ("121" under "1") are never mistaken for a direct child.
 * Expects `prefix` and `aliases` already upper-cased by `guessNextAlias`.
 */
function nextNumericSlot(prefix: string, aliases: string[], slots: string = NUMERIC_SLOTS): string {
    const used = new Set<number>();
    for (const alias of aliases) {
        if (alias.length !== prefix.length + 1 || !alias.startsWith(prefix)) continue;

        const index = slots.indexOf(alias.slice(prefix.length));
        if (index !== -1) {
            used.add(index);
        }
    }

    let index = 0;
    while (used.has(index)) {
        index++;
    }
    return slots[Math.min(index, slots.length - 1)];
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
    const combatHome = Boolean(opts?.combatHome);
    const isHome = usesHomeLetters(parentAlias, opts?.ignoredAlias, combatHome);
    const prefix = isHome || combatHome ? '' : (parentAlias ?? '').trim().toUpperCase();

    const knownAliases = aliases.map((alias) => alias.trim().toUpperCase());

    if (opts?.scheme === 'alphabetical') {
        return guessNextAlphabeticalAlias(prefix, knownAliases, opts.targetKind);
    }

    // A guess is never the static: home's holes start at B, others at 1.
    return `${prefix}${nextNumericSlot(prefix, knownAliases, slotsFor(isHome).slots)}`;
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
    /** The origin is a combat home (its holes number 1, 2, 3 with static 0). */
    combatHome?: boolean;
}): string | null {
    const originIsAliased = Boolean(params.parentAlias && params.parentAlias.trim());

    if (!params.targetIsWormhole && !params.originIsWormhole && !originIsAliased && !params.combatHome) {
        return null;
    }

    return guessNextAlias(params.parentAlias, params.aliases, {
        scheme: params.scheme,
        targetKind: params.targetKind,
        ignoredAlias: params.ignoredAlias,
        combatHome: params.combatHome,
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
    /** Marked as the system's static: takes the reserved static slot (A in home, 0 elsewhere). */
    isStatic?: boolean;
    /** Whether the hole leads to wormhole space; unknown destinations count as wormhole space. */
    targetIsWormhole?: boolean;
    /** The known destination class, if identified ("unknown"/null otherwise). */
    targetClass?: string | null;
    /**
     * Only holds on to its locked number, never gets a new one: a signature
     * missing from the last paste (ignored in game, or gone) still owns its
     * number until it is deleted.
     */
    reserveOnly?: boolean;
};

/**
 * The chain prefix for a system: its alias, or "" for the ignored (home) alias
 * and for a combat home (whose chain starts again at 1, 2, 3).
 */
export function chainPrefix(parentAlias: string | null | undefined, ignoredAlias?: string | null, combatHome = false): string {
    if (combatHome) return '';
    const prefix = (parentAlias ?? '').trim().toUpperCase();
    return isIgnoredAlias(prefix, ignoredAlias) ? '' : prefix;
}

/** The alias reserved for a system's static: "A" (Alpha) in home, "0" in a combat home, otherwise slot 0, e.g. "A0", "10". */
export function staticSlotAlias(parentAlias: string | null | undefined, ignoredAlias?: string | null, combatHome = false): string {
    const isHome = usesHomeLetters(parentAlias, ignoredAlias, combatHome);
    return `${chainPrefix(parentAlias, ignoredAlias, combatHome)}${slotsFor(isHome).staticSlot}`;
}

/**
 * The alias for a single slot character in a system, or null when the slot is
 * invalid there: home takes its military letters (A, B, D, G…), everywhere
 * else 0 (the static) or 1-9 / A-Z.
 */
export function aliasForSlot(parentAlias: string | null | undefined, slot: string, ignoredAlias?: string | null, combatHome = false): string | null {
    const normalized = slot.trim().toUpperCase();
    const { staticSlot, slots } = slotsFor(usesHomeLetters(parentAlias, ignoredAlias, combatHome));
    if (normalized.length !== 1 || !(staticSlot + slots).includes(normalized)) return null;
    return `${chainPrefix(parentAlias, ignoredAlias, combatHome)}${normalized}`;
}

/**
 * Hand out chain numbers to the wormhole signatures of one system.
 *
 * Numeric scheme (the Devil's Rage convention):
 * - a number stored on a signature (locked on copy or jump) is always kept;
 * - the static slot is reserved for the hole marked as the static: only it
 *   can take it (A / Alpha in home, 0 everywhere else);
 * - every other unjumped hole gets the lowest free slot (home: B, D, G…;
 *   elsewhere 1-9 then A-Z), in the order the signatures were added;
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
    /** The system is a combat home: its holes start a fresh chain (1, 2, 3, static 0). */
    combatHome?: boolean;
    /**
     * Combat chains number holes in the order they are jumped: unjumped holes
     * wait in "limbo" without a number, the static included.
     */
    limbo?: boolean;
}): Map<number, string> {
    const planned = new Map<number, string>();
    const wormholes = params.signatures.filter((signature) => signature.isWormhole).toSorted((a, b) => a.id - b.id);

    if (params.scheme === 'alphabetical') {
        return planAlphabetical(params, wormholes);
    }

    const combatHome = Boolean(params.combatHome);
    const prefix = chainPrefix(params.parentAlias, params.ignoredAlias, combatHome);
    const { staticSlot: staticChar, slots } = slotsFor(usesHomeLetters(params.parentAlias, params.ignoredAlias, combatHome));
    const staticSlot = `${prefix}${staticChar}`;
    const taken = new Set(params.aliases.map((alias) => alias.trim().toUpperCase()));

    // Every locked number is taken, whatever the signature is now (a hole later
    // recategorised, or one missing from the last paste, still owns its number).
    for (const signature of params.signatures) {
        const locked = signature.lockedAlias?.trim().toUpperCase();
        if (!locked) continue;
        taken.add(locked);
        if (signature.isWormhole) planned.set(signature.id, locked);
    }

    const unnumbered = wormholes.filter((signature) => !planned.has(signature.id) && !signature.isConnected && !signature.reserveOnly);

    // Combat chains never hand the static its 0 on their own (patch 12): it waits
    // in limbo like any other hole, until a scanner renames it by hand.
    const staticHole = params.limbo ? undefined : unnumbered.find((signature) => signature.isStatic);
    if (staticHole && !taken.has(staticSlot)) {
        planned.set(staticHole.id, staticSlot);
        taken.add(staticSlot);
    }

    for (const signature of unnumbered) {
        if (planned.has(signature.id) || params.limbo) continue;

        let index = 0;
        while (index < slots.length - 1 && taken.has(`${prefix}${slots[index]}`)) {
            index++;
        }

        const alias = `${prefix}${slots[index]}`;
        planned.set(signature.id, alias);
        taken.add(alias);
    }

    return planned;
}

/** The alphabetical scheme keeps the stock behaviour: locked numbers first, then the next free letter. */
function planAlphabetical(
    params: { parentAlias: string | null | undefined; originIsWormhole: boolean; aliases: string[]; ignoredAlias?: string; combatHome?: boolean },
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
        if (planned.has(signature.id) || signature.isConnected || signature.reserveOnly) continue;

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
            combatHome: params.combatHome,
        });

        if (!alias) continue;
        planned.set(signature.id, alias);
        taken.push(alias);
    }

    return planned;
}

// ---- Display (patch 11) -------------------------------------------------------

/**
 * A chain alias in groups of three, easier to read at a glance:
 * "A111102111140" → "A111-102-111-140", "1111111" → "111-111-1". A leading
 * letter (home's hole, e.g. the "A" of Alpha) stays in front of the first group.
 * Only chain-looking aliases (upper-case letters and digits, with a digit
 * somewhere) are grouped; anything else ("Daisy", "HOME") is left alone.
 */
export function formatAliasPath(alias: string | null | undefined): string {
    const value = (alias ?? '').trim();
    if (!/^[A-Z0-9]+$/.test(value) || !/\d/.test(value)) return value;

    const head = /^[A-Z]/.test(value) ? value[0] : '';
    const rest = value.slice(head.length);
    if (rest.length <= 3) return value;

    const groups = rest.match(/.{1,3}/g) ?? [rest];
    return `${head}${groups.join('-')}`;
}

/**
 * How an alias is shown to people: home's holes by their callsign ("A" →
 * "Alpha"), chain aliases grouped in threes ("A111-102"). The stored alias is
 * never changed. The alphabetical scheme keeps its aliases as they are.
 */
export function displayAlias(alias: string | null | undefined, scheme?: TAliasScheme): string {
    const value = (alias ?? '').trim();
    if (scheme === 'alphabetical') return value;
    return homeCallsign(value) ?? formatAliasPath(value);
}

/** The hole's own number within its system: the last character of a chain alias ("1121" → "1"). */
export function localSlot(alias: string | null | undefined): string {
    const value = (alias ?? '').trim();
    return value ? value[value.length - 1] : '';
}
