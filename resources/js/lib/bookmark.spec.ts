import { buildSignatureBookmark, formatBookmarkName, isReturnBookmark, TBookmarkFormats, visibleBookmarkName } from '@/lib/bookmark';
import { describe, expect, it } from 'vitest';

const NUMERIC_FORMATS: TBookmarkFormats = {};
const ALPHABETICAL_FORMATS: TBookmarkFormats & { bookmark_alias_scheme: 'alphabetical' } = { bookmark_alias_scheme: 'alphabetical' };

function baseSignature(overrides: Partial<Parameters<typeof buildSignatureBookmark>[0]['signature']> = {}) {
    return {
        signature_id: 'ABC-123',
        ship_size: null,
        mass_status: null,
        lifetime: 'healthy' as const,
        wormhole: null,
        signature_type: null,
        ...overrides,
    };
}

describe('buildSignatureBookmark (connected)', () => {
    it('uses the real target alias, class and name', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature(),
            currentSystem: { alias: 'HOME' },
            connectionTarget: {
                alias: 'AC',
                occupier_alias: null,
                solarsystem: { class: 'h', name: 'Jita', region: { name: 'The Forge' } },
            },
            aliases: [],
            formats: { ...NUMERIC_FORMATS, bookmark_format_kspace: '{alias} {class} {name} {region}' },
        });

        expect(name).toBe('AC HS Jita The Forge');
    });

    it('falls back to the guessed alias when the target has none yet', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature(),
            currentSystem: { alias: 'A' },
            connectionTarget: {
                alias: null,
                occupier_alias: null,
                solarsystem: { class: '3', name: 'J123456' },
            },
            aliases: [],
            formats: { ...NUMERIC_FORMATS, bookmark_format_wormhole: '{alias} {sig} {class}' },
        });

        expect(name).toBe('A1 ABC C3');
    });

    it('picks a reserved-letter guess for the fallback under the alphabetical scheme', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature(),
            currentSystem: { alias: 'A' },
            connectionTarget: {
                alias: '',
                occupier_alias: null,
                solarsystem: { class: 'l', name: 'Amamake' },
            },
            aliases: [],
            formats: { ...ALPHABETICAL_FORMATS, bookmark_format_kspace: '{alias} {class}' },
        });

        expect(name).toBe('AL1 LS');
    });

    it('matches formatBookmarkName for an already-aliased target', () => {
        const connectionTarget = { alias: 'ZZ', occupier_alias: 'Occupier', solarsystem: { class: '3' as const, name: 'J1' } };
        const context = { signatureId: 'ABC-123', shipSize: null, massStatus: null, lifetime: 'healthy' as const, wormholeCode: null };

        const viaBuilder = buildSignatureBookmark({
            signature: baseSignature(),
            currentSystem: { alias: 'HOME' },
            connectionTarget,
            aliases: [],
            formats: NUMERIC_FORMATS,
        });

        expect(viaBuilder).toBe(formatBookmarkName(connectionTarget, context, NUMERIC_FORMATS));
    });
});

describe('buildSignatureBookmark (unconnected, numeric)', () => {
    it('numbers the next child under the current alias', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature(),
            currentSystem: { alias: '1' },
            connectionTarget: null,
            aliases: [],
            formats: NUMERIC_FORMATS,
        });

        expect(name).toBe('11 ABC');
    });

    it('numbers from 1 for an unaliased current system', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature(),
            currentSystem: { alias: null },
            connectionTarget: null,
            aliases: [],
            formats: NUMERIC_FORMATS,
        });

        expect(name).toBe('1 ABC');
    });
});

