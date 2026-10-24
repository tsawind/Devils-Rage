import { isK162Drifter, isK162Frigate, k162Classes, k162Hint, k162Order, offersK162 } from '@/lib/k162';
import { describe, expect, it } from 'vitest';

/** Patch 54: a K162 from a Drifter system, offered everywhere and listed last among the K162s. */
describe('patch 54: K162 Drifter', () => {
    const drifter = { id: 905, name: 'K162 - Drifter', signature: 'K162', target_class: 'unknown', extra: 'drifter' };
    const frigate = { id: 904, name: 'K162 - Frigate', signature: 'K162', target_class: 'unknown', extra: 'frigate' };
    const unknown = { id: 157, name: 'K162 - Unknown', signature: 'K162', target_class: 'unknown', extra: null };
    const c6 = { id: 163, name: 'K162 - C6', signature: 'K162', target_class: '6', extra: null };

    it('is a drifter K162, not a frigate one, with no class', () => {
        expect(isK162Drifter(drifter)).toBe(true);
        expect(isK162Frigate(drifter)).toBe(false);
        expect(isK162Drifter(frigate)).toBe(false);
        expect(k162Classes(drifter)).toEqual([]);
    });

    it('is offered in every class and in known space', () => {
        for (const here of ['1', '3', '6', 'h', null]) expect(offersK162(drifter, here)).toBe(true);
        expect(k162Hint(drifter, '1')).toBe('Drifter systems');
    });

    it('comes last among the K162s, after the frigate one', () => {
        const sorted = [drifter, c6, frigate, unknown].toSorted((a, b) => k162Order(a) - k162Order(b));
        expect(sorted.map((type) => type.id)).toEqual([157, 163, 904, 905]);
    });
});
