import { aliasesBelow, aliasForSlot, guessNextAlias, homeCallsign, isIgnoredAlias, planSignatureAliases, staticSlotAlias, suggestAlias } from '@/lib/alias';
import { describe, expect, it } from 'vitest';

describe('guessNextAlias (numeric, default)', () => {
    it('numbers top-level systems sequentially', () => {
        expect(guessNextAlias(null, [])).toBe('1');
        expect(guessNextAlias(null, ['1'])).toBe('2');
        expect(guessNextAlias(null, ['1', '2'])).toBe('3');
    });

    it('extends the parent prefix for children', () => {
        expect(guessNextAlias('1', [])).toBe('11');
        expect(guessNextAlias('1', ['11', '12'])).toBe('13');
        expect(guessNextAlias('12', ['12', '121'])).toBe('122');
    });

    it('excludes grandchildren when counting direct children', () => {
        // "121" and "122" are children of "12", not of "1" — only "11" and "12"
        // count as direct children of "1", so the next is "13", not something
        // inflated by the grandchildren.
        expect(guessNextAlias('1', ['1', '11', '12', '121', '122'])).toBe('13');
    });
});

describe('guessNextAlias (alphabetical)', () => {
    const scheme = 'alphabetical' as const;

    it("letters an unaliased home's direct holes, mirroring numeric's 1, 2, 3", () => {
        expect(guessNextAlias(null, [], { scheme })).toBe('A');
        expect(guessNextAlias(null, ['A'], { scheme })).toBe('B');
        expect(guessNextAlias(null, ['A', 'B'], { scheme })).toBe('C');
    });

    it('extends the parent prefix with a letter', () => {
        expect(guessNextAlias('A', [], { scheme })).toBe('AA');
        expect(guessNextAlias('A', ['AA'], { scheme })).toBe('AB');
    });

    it('skips the reserved H, L, N, P letters', () => {
        expect(guessNextAlias('A', ['AA', 'AB', 'AC', 'AD', 'AE', 'AF', 'AG'], { scheme })).toBe('AI');
        expect(guessNextAlias('A', ['AA', 'AB', 'AC', 'AD', 'AE', 'AF', 'AG', 'AI', 'AJ', 'AK'], { scheme })).toBe('AM');
        expect(guessNextAlias('A', ['AA', 'AB', 'AC', 'AD', 'AE', 'AF', 'AG', 'AI', 'AJ', 'AK', 'AM'], { scheme })).toBe('AO');
    });

    it('indexes k-space exits per reserved letter, off the parent', () => {
        expect(guessNextAlias('A', [], { scheme, targetKind: 'h' })).toBe('AH1');
        expect(guessNextAlias('A', ['AH1'], { scheme, targetKind: 'h' })).toBe('AH2');
        expect(guessNextAlias('AC', [], { scheme, targetKind: 'l' })).toBe('ACL1');
        expect(guessNextAlias('B', ['BN1'], { scheme, targetKind: 'n' })).toBe('BN2');
        expect(guessNextAlias('A', [], { scheme, targetKind: 'p' })).toBe('AP1');
    });

    it('indexes a k-space exit off an unaliased home the same way, empty prefix and all', () => {
        expect(guessNextAlias(null, [], { scheme, targetKind: 'h' })).toBe('H1');
        expect(guessNextAlias(null, ['H1'], { scheme, targetKind: 'h' })).toBe('H2');
    });

    it('keeps the wormhole letter sequence and each k-space counter independent for mixed children', () => {
        const aliases = ['AB', 'AH1', 'AL1'];

        expect(guessNextAlias('A', aliases, { scheme })).toBe('AA');
        expect(guessNextAlias('A', aliases, { scheme, targetKind: 'h' })).toBe('AH2');
        expect(guessNextAlias('A', aliases, { scheme, targetKind: 'l' })).toBe('AL2');
    });

    it('lets a wormhole branch off a k-space node', () => {
        expect(guessNextAlias('AH1', [], { scheme })).toBe('AH1A');
    });
});