describe('buildSignatureBookmark (unconnected, alphabetical)', () => {
    it('letters the next wormhole child, skipping H/L/N/P', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_type: { target_class: '3' } }),
            currentSystem: { alias: 'A' },
            connectionTarget: null,
            aliases: ['AA', 'AB', 'AC', 'AD', 'AE', 'AF', 'AG'],
            formats: ALPHABETICAL_FORMATS,
        });

        expect(name).toBe('AI ABC C3');
    });

    it('assigns a reserved letter and per-type index for a k-space target', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_type: { target_class: 'h' } }),
            currentSystem: { alias: 'A' },
            connectionTarget: null,
            aliases: [],
            formats: ALPHABETICAL_FORMATS,
        });

        expect(name).toBe('AH1 HS ABC');
    });

    it('reserves N for a null-sec target', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_type: { target_class: 'n' } }),
            currentSystem: { alias: 'B' },
            connectionTarget: null,
            aliases: ['BN1'],
            formats: ALPHABETICAL_FORMATS,
        });

        expect(name).toBe('BN2 NS ABC');
    });

    it('reserves P for a Pochven target', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_type: { target_class: 'p' } }),
            currentSystem: { alias: 'A' },
            connectionTarget: null,
            aliases: [],
            formats: ALPHABETICAL_FORMATS,
        });

        expect(name).toBe('AP1 P ABC');
    });

    it('treats an unidentified type as a wormhole for the guess and leaves the class blank', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_type: { target_class: null } }),
            currentSystem: { alias: 'A' },
            connectionTarget: null,
            aliases: ['AA'],
            formats: ALPHABETICAL_FORMATS,
        });

        // Next plain letter (B), not a reserved k-space letter, and no "UNKNOWN" class token.
        expect(name).toBe('AB ABC');
    });

    it('treats "unknown" the same as a missing target class', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_type: { target_class: 'unknown' } }),
            currentSystem: { alias: 'A' },
            connectionTarget: null,
            aliases: ['AA'],
            formats: ALPHABETICAL_FORMATS,
        });

        expect(name).toBe('AB ABC');
    });
});

describe('buildSignatureBookmark (class token)', () => {
    it('renders wormhole classes as "C<n>"', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_type: { target_class: '5' } }),
            currentSystem: { alias: null },
            connectionTarget: null,
            aliases: [],
            formats: NUMERIC_FORMATS,
        });

        expect(name).toBe('1 ABC C5');
    });

    it.each([
        ['h', 'HS'],
        ['l', 'LS'],
        ['n', 'NS'],
    ])('renders k-space class "%s" as "%s"', (targetClass, label) => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_type: { target_class: targetClass } }),
            currentSystem: { alias: null, class: '5' },
            connectionTarget: null,
            aliases: [],
            formats: { ...NUMERIC_FORMATS, bookmark_format_kspace: '{alias} {sig} {class}' },
        });

        expect(name).toBe(`1 ABC ${label}`);
    });
});

describe('buildSignatureBookmark (alias eligibility)', () => {
    it('leaves the alias blank for a connected k-space target reached from an unaliased k-space system', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature(),
            currentSystem: { alias: null, class: 'l' },
            connectionTarget: {
                alias: null,
                occupier_alias: null,
                solarsystem: { class: 'h', name: 'Jita', region: { name: 'The Forge' } },
            },
            aliases: [],
            formats: { ...NUMERIC_FORMATS, bookmark_format_kspace: '{alias} {sig} {name}' },
        });

        expect(name).toBe('ABC Jita');
    });

    it('still guesses for a k-space target reached from an unaliased wormhole system', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature(),
            currentSystem: { alias: null, class: '3' },
            connectionTarget: {
                alias: null,
                occupier_alias: null,
                solarsystem: { class: 'h', name: 'Jita', region: { name: 'The Forge' } },
            },
            aliases: [],
            formats: { ...NUMERIC_FORMATS, bookmark_format_kspace: '{alias} {sig} {name}' },
        });

        expect(name).toBe('1 ABC Jita');
    });

    it('still guesses for a k-space target reached from an aliased k-space system', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature(),
            currentSystem: { alias: '2', class: 'l' },
            connectionTarget: {
                alias: null,
                occupier_alias: null,
                solarsystem: { class: 'h', name: 'Jita', region: { name: 'The Forge' } },
            },
            aliases: ['2'],
            formats: { ...NUMERIC_FORMATS, bookmark_format_kspace: '{alias} {sig} {name}' },
        });

        expect(name).toBe('21 ABC Jita');
    });

    it('still guesses for a wormhole target reached from an unaliased k-space system', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature(),
            currentSystem: { alias: null, class: 'l' },
            connectionTarget: {
                alias: null,
                occupier_alias: null,
                solarsystem: { class: '3', name: 'J123456' },
            },
            aliases: [],
            formats: NUMERIC_FORMATS,
        });

        expect(name).toBe('1 ABC C3');
    });

    it('leaves the alias blank for an unconnected k-space signature in an unaliased k-space system', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_type: { target_class: 'h' } }),
            currentSystem: { alias: null, class: 'l' },
            connectionTarget: null,
            aliases: [],
            formats: { ...NUMERIC_FORMATS, bookmark_format_kspace: '{alias} {sig} {class}' },
        });

        expect(name).toBe('ABC HS');
    });
});

