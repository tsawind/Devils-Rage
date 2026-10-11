import { k162Order } from '@/lib/k162';
import { startsWithFirst, typeSections } from '@/lib/typeOrdering';
import { describe, expect, it } from 'vitest';

const here = [
    { id: 1, signature: 'V911', target_class: '5', extra: null },
    { id: 2, signature: 'K162', target_class: 'unknown', extra: null },
    { id: 3, signature: 'K162', target_class: '1', extra: null },
    { id: 4, signature: 'K162', target_class: 'unknown', extra: 'frigate' },
    { id: 5, signature: 'C248', target_class: 'n', extra: null },
    { id: 6, signature: 'G024', target_class: '2', extra: null },
];
const elsewhere = [{ id: 7, signature: 'C125', target_class: '2', extra: null }];

const sections = (query: string) =>
    typeSections({ here, elsewhere, staticNames: ['V911'], standingClass: '5', query, counts: new Map(), offers: () => true, matches: () => true });

describe('patch 37: Type lists', () => {
    it('puts "K162 ?" (class unknown) first among the K162s, frigate last', () => {
        const order = here.filter((type) => type.signature === 'K162').toSorted((a, b) => k162Order(a) - k162Order(b)).map((type) => type.id);
        expect(order).toEqual([2, 3, 4]);
    });

    it('lists names starting with the search first, the ones that spawn here before the rest', () => {
        const list = sections('C2');
        expect(list[0].key).toBe('starts');
        expect(list[0].items.map((type) => type.signature)).toEqual(['C248']);
        expect(sections('C')[0].items.map((type) => type.signature)).toEqual(['C248', 'C125']);
    });

    it('keeps statics, then K162, with no search', () => {
        expect(sections('').map((section) => section.key).slice(0, 2)).toEqual(['statics', 'k162']);
        expect(startsWithFirst('', here)).toEqual([]);
    });
});

describe('patch 37: frigate holes are not rare', () => {
    it('lists A009 with the other holes and keeps Thera / drifters rare', async () => {
        const { isRareType } = await import('@/lib/typeOrdering');
        expect(isRareType({ id: 1, signature: 'A009', target_class: '13', extra: null })).toBe(false);
        expect(isRareType({ id: 2, signature: 'Q063', target_class: 'h', extra: 'Thera' })).toBe(true);
    });
});