describe('guessNextAlias (gap filling)', () => {
    it('fills a freed numeric index before extending the sequence', () => {
        expect(guessNextAlias(null, ['1', '3', '4'])).toBe('2');
        expect(guessNextAlias('1', ['11', '13'])).toBe('12');
    });

    it('fills a freed letter before extending the sequence', () => {
        const scheme = 'alphabetical' as const;

        expect(guessNextAlias(null, ['A', 'C', 'D'], { scheme })).toBe('B');
        expect(guessNextAlias('A', ['AA', 'AC'], { scheme })).toBe('AB');
    });

    it('fills a freed k-space index before extending the counter', () => {
        const scheme = 'alphabetical' as const;

        expect(guessNextAlias('A', ['AH1', 'AH3'], { scheme, targetKind: 'h' })).toBe('AH2');
    });
});

describe('guessNextAlias (ignoredAlias)', () => {
    it('resets the prefix when the parent is the ignored alias, alphabetical scheme', () => {
        expect(guessNextAlias('HOME', [], { scheme: 'alphabetical', ignoredAlias: 'HOME' })).toBe('A');
        expect(guessNextAlias('HOME', ['A'], { scheme: 'alphabetical', ignoredAlias: 'HOME' })).toBe('B');
    });

    it('resets the prefix when the parent is the ignored alias, numeric scheme (home letters)', () => {
        expect(guessNextAlias('HOME', [], { ignoredAlias: 'HOME' })).toBe('B');
        expect(guessNextAlias('HOME', ['B'], { ignoredAlias: 'HOME' })).toBe('D');
    });

    it('matches the ignored alias case-insensitively', () => {
        expect(guessNextAlias('home', [], { scheme: 'alphabetical', ignoredAlias: 'HOME' })).toBe('A');
        expect(guessNextAlias('Home', [], { scheme: 'alphabetical', ignoredAlias: 'HOME' })).toBe('A');
    });

    it('leaves a non-ignored parent unaffected', () => {
        expect(guessNextAlias('AB', [], { scheme: 'alphabetical', ignoredAlias: 'HOME' })).toBe('ABA');
        expect(guessNextAlias('AB', [], { ignoredAlias: 'HOME' })).toBe('AB1');
    });

    it('is a no-op when ignoredAlias is empty', () => {
        expect(guessNextAlias('HOME', [], { scheme: 'alphabetical', ignoredAlias: '' })).toBe('HOMEA');
        expect(guessNextAlias('HOME', [], { ignoredAlias: '' })).toBe('HOME1');
    });
});

describe('guessNextAlias (case handling)', () => {
    it('upper-cases the suggestion even for a lowercase parent alias', () => {
        expect(guessNextAlias('a', [], { scheme: 'alphabetical' })).toBe('AA');
        expect(guessNextAlias('foo', [])).toBe('FOO1');
    });

    it('counts lowercase existing aliases as taken wormhole letters', () => {
        expect(guessNextAlias('A', ['aa', 'Ab'], { scheme: 'alphabetical' })).toBe('AC');
    });

    it('counts lowercase existing aliases as taken k-space indexes', () => {
        expect(guessNextAlias('a', ['ah1'], { scheme: 'alphabetical', targetKind: 'h' })).toBe('AH2');
    });

    it('counts lowercase existing aliases as taken numeric children', () => {
        expect(guessNextAlias('foo', ['foo1', 'FOO2'])).toBe('FOO3');
    });
});

describe('isIgnoredAlias', () => {
    it('matches the exact, trimmed, case-insensitive alias', () => {
        expect(isIgnoredAlias('HOME', 'HOME')).toBe(true);
        expect(isIgnoredAlias('home', 'HOME')).toBe(true);
        expect(isIgnoredAlias(' Home ', 'home')).toBe(true);
    });

    it('does not match a different alias', () => {
        expect(isIgnoredAlias('AB', 'HOME')).toBe(false);
        expect(isIgnoredAlias('HOMEA', 'HOME')).toBe(false);
    });

    it('never matches when the alias is empty', () => {
        expect(isIgnoredAlias('', 'HOME')).toBe(false);
        expect(isIgnoredAlias(null, 'HOME')).toBe(false);
        expect(isIgnoredAlias(undefined, 'HOME')).toBe(false);
    });

    it('never matches when the ignored alias is empty (feature disabled)', () => {
        expect(isIgnoredAlias('HOME', '')).toBe(false);
        expect(isIgnoredAlias('HOME', null)).toBe(false);
        expect(isIgnoredAlias('HOME', undefined)).toBe(false);
    });
});

