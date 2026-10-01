import { needsSideChainLetter, suggestSideChainLetter, takenLetters } from '@/lib/sideChain';
import { describe, expect, it } from 'vitest';

describe('patch 12: side chain letters', () => {
    it('suggests Z, then Y, X… skipping letters in use', () => {
        expect(suggestSideChainLetter(new Set())).toBe('Z');
        expect(suggestSideChainLetter(takenLetters(['Daisy', 'A', 'B', 'Z', 'Z1']))).toBe('Y');
        expect(suggestSideChainLetter(takenLetters(['Z', 'Y', 'X']))).toBe('W');
        // Freed again once its chain is deleted.
        expect(suggestSideChainLetter(takenLetters(['Y']))).toBe('Z');
    });

    it('only counts single-letter aliases as taken', () => {
        expect([...takenLetters(['A1', 'z', ' Q ', '111', null])].sort()).toEqual(['Q', 'Z']);
    });

    it('asks for a letter only from the second numbered side chain on', () => {
        const otela = { rootId: 10, rootAlias: null, memberAliases: [null, '1', '11'] };
        const fresh = { rootId: 20, rootAlias: null, memberAliases: [null] };
        expect(needsSideChainLetter(20, [otela, fresh])).toBe(true);
        // The first side chain keeps plain numbers.
        expect(needsSideChainLetter(10, [otela, fresh])).toBe(false);
        expect(needsSideChainLetter(20, [fresh])).toBe(false);
        // Already lettered.
        expect(needsSideChainLetter(20, [otela, { ...fresh, rootAlias: 'Z' }])).toBe(false);
        // Two fresh chains: whichever is numbered first keeps plain numbers.
        expect(needsSideChainLetter(20, [{ ...otela, memberAliases: [null] }, fresh])).toBe(false);
    });
});
