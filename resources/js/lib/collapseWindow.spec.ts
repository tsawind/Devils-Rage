import { collapseIn, collapseText, collapseWindow } from '@/lib/collapseWindow';
import { describe, expect, it } from 'vitest';

const now = Date.parse('2026-10-11T14:00:00Z');
const at = (time: string) => `2026-10-11T${time}:00Z`;

describe('patch 37: when an end-of-life hole collapses', () => {
    it('EOL: 55 minutes after the newest look, 4 hours after the first mark', () => {
        const window = collapseWindow({ lifetime: 'eol', eolSince: at('12:00'), lastLookAt: at('13:55'), now })!;
        expect(collapseText(window)).toBe('collapsing 14:50–16:00');
        expect(collapseIn(window, now)).toBe('50m–2h');
        expect(window.scout).toBe(false);
    });

    it('EOL: "scout it" in the last 10 minutes before the earliest time', () => {
        const window = collapseWindow({ lifetime: 'eol', eolSince: at('12:00'), lastLookAt: at('13:10'), now })!;
        expect(collapseText(window)).toBe('collapsing 14:05–16:00');
        expect(window.scout).toBe(true);
    });

    it('critical: could go now, at most an hour after the first critical mark', () => {
        const window = collapseWindow({ lifetime: 'critical', eolSince: at('12:00'), criticalSince: at('13:30'), lastLookAt: at('13:55'), now })!;
        expect(collapseText(window)).toBe('collapsing now–14:30');
        expect(window.scout).toBe(false);
        expect(collapseWindow({ lifetime: 'critical', eolSince: at('12:00'), criticalSince: at('13:05'), now })!.scout).toBe(true);
    });

    it('past the latest time: probably gone', () => {
        const window = collapseWindow({ lifetime: 'eol', eolSince: at('09:00'), now })!;
        expect(collapseText(window)).toBe('probably gone (since 13:00)');
        expect(window.scout).toBe(true);
    });

    it('nothing for a healthy hole or one with no mark time', () => {
        expect(collapseWindow({ lifetime: 'healthy', eolSince: at('12:00'), now })).toBeNull();
        expect(collapseWindow({ lifetime: 'eol', eolSince: null, now })).toBeNull();
    });
});