describe('suggestAlias', () => {
    it('threads the scheme and target kind through to guessNextAlias', () => {
        const alias = suggestAlias({
            parentAlias: 'A',
            targetIsWormhole: false,
            originIsWormhole: true,
            aliases: ['A'],
            scheme: 'alphabetical',
            targetKind: 'h',
        });

        expect(alias).toBe('AH1');
    });

    it('still returns null when neither side of the jump is part of a chain', () => {
        const alias = suggestAlias({
            parentAlias: null,
            targetIsWormhole: false,
            originIsWormhole: false,
            aliases: [],
            scheme: 'alphabetical',
        });

        expect(alias).toBeNull();
    });
});

describe('guessNextAlias (numeric, more than nine holes)', () => {
    it('continues with letters after 9', () => {
        const nine = ['11', '12', '13', '14', '15', '16', '17', '18', '19'];
        expect(guessNextAlias('1', nine)).toBe('1A');
        expect(guessNextAlias('1', [...nine, '1A'])).toBe('1B');
    });

    it('never treats a letter child as ambiguous with a deeper numeric alias', () => {
        // "1A" is the tenth hole off 1; "111" is the first hole off 11.
        expect(guessNextAlias('11', ['1', '11', '1A'])).toBe('111');
        expect(guessNextAlias('1A', ['1', '1A'])).toBe('1A1');
    });

    it('reuses a freed slot before growing, letters included', () => {
        const nine = ['11', '12', '13', '14', '15', '16', '17', '18', '19'];
        expect(guessNextAlias('1', [...nine.filter((a) => a !== '12'), '1A'])).toBe('12');
    });

    it("names home's non-static holes with the military letters B, D, G", () => {
        expect(guessNextAlias('Daisy', ['Daisy'], { ignoredAlias: 'Daisy' })).toBe('B');
        expect(guessNextAlias('Daisy', ['Daisy', 'B'], { ignoredAlias: 'Daisy' })).toBe('D');
        expect(guessNextAlias('Daisy', ['Daisy', 'A', 'B', 'D'], { ignoredAlias: 'Daisy' })).toBe('G');
    });
});

describe('planSignatureAliases (patch 9: home letters, static 0, locked numbers)', () => {
    const home = { parentAlias: 'Daisy', originIsWormhole: true, ignoredAlias: 'Daisy' };
    const inA = { parentAlias: 'A', originIsWormhole: true, ignoredAlias: 'Daisy' };
    const wh = (id: number, extra: Partial<{ isConnected: boolean; lockedAlias: string | null; isStatic: boolean }> = {}) => ({
        id,
        isWormhole: true,
        isConnected: false,
        ...extra,
    });

    it("home: keeps A for the static and names other holes B, D, G", () => {
        const planned = planSignatureAliases({ ...home, aliases: ['Daisy'], signatures: [wh(10), wh(11), wh(12)] });
        expect(planned.get(10)).toBe('B');
        expect(planned.get(11)).toBe('D');
        expect(planned.get(12)).toBe('G');
    });

    it('home: the hole marked Static is always A, even when added later', () => {
        const planned = planSignatureAliases({ ...home, aliases: ['Daisy'], signatures: [wh(10), wh(11, { isStatic: true })] });
        expect(planned.get(11)).toBe('A');
        expect(planned.get(10)).toBe('B');
    });

    it('elsewhere: static is 0, the first other hole is 1', () => {
        const planned = planSignatureAliases({ ...inA, aliases: ['Daisy', 'A'], signatures: [wh(1), wh(2, { isStatic: true }), wh(3)] });
        expect(planned.get(2)).toBe('A0');
        expect(planned.get(1)).toBe('A1');
        expect(planned.get(3)).toBe('A2');
    });

    it('a hole numbered before the static was found keeps its number (no clashes)', () => {
        const first = planSignatureAliases({ ...inA, aliases: ['Daisy', 'A'], signatures: [wh(5)] });
        expect(first.get(5)).toBe('A1');

        const later = planSignatureAliases({
            ...inA,
            aliases: ['Daisy', 'A'],
            signatures: [wh(1), wh(2), wh(4, { isStatic: true }), wh(5, { lockedAlias: 'A1' })],
        });
        expect(later.get(4)).toBe('A0');
        expect(later.get(5)).toBe('A1');
        expect(later.get(1)).toBe('A2');
        expect(later.get(2)).toBe('A3');
        expect(new Set(later.values()).size).toBe(later.size);
    });

    it('never gives the static slot to a hole that is not marked Static', () => {
        expect(planSignatureAliases({ ...home, aliases: ['Daisy'], signatures: [wh(10)] }).get(10)).toBe('B');
        expect(planSignatureAliases({ ...inA, aliases: ['Daisy', 'A'], signatures: [wh(10)] }).get(10)).toBe('A1');
    });

    it('reuses the lowest free number after a signature is deleted', () => {
        const planned = planSignatureAliases({
            ...inA,
            aliases: ['Daisy', 'A'],
            signatures: [wh(10, { lockedAlias: 'A0', isStatic: true }), wh(12, { lockedAlias: 'A2' }), wh(13)],
        });
        expect(planned.get(13)).toBe('A1');
    });

    it('skips numbers used by systems on the map and leaves connected holes without a locked number out', () => {
        const planned = planSignatureAliases({
            ...home,
            aliases: ['Daisy', 'A', 'B'],
            signatures: [wh(10, { isConnected: true }), { id: 11, isWormhole: false, isConnected: false }, wh(12)],
        });
        expect(planned.has(10)).toBe(false);
        expect(planned.has(11)).toBe(false);
        expect(planned.get(12)).toBe('D');
    });

    it('a second hole marked Static does not steal the static slot', () => {
        const planned = planSignatureAliases({
            ...home,
            aliases: ['Daisy'],
            signatures: [wh(10, { lockedAlias: 'A', isStatic: true }), wh(11, { isStatic: true })],
        });
        expect(planned.get(11)).toBe('B');
    });

    it('elsewhere continues with letters after 9', () => {
        const locked = ['11', '12', '13', '14', '15', '16', '17', '18', '19'].map((alias, index) => wh(index + 1, { lockedAlias: alias }));
        const planned = planSignatureAliases({ parentAlias: '1', originIsWormhole: true, ignoredAlias: 'Daisy', aliases: ['1'], signatures: [...locked, wh(50)] });
        expect(planned.get(50)).toBe('1A');
    });

    it('a chain started outside home numbers from 1 with static 0', () => {
        const planned = planSignatureAliases({
            parentAlias: null,
            originIsWormhole: true,
            ignoredAlias: 'Daisy',
            aliases: [],
            signatures: [wh(1), wh(2, { isStatic: true })],
        });
        expect(planned.get(1)).toBe('1');
        expect(planned.get(2)).toBe('0');
    });
});

