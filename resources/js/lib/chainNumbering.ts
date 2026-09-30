/**
 * Devil's Rage chain-numbering rules that sit on top of the alias planning in
 * `alias.ts`: the Static / Wandering / K162 markers and their bookmark suffix,
 * automatic ticking when a hole's type is set, and hand-set numbers.
 */

/** The marker appended to `{class}` in a bookmark: s = static, w = wandering, k = K162. */
export type TConnectionFlag = 's' | 'w' | 'k' | '';

export type TChainFlags = {
    is_static?: boolean | null;
    is_wandering?: boolean | null;
    /** The identified wormhole type, e.g. "V753" or "K162". */
    wormholeName?: string | null;
};

/** Whether a wormhole type is a K162 (the far end of someone else's hole). */
export function isK162(wormholeName: string | null | undefined): boolean {
    return (wormholeName ?? '').trim().toUpperCase() === 'K162';
}

/** The suffix for `{class}`: a K162 is always "k"; otherwise static "s", wandering "w", or nothing. */
export function connectionFlag(flags: TChainFlags): TConnectionFlag {
    if (isK162(flags.wormholeName)) return 'k';
    if (flags.is_static) return 's';
    if (flags.is_wandering) return 'w';
    return '';
}

/**
 * The Static / Wandering flags to set automatically when a hole's type changes.
 * Setting the type to one of the system's static types ticks Static while no
 * other hole in the system is the static, otherwise Wandering (another hole of
 * the static's type). Any other type, including a K162, clears both.
 */
export function autoFlagsForType(params: {
    wormholeName: string | null | undefined;
    staticNames: string[];
    otherStaticExists: boolean;
}): { is_static: boolean; is_wandering: boolean } {
    const name = (params.wormholeName ?? '').trim().toUpperCase();
    const isStaticType = name !== '' && !isK162(name) && params.staticNames.some((staticName) => staticName.trim().toUpperCase() === name);

    if (!isStaticType) return { is_static: false, is_wandering: false };

    return params.otherStaticExists ? { is_static: false, is_wandering: true } : { is_static: true, is_wandering: false };
}

/**
 * Check a hand-set number. `alias` is the full alias for the chosen slot (see
 * `aliasForSlot`), or null when the slot itself was invalid. `usedBy` maps the
 * aliases already taken in the system to a label for the error message.
 */
export function validateManualAlias(alias: string | null, usedBy: Map<string, string>): { ok: true; alias: string } | { ok: false; error: string } {
    if (!alias) {
        return { ok: false, error: 'Enter a single slot: 1-9 or A-Z.' };
    }

    const owner = usedBy.get(alias.toUpperCase());
    if (owner) {
        return { ok: false, error: `Number ${alias} is already used by ${owner}.` };
    }

    return { ok: true, alias: alias.toUpperCase() };
}
