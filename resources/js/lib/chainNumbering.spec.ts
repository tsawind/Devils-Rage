import { autoFlagsForType, connectionFlag, validateManualAlias } from '@/lib/chainNumbering';
import { describe, expect, it } from 'vitest';

describe('connectionFlag', () => {
    it('marks K162 as k, static as s, wandering as w', () => {
        expect(connectionFlag({ wormholeName: 'K162' })).toBe('k');
        expect(connectionFlag({ wormholeName: 'K162', is_static: true })).toBe('k');
        expect(connectionFlag({ wormholeName: 'V753', is_static: true })).toBe('s');
        expect(connectionFlag({ wormholeName: 'V753', is_wandering: true })).toBe('w');
        expect(connectionFlag({ wormholeName: 'C248' })).toBe('');
    });
});

describe('autoFlagsForType', () => {
    const daisyStatics = ['V753'];

    it('ticks Static for the first V753 in Daisy', () => {
        expect(autoFlagsForType({ wormholeName: 'V753', staticNames: daisyStatics, otherStaticExists: false })).toEqual({ is_static: true, is_wandering: false });
    });

    it('ticks Wandering for a second V753 in Daisy', () => {
        expect(autoFlagsForType({ wormholeName: 'V753', staticNames: daisyStatics, otherStaticExists: true })).toEqual({ is_static: false, is_wandering: true });
    });

    it('clears both for other types and K162s', () => {
        expect(autoFlagsForType({ wormholeName: 'C248', staticNames: daisyStatics, otherStaticExists: false })).toEqual({ is_static: false, is_wandering: false });
        expect(autoFlagsForType({ wormholeName: 'K162', staticNames: ['K162'], otherStaticExists: false })).toEqual({ is_static: false, is_wandering: false });
    });
});

describe('validateManualAlias', () => {
    it('accepts a free number and refuses one already used', () => {
        const used = new Map([['12', 'UAZ-576']]);
        expect(validateManualAlias('14', used)).toEqual({ ok: true, alias: '14' });
        expect(validateManualAlias('12', used)).toEqual({ ok: false, error: 'Number 12 is already used by UAZ-576.' });
        expect(validateManualAlias(null, used).ok).toBe(false);
    });
});
