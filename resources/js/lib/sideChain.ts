/**
 * Side chains (patch 12): chains not linked to Daisy. The first one numbers
 * its holes plainly (Otela → 1, 11, 111); from the second on, each chain gets a
 * letter so its numbers can't be confused: Z (Zulu), Y (Yankee), X… counting
 * back from Z. The start takes the letter, its holes read Z1, Z11, Z111-1.
 */

/** The letters offered, in suggestion order. A is never offered: Alpha is always Daisy's static. */
export const SIDE_CHAIN_LETTERS = 'ZYXWVUTSRQPONMLKJIHGFEDCB';

/** Letters already taken: every single-letter alias on the map (Daisy's holes, other side chains). */
export function takenLetters(aliases: readonly (string | null | undefined)[]): Set<string> {
    const taken = new Set<string>();
    for (const alias of aliases) {
        const value = (alias ?? '').trim().toUpperCase();
        if (/^[A-Z]$/.test(value)) taken.add(value);
    }
    return taken;
}

/** The letter to suggest: the first free one counting back from Z, or null when all are taken. */
export function suggestSideChainLetter(taken: ReadonlySet<string>): string | null {
    for (const letter of SIDE_CHAIN_LETTERS) {
        if (!taken.has(letter)) return letter;
    }
    return null;
}

export type TSideChainInfo = {
    rootId: number;
    /** The start's alias: empty for a plain-numbered chain (or one not numbered yet). */
    rootAlias: string | null | undefined;
    /** Aliases of the systems in the chain (the start included). */
    memberAliases: readonly (string | null | undefined)[];
};

/**
 * Whether the side chain starting at `rootId` needs a letter before its holes
 * are numbered: its start has no alias and nothing in it is numbered yet,
 * while another side chain already uses the plain numbers.
 */
export function needsSideChainLetter(rootId: number, chains: readonly TSideChainInfo[]): boolean {
    const chain = chains.find((candidate) => candidate.rootId === rootId);
    if (!chain || (chain.rootAlias ?? '').trim()) return false;
    const numbered = (aliases: readonly (string | null | undefined)[]) => aliases.some((alias) => Boolean((alias ?? '').trim()));
    if (numbered(chain.memberAliases)) return false;
    return chains.some((other) => other.rootId !== rootId && !(other.rootAlias ?? '').trim() && numbered(other.memberAliases));
}