describe('isReturnBookmark', () => {
    it('is true when the destination alias is a substring of the opposite alias (up-chain)', () => {
        expect(isReturnBookmark('A', 'AB', null)).toBe(true);
    });

    it('is false when the opposite alias is a substring of the destination (down-chain)', () => {
        expect(isReturnBookmark('AB', 'A', null)).toBe(false);
    });

    it('is false for a sibling branch whose alias is a non-prefix substring of the opposite alias', () => {
        expect(isReturnBookmark('B', 'AB', null)).toBe(false);
        expect(isReturnBookmark('1', '21', null)).toBe(false);
    });

    it('is true when the destination alias is the ignored alias, regardless of the opposite alias', () => {
        expect(isReturnBookmark('HOME', 'AB', 'HOME')).toBe(true);
    });

    it('is false when either alias is empty', () => {
        expect(isReturnBookmark('', 'AB', null)).toBe(false);
        expect(isReturnBookmark('A', '', null)).toBe(false);
        expect(isReturnBookmark(null, null, null)).toBe(false);
    });

    it('is false for an ignored-alias destination when the opposite alias is omitted', () => {
        expect(isReturnBookmark('HOME', null, 'HOME')).toBe(false);
        expect(isReturnBookmark('HOME', '', 'HOME')).toBe(false);
    });
});

describe('formatBookmarkName (oppositeAlias / return format)', () => {
    const system = { alias: 'A', solarsystem: { class: '3' as const, name: 'J123456' } };
    const context = { signatureId: 'ABC-123', shipSize: null, massStatus: null, lifetime: 'healthy' as const, wormholeCode: null };
    // Alphabetical-style aliases ("A", "AB"): no home callsigns.
    const formats: TBookmarkFormats = {
        bookmark_format_wormhole: '{alias} {sig} {class}',
        bookmark_format_return: '*{alias} {sig} {class}',
        bookmark_alias_scheme: 'alphabetical',
    };

    it('renders the return template when the destination is up-chain of the opposite alias', () => {
        expect(formatBookmarkName(system, context, formats, 'AB')).toBe('*A ABC C3');
    });

    it('renders the return template when the destination is the ignored alias', () => {
        const home = { ...system, alias: 'HOME' };
        expect(formatBookmarkName(home, context, { ...formats, bookmark_ignored_alias: 'HOME' }, 'AB')).toBe('*HOME ABC C3');
    });

    it('renders the forward wormhole/k-space template when not a return', () => {
        expect(formatBookmarkName(system, context, formats, 'B')).toBe('A ABC C3');
    });

    it('never selects the return format when oppositeAlias is omitted (legacy callers)', () => {
        expect(formatBookmarkName(system, context, formats)).toBe('A ABC C3');
    });
});

