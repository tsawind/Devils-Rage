import { couldBeNames, holeCandidates } from '@/map/holeGuess';
import { describe, expect, it } from 'vitest';

const names = (list: { name: string }[]) => list.map((type) => type.name.toUpperCase());

describe('patch 37: Could be for K162s', () => {
    it('a K162 frigate in a C5 from a C5 can only be a C008', () => {
        expect(names(holeCandidates({ k162Class: '5', spawnClasses: ['5'], frigate: true }))).toEqual(['C008']);
    });

    it('a plain K162 in a C5 from a C5 lists the big holes into a C5, no frigate holes', () => {
        const list = names(holeCandidates({ k162Class: '5', spawnClasses: ['5'] }));
        expect(list).toContain('H296');
        expect(list).not.toContain('C008');
        expect(list).not.toContain('K162');
    });

    it('a K162 whose far side is not known yet lists every hole into its class', () => {
        const list = names(holeCandidates({ k162Class: '5', spawnClasses: [] }));
        expect(list).toContain('H296');
        expect(list.length).toBeGreaterThan(1);
    });

    it('caps the line at eight entries', () => {
        const many = Array.from({ length: 12 }, (_, index) => ({ name: `X${index}`, target_class: '5', total: 1, maxJump: 1 }));
        expect(couldBeNames(many)).toHaveLength(8);
        expect(couldBeNames(many).at(-1)).toBe('+5 more');
    });
});
