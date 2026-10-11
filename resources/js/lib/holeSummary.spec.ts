import { ago, eveTime, hintLine, lastLook, lifeLine, massBar } from '@/lib/holeSummary';
import { estimateMass } from '@/lib/massEstimate';
import { describe, expect, it } from 'vitest';

const now = Date.parse('2026-10-11T09:02:00Z');
const hour = 3_600_000;

describe('patch 37: the pipe details box', () => {
    it('says how long ago, in EVE time', () => {
        expect(ago(30_000)).toBe('just now');
        expect(ago(12 * 60_000)).toBe('12 min ago');
        expect(ago(4 * hour + 12 * 60_000)).toBe('4 h 12 min ago');
        expect(ago(13 * hour)).toBe('13 h ago');
        expect(eveTime('2026-10-11T06:02:00Z')).toBe('06:02 EVE');
    });

    it('gives the life left from the type and the age on the map', () => {
        const addedAt = new Date(now - 3 * hour).toISOString();
        expect(lifeLine({ lifetime: 'healthy', markedAt: null, hours: [24, 24], addedAt, now })).toBe('Fresh · 24 h hole · about 21 h left at most');
        expect(lifeLine({ lifetime: 'healthy', markedAt: null, hours: [16, 24], addedAt, now })).toBe('Fresh · 16–24 h hole · 13 h–21 h left at most');
    });

    it('patch 37: end of life as a collapse window in EVE time', () => {
        const eolSince = new Date(now - 1.5 * hour).toISOString();
        const checked = new Date(now - 5 * 60_000).toISOString();
        expect(lifeLine({ lifetime: 'eol', markedAt: checked, eolSince, hours: [24, 24], addedAt: null, now })).toBe('End of life (under 4 h) · collapsing 09:52–11:32 EVE (in 50m–2h30m)');
        const criticalSince = new Date(now - 18 * 60_000).toISOString();
        expect(lifeLine({ lifetime: 'critical', markedAt: criticalSince, eolSince, criticalSince, hours: null, addedAt: null, now })).toBe('EOL! (under 1 h) · collapsing now–09:44 EVE (in 0–42m)');
        const old = new Date(now - 5 * hour).toISOString();
        expect(lifeLine({ lifetime: 'eol', markedAt: old, eolSince: old, hours: null, addedAt: null, now })).toBe('End of life (under 4 h) · probably gone (since 08:02 EVE)');
    });

    it('patch 37: imminent collapse', () => {
        const imminentSince = new Date(now - 12 * 60_000).toISOString();
        expect(lifeLine({ lifetime: 'imminent', markedAt: imminentSince, imminentSince, hours: null, addedAt: null, now })).toBe('Imminent collapse · closing now (imminent since 08:50) EVE');
    });

    it('draws the range left and the jumps logged since the mark', () => {
        const estimate = estimateMass({ totalMass: 3.3e9, jumped: 400e6, jumpedSinceStatus: 400e6, status: 'reduced' })!;
        const bar = massBar({ estimate, loggedSinceMark: 400e6, status: 'reduced', hints: { shouldBe: null, mayBe: 'critical' } });
        expect(bar.left).toBe(0);
        expect(Math.round(bar.width)).toBe(39);
        expect(Math.round(bar.loggedWidth)).toBe(11);
        expect(bar.tone).toBe('reduced');
    });

    it('words the hints', () => {
        expect(hintLine({ shouldBe: null, mayBe: 'critical' })).toBe('May be Crit now: check Show Info');
        expect(hintLine({ shouldBe: 'critical', mayBe: null })).toBe('Should be Crit by now: check Show Info');
        expect(hintLine({ shouldBe: null, mayBe: null })).toBeNull();
    });

    it('finds the latest look at the hole', () => {
        const look = lastLook([
            { at: '2026-10-11T08:00:00Z', by: 'WickedWrench', note: 'marked Reduced' },
            { at: '2026-10-11T08:20:00Z', by: 'Kyle', note: 'jumped' },
            { at: null, by: 'Nobody', note: 'checked' },
        ]);
        expect(look?.by).toBe('Kyle');
        expect(look?.note).toBe('jumped');
    });
});