describe('buildSignatureBookmark (detectReturn)', () => {
    it('renders the return template for an up-chain connected target when detectReturn is true', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature(),
            currentSystem: { alias: 'AB' },
            connectionTarget: { alias: 'A', occupier_alias: null, solarsystem: { class: '3', name: 'J1' } },
            aliases: [],
            formats: { ...ALPHABETICAL_FORMATS, bookmark_format_return: '*{alias} {sig} {class}' },
            detectReturn: true,
        });

        expect(name).toBe('*A ABC C3');
    });

    it('renders the return template for a connected target named the ignored (home) alias when detectReturn is true', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature(),
            currentSystem: { alias: 'AB' },
            connectionTarget: { alias: 'HOME', occupier_alias: null, solarsystem: { class: '3', name: 'J1' } },
            aliases: [],
            formats: { ...ALPHABETICAL_FORMATS, bookmark_format_return: '*{alias} {sig} {class}', bookmark_ignored_alias: 'HOME' },
            detectReturn: true,
        });

        expect(name).toBe('*HOME ABC C3');
    });

    it('renders the normal forward template for a down-chain target when detectReturn is true', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature(),
            currentSystem: { alias: 'A' },
            connectionTarget: { alias: 'AB', occupier_alias: null, solarsystem: { class: '3', name: 'J1' } },
            aliases: [],
            formats: { ...ALPHABETICAL_FORMATS, bookmark_format_return: '*{alias} {sig} {class}' },
            detectReturn: true,
        });

        expect(name).toBe('AB ABC C3');
    });

    it('stays forward for an up-chain / home target when detectReturn is omitted (the auto-copy path)', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature(),
            currentSystem: { alias: 'AB' },
            connectionTarget: { alias: 'HOME', occupier_alias: null, solarsystem: { class: '3', name: 'J1' } },
            aliases: [],
            formats: { ...ALPHABETICAL_FORMATS, bookmark_format_return: '*{alias} {sig} {class}', bookmark_ignored_alias: 'HOME' },
        });

        expect(name).toBe('HOME ABC C3');
    });

    it('stays forward for an up-chain target when detectReturn is explicitly false', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature(),
            currentSystem: { alias: 'AB' },
            connectionTarget: { alias: 'A', occupier_alias: null, solarsystem: { class: '3', name: 'J1' } },
            aliases: [],
            formats: { ...ALPHABETICAL_FORMATS, bookmark_format_return: '*{alias} {sig} {class}' },
            detectReturn: false,
        });

        expect(name).toBe('A ABC C3');
    });
});

describe("Devil's Rage scheme ({_} spaces and {here})", () => {
    const formats: TBookmarkFormats = {
        bookmark_format_wormhole: '{_}{alias}',
        bookmark_format_kspace: '{_}{alias}',
        bookmark_format_return: '{_}{_}*{_}{here}',
        bookmark_ignored_alias: 'Daisy',
    };
    const context = { signatureId: 'ABC-123' };
    const c5 = (alias: string | null) => ({ alias, solarsystem: { class: '5' as const, name: 'J145735' } });
    const c6 = (alias: string | null) => ({ alias, solarsystem: { class: '6' as const, name: 'J100001' } });

    it('keeps the leading space on an outbound bookmark', () => {
        expect(formatBookmarkName(c6('1'), context, formats, 'Daisy')).toBe(' 1');
        expect(formatBookmarkName(c6('12'), context, formats, '1')).toBe(' 12');
    });

    it('names the way home after the system you stand in', () => {
        expect(formatBookmarkName(c5('Daisy'), context, formats, '1')).toBe('  * 1');
        expect(formatBookmarkName(c6('1'), context, formats, '12')).toBe('  * 12');
        expect(formatBookmarkName(c6('12'), context, formats, '122')).toBe('  * 122');
    });

    it('suggests the next outbound bookmark for an unconnected signature', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature(),
            currentSystem: { alias: '12', class: '6' },
            aliases: ['Daisy', '1', '12', '121'],
            formats,
        });
        expect(name).toBe(' 122');
    });

    it('suggests "  Bravo" for the first non-static hole found in home', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature(),
            currentSystem: { alias: 'Daisy', class: '5' },
            aliases: ['Daisy'],
            formats,
        });
        expect(name).toBe('  Bravo');
    });

    it('gives the return name for a connected signature leading back up-chain', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature(),
            currentSystem: { alias: '12', class: '6' },
            connectionTarget: c6('1'),
            aliases: ['Daisy', '1', '12'],
            formats,
            detectReturn: true,
        });
        expect(name).toBe('  * 12');
    });

    it('drops to nothing when every real token is empty', () => {
        expect(formatBookmarkName(c6(null), {}, { bookmark_format_wormhole: '{_}{alias}' })).toBe('');
    });

    it('leaves the stock templates unchanged', () => {
        expect(formatBookmarkName(c6('12'), context, {})).toBe('12 ABC C6');
    });
});

