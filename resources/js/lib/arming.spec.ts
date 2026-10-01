import { armAsOptions, armedSummary, jumpMatchesArm, matchesQuickKey, myArmedHole, nextQuickKey, pickGridHole, typeSearchMatches } from '@/lib/arming';
import { describe, expect, it } from 'vitest';

describe('patch 13: arming', () => {
    it('a full paste arms the one wormhole you sit on', () => {
        expect(
            pickGridHole([
                { id: 1, meters: 2_400, isWormhole: true, linked: false },
                { id: 2, meters: 1.5e12, isWormhole: true, linked: false },
                { id: 3, meters: 800, isWormhole: false, linked: false },
            ]),
        ).toEqual({ mode: 'one', id: 1 });
    });

    it('never arms the way back, and asks when two holes are on grid', () => {
        expect(pickGridHole([{ id: 1, meters: 1_000, isWormhole: true, linked: true }])).toEqual({ mode: 'none' });
        expect(
            pickGridHole([
                { id: 1, meters: 1_000, isWormhole: true, linked: false },
                { id: 2, meters: 9_000, isWormhole: true, linked: false },
            ]),
        ).toEqual({ mode: 'many', count: 2 });
    });

    it('Arm as: free numbers, swaps with someone else, jumped numbers taken', () => {
        const options = armAsOptions({
            parentAlias: '1',
            usedBySystems: ['11'],
            signatures: [
                { id: 10, signature_id: 'LIH-123', alias: '12', armed_by_user_id: 7, armed_by_name: 'Kyle' },
                { id: 11, signature_id: 'QXP-456', alias: '13' },
                { id: 12, signature_id: 'MVD-789', alias: '14', armed_by_user_id: 5 },
            ],
            signatureId: 12,
            userId: 5,
        });
        expect(options.slice(0, 5).map((option) => [option.alias, option.state])).toEqual([
            ['11', 'taken'],
            ['12', 'swap'],
            ['13', 'taken'],
            ['14', 'mine'],
            ['15', 'free'],
        ]);
        expect(options[1].holder).toBe('Kyle');
    });

    it('lists the armed holes by number', () => {
        const signatures = [
            { id: 2, signature_id: 'QXP-456', alias: '2', armed_by_user_id: 7, armed_by_name: 'Kyle' },
            { id: 1, signature_id: 'LIH-123', alias: '1', armed_by_user_id: 5, armed_by_name: 'Nate' },
            { id: 3, signature_id: 'MVD-789', alias: '3' },
        ];
        expect(armedSummary(signatures, 5)).toBe('1 LIH (you) · 2 QXP (Kyle)');
        expect(myArmedHole(signatures, 5)?.id).toBe(1);
        expect(myArmedHole(signatures, 9)).toBeNull();
    });

    it('spots a jump through another hole than the armed one', () => {
        expect(jumpMatchesArm({ armedTargetClass: '2', destinationClass: '2', connectedByOtherHole: false })).toBe(true);
        expect(jumpMatchesArm({ armedTargetClass: '2', destinationClass: '5', connectedByOtherHole: false })).toBe(false);
        expect(jumpMatchesArm({ armedTargetClass: null, destinationClass: '5', connectedByOtherHole: false })).toBe(true);
        expect(jumpMatchesArm({ armedTargetClass: '5', destinationClass: '5', connectedByOtherHole: true })).toBe(false);
    });
});

describe('patch 13: quick keys in the Type lists', () => {
    it('filters by destination class, k-space and frigate holes', () => {
        expect(matchesQuickKey('5', { signature: 'H296', target_class: '5' })).toBe(true);
        expect(matchesQuickKey('5', { signature: 'V911', target_class: '6' })).toBe(false);
        expect(matchesQuickKey('h', { signature: 'B274', target_class: 'h' })).toBe(true);
        expect(matchesQuickKey('f', { signature: 'E004', target_class: '1' })).toBe(true);
        expect(matchesQuickKey('f', { signature: 'H296', target_class: '5' })).toBe(false);
        expect(matchesQuickKey(null, { signature: 'H296', target_class: '5' })).toBe(true);
    });

    it('the same key again or Backspace clears the filter', () => {
        expect(nextQuickKey(null, '5')).toBe('5');
        expect(nextQuickKey('5', '5')).toBeNull();
        expect(nextQuickKey('5', 'h')).toBe('h');
        expect(nextQuickKey('5', 'Backspace')).toBeNull();
        expect(nextQuickKey(null, 'x')).toBeUndefined();
    });
});

describe('patch 14: Type list search', () => {
    const F135 = { name: 'F135 - C12 Thera', signature: 'F135', target_class: '12' };
    const C140 = { name: 'C140 - L Lowsec', signature: 'C140', target_class: 'l' };
    const E004 = { name: 'E004 - C1', signature: 'E004', target_class: '1' };
    const H296 = { name: 'H296 - C5', signature: 'H296', target_class: '5' };

    it('one key matches names and its class', () => {
        // "f": every name with an F, plus frigate holes.
        expect(typeSearchMatches('f', F135)).toBe(true);
        expect(typeSearchMatches('f', E004)).toBe(true);
        expect(typeSearchMatches('f', H296)).toBe(false);
        // "1": every name with a 1, plus holes to C1.
        expect(typeSearchMatches('1', C140)).toBe(true);
        expect(typeSearchMatches('1', F135)).toBe(true);
        expect(typeSearchMatches('1', H296)).toBe(false);
    });

    it('more keys search names only', () => {
        expect(typeSearchMatches('F1', F135)).toBe(true);
        expect(typeSearchMatches('F1', E004)).toBe(false);
        expect(typeSearchMatches('', H296)).toBe(true);
        expect(typeSearchMatches('thera', F135)).toBe(true);
    });
});
