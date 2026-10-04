import { badgeLines, badgeSize, type EdgeIndicator } from '@/map/components/edges/badgeWidth';
import { describe, expect, it } from 'vitest';

const ink = { fill: 'x', stroke: 'x' };

describe('patch 21: stacked pills', () => {
    const parts: EdgeIndicator[] = [
        { type: 'eol', label: 'EOL', ...ink },
        { type: 'static', label: 'Static', ...ink },
        { type: 'text', role: 'type', label: 'V911', ...ink },
        { type: 'text', label: 'XL', arrow: 'right', ...ink },
        { type: 'weight', ...ink },
    ];

    it('stacks size (with arrow and icons), then type, then Static, then EOL', () => {
        expect(badgeLines(parts).map((line) => line.map((part) => part.label ?? part.type))).toEqual([['XL', 'weight'], ['V911'], ['Static'], ['EOL']]);
    });

    it('is narrow enough to sit between two columns, and as tall as its lines', () => {
        const size = badgeSize(parts);
        expect(size.width).toBeLessThanOrEqual(60);
        expect(size.height).toBe(4 * 14 + 6);
        expect(badgeSize([{ type: 'text', label: 'L', arrow: 'left', ...ink }]).height).toBe(20);
    });

    it('no parts, no pill', () => {
        expect(badgeSize([])).toEqual({ width: 0, height: 0 });
    });
});