describe("Devil's Rage full scheme (sig and class)", () => {
    const formats: TBookmarkFormats = {
        bookmark_format_wormhole: '{_}{alias} {sig} {class}',
        bookmark_format_kspace: '{_}{alias} {sig} {class}',
        bookmark_format_return: '{_}{_}*{_}{here} {sig} {hereclass}',
        bookmark_ignored_alias: 'Daisy',
    };
    const daisy = { alias: 'Daisy', solarsystem: { class: '5' as const, name: 'J145735' } };
    const one = { alias: '1', solarsystem: { class: '6' as const, name: 'J100001' } };

    it('outbound from Daisy: " 1 SOF C6"', () => {
        expect(formatBookmarkName(one, { signatureId: 'SOF-123' }, formats, 'Daisy', 'Daisy', '5')).toBe(' 1 SOF C6');
    });

    it('return from 1 to Daisy: "  * 1 KXR C6"', () => {
        expect(formatBookmarkName(daisy, { signatureId: 'KXR-456' }, formats, '1', '1', '6')).toBe('  * 1 KXR C6');
    });

    it('return signature row in 1 (connected, detectReturn)', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_id: 'KXR-456' }),
            currentSystem: { alias: '1', class: '6' },
            connectionTarget: daisy,
            aliases: ['Daisy', '1'],
            formats,
            detectReturn: true,
        });
        expect(name).toBe('  * 1 KXR C6');
    });

    it('unscanned outbound signature in Daisy, not yet marked static: "  Bravo SOF C6"', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_id: 'SOF-123', signature_type: { target_class: '6' } }),
            currentSystem: { alias: 'Daisy', class: '5' },
            aliases: ['Daisy'],
            formats,
        });
        expect(name).toBe('  Bravo SOF C6');
    });

    it('jump copy before the return signature is scanned leaves the sig out', () => {
        expect(formatBookmarkName(daisy, { signatureId: null }, formats, '1', '1', '6')).toBe('  * 1 C6');
    });
});

describe('planned alias and visible names', () => {
    const formats: TBookmarkFormats = { bookmark_format_wormhole: '{_}{alias} {sig} {class}', bookmark_ignored_alias: 'Daisy' };

    it('uses the planned alias for an unconnected signature', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_id: 'LNS-434' }),
            currentSystem: { alias: 'Daisy', class: '5' },
            aliases: ['Daisy'],
            formats,
            plannedAlias: '2',
        });
        expect(name).toBe(' 2 LNS');
    });

    it('shows leading spaces as dots for display only', () => {
        expect(visibleBookmarkName(' 1 SOF C6')).toBe('·1 SOF C6');
        expect(visibleBookmarkName('  * 1 KXR C6')).toBe('··* 1 KXR C6');
        expect(visibleBookmarkName('A ABC C3')).toBe('A ABC C3');
    });
});

describe('class suffix: static s, wandering w, K162 k', () => {
    const formats: TBookmarkFormats = { bookmark_format_wormhole: '{_}{alias} {sig} {class} {mass} {life}', bookmark_ignored_alias: 'Daisy' };

    it('static: " 1 SOF C6s"', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_id: 'SOF-078', signature_type: { target_class: '6' }, wormhole: { name: 'V753' }, is_static: true }),
            currentSystem: { alias: 'Daisy', class: '5' },
            aliases: ['Daisy'],
            formats,
            plannedAlias: '1',
        });
        expect(name).toBe(' 1 SOF C6s');
    });

    it('wandering: " 2 ABC C6w reduced"', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_id: 'ABC-123', signature_type: { target_class: '6' }, wormhole: { name: 'V753' }, is_wandering: true, mass_status: 'reduced' }),
            currentSystem: { alias: 'Daisy', class: '5' },
            aliases: ['Daisy'],
            formats,
            plannedAlias: '2',
        });
        expect(name).toBe(' 2 ABC C6w reduced');
    });

    it('K162: " 12 DFD C5k", even if someone ticked Static by mistake', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_id: 'DFD-876', signature_type: { target_class: '5' }, wormhole: { name: 'K162' }, is_static: true }),
            currentSystem: { alias: '1', class: '6' },
            aliases: ['Daisy', '1'],
            formats,
            plannedAlias: '12',
        });
        expect(name).toBe(' 12 DFD C5k');
    });

    it('anything else stays plain: " 13 UAZ NS"', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_id: 'UAZ-576', signature_type: { target_class: 'n' }, wormhole: { name: 'C248' } }),
            currentSystem: { alias: '1', class: '6' },
            aliases: ['Daisy', '1'],
            formats: { ...formats, bookmark_format_kspace: '{_}{alias} {sig} {class}' },
            plannedAlias: '13',
        });
        expect(name).toBe(' 13 UAZ NS');
    });

    it('connected static hole keeps its suffix: " 1 SOF C6s"', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_id: 'SOF-078', wormhole: { name: 'V753' }, is_static: true }),
            currentSystem: { alias: 'Daisy', class: '5' },
            connectionTarget: { alias: '1', solarsystem: { class: '6' as const, name: 'J111918' } },
            aliases: ['Daisy', '1'],
            formats,
            detectReturn: true,
        });
        expect(name).toBe(' 1 SOF C6s');
    });

    it('return bookmarks are unchanged: "  * 1 JOW C6"', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_id: 'JOW-849', wormhole: { name: 'K162' } }),
            currentSystem: { alias: '1', class: '6' },
            connectionTarget: { alias: 'Daisy', solarsystem: { class: '5' as const, name: 'J145735' } },
            aliases: ['Daisy', '1'],
            formats: { ...formats, bookmark_format_return: '{_}{_}*{_}{here} {sig} {hereclass}' },
            detectReturn: true,
        });
        expect(name).toBe('  * 1 JOW C6');
    });
});

