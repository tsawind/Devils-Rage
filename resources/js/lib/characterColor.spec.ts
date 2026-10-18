import { CHARACTER_COLORS, characterColor, rememberColor, tabsThatFit } from '@/lib/characterColor';
import { describe, expect, it } from 'vitest';

describe('patch 41: character colours and tab room', () => {
    it('uses the picked colour, else one by id', () => {
        expect(characterColor({ id: 3, map_color: '#AABBCC' })).toBe('#aabbcc');
        expect(characterColor({ id: 3, map_color: null })).toBe(CHARACTER_COLORS[3]);
        expect(characterColor({ id: 13 })).toBe(CHARACTER_COLORS[3]);
        expect(characterColor({ id: 3, map_color: 'red' })).toBe(CHARACTER_COLORS[3]);
    });

    it('shows a new pick right away', () => {
        rememberColor(7, '#123456');
        expect(characterColor({ id: 7, map_color: null })).toBe('#123456');
    });

    it('fits as many tabs as there is room for, keeping room for "+N"', () => {
        expect(tabsThatFit(3, 400, 130)).toBe(3);
        expect(tabsThatFit(10, 400, 130)).toBe(2);
        expect(tabsThatFit(10, 60, 130)).toBe(1);
        expect(tabsThatFit(0, 400, 130)).toBe(0);
    });
});