describe('static slot, callsigns and hand-set numbers', () => {
    it('reserves A in home and 0 elsewhere', () => {
        expect(staticSlotAlias('Daisy', 'Daisy')).toBe('A');
        expect(staticSlotAlias('A', 'Daisy')).toBe('A0');
        expect(staticSlotAlias('A1', 'Daisy')).toBe('A10');
    });

    it('builds an alias from a single slot valid for that system', () => {
        expect(aliasForSlot('A', '4', 'Daisy')).toBe('A4');
        expect(aliasForSlot('A', '0', 'Daisy')).toBe('A0');
        expect(aliasForSlot('Daisy', 'b', 'Daisy')).toBe('B');
        expect(aliasForSlot('Daisy', 'A', 'Daisy')).toBe('A');
        expect(aliasForSlot('Daisy', 'C', 'Daisy')).toBeNull();
        expect(aliasForSlot('Daisy', 'E', 'Daisy')).toBeNull();
        expect(aliasForSlot('Daisy', '1', 'Daisy')).toBeNull();
        expect(aliasForSlot('A', '10', 'Daisy')).toBeNull();
    });

    it('names home letters with the military alphabet', () => {
        expect(homeCallsign('A')).toBe('Alpha');
        expect(homeCallsign('b')).toBe('Bravo');
        expect(homeCallsign('X')).toBe('X-ray');
        expect(homeCallsign('E')).toBeNull();
        expect(homeCallsign('A1')).toBeNull();
        expect(homeCallsign('1')).toBeNull();
    });
});

describe('patch 15: systems mapped further down a hole', () => {
    it('counts only chain numbers, never the home name that starts with the same letter', () => {
        expect(aliasesBelow(['Daisy', 'D1', 'D12', 'B', 'DA'], 'D', 'Daisy')).toEqual(['D1', 'D12', 'DA']);
        expect(aliasesBelow(['Daisy', 'B1'], 'D', 'Daisy')).toEqual([]);
        expect(aliasesBelow(['A1'], '', 'Daisy')).toEqual([]);
    });
});