describe('patch 9: Daisy callsigns and static 0', () => {
    const formats: TBookmarkFormats = {
        bookmark_format_wormhole: '{_}{alias} {sig} {class} {mass} {life}',
        bookmark_format_kspace: '{_}{alias} {sig} {class} {mass} {life}',
        bookmark_format_return: '{_}{_}*{_}{here} {sig} {hereclass}',
        bookmark_ignored_alias: 'Daisy',
    };
    const daisy = { alias: 'Daisy', solarsystem: { class: '5' as const, name: 'J145735' } };
    const alpha = { alias: 'A', solarsystem: { class: '6' as const, name: 'J111918' } };

    it("Daisy's static: \"  Alpha ZGB C6s\"", () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_id: 'ZGB-111', signature_type: { target_class: '6' }, wormhole: { name: 'V753' }, is_static: true }),
            currentSystem: { alias: 'Daisy', class: '5' },
            aliases: ['Daisy'],
            formats,
            plannedAlias: 'A',
        });
        expect(name).toBe('  Alpha ZGB C6s');
    });

    it("Daisy's second hole: \"  Bravo KXR C3\"", () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_id: 'KXR-222', signature_type: { target_class: '3' } }),
            currentSystem: { alias: 'Daisy', class: '5' },
            aliases: ['Daisy', 'A'],
            formats,
            plannedAlias: 'B',
        });
        expect(name).toBe('  Bravo KXR C3');
    });

    it('connected Daisy static keeps the callsign: "  Alpha ZGB C6s"', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_id: 'ZGB-111', wormhole: { name: 'V753' }, is_static: true }),
            currentSystem: { alias: 'Daisy', class: '5' },
            connectionTarget: alpha,
            aliases: ['Daisy', 'A'],
            formats,
            detectReturn: true,
        });
        expect(name).toBe('  Alpha ZGB C6s');
    });

    it('return directly into Daisy uses the name: "  * Alpha JOW C6"', () => {
        expect(formatBookmarkName(daisy, { signatureId: 'JOW-849' }, formats, 'A', 'A', '6')).toBe('  * Alpha JOW C6');
    });

    it('in system A: static "A0", others "A1", returns use the plain alias', () => {
        const staticInA = buildSignatureBookmark({
            signature: baseSignature({ signature_id: 'OPP-952', signature_type: { target_class: '3' }, is_static: true }),
            currentSystem: { alias: 'A', class: '6' },
            aliases: ['Daisy', 'A'],
            formats,
            plannedAlias: 'A0',
        });
        expect(staticInA).toBe(' A0 OPP C3s');

        const returnIntoA = formatBookmarkName(alpha, { signatureId: 'QRS-123' }, formats, 'A1', 'A1', '4');
        expect(returnIntoA).toBe('  * A1 QRS C4');
    });

    it('numbers outside the home chain are never callsigns: " 1 ABC C3"', () => {
        const name = buildSignatureBookmark({
            signature: baseSignature({ signature_type: { target_class: '3' } }),
            currentSystem: { alias: null, class: '6' },
            aliases: [],
            formats,
            plannedAlias: '1',
        });
        expect(name).toBe(' 1 ABC C3');
    });
});
