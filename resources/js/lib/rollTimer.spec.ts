import { formatCountdown, ROLL_DUE_SHOWN_SECONDS, ROLL_SCAN_AT_SECONDS, ROLL_SIG_DUE_SECONDS, rollTimer } from '@/lib/rageRoll';
import { describe, expect, it } from 'vitest';

const collapsed = '2026-10-26T19:00:00.000Z';
const at = (seconds: number) => Date.parse(collapsed) + seconds * 1000;

describe('patch 57: rage roll timer', () => {
    it('is off without a collapse', () => {
        expect(rollTimer(null, at(0))).toBeNull();
        expect(rollTimer({ collapsed_at: null, collapsed_by: null }, at(0))).toBeNull();
        expect(rollTimer({ collapsed_at: 'nonsense', collapsed_by: null }, at(0))).toBeNull();
    });

    it('counts down to the sig at 59 s with the scan moment at 54 s', () => {
        expect(ROLL_SIG_DUE_SECONDS).toBe(59);
        expect(ROLL_SCAN_AT_SECONDS).toBe(54);
        const timer = rollTimer({ collapsed_at: collapsed, collapsed_by: 'LuigiSquirrel' }, at(18));
        expect(timer).toMatchObject({ phase: 'counting', toDue: 41, toScan: 36, by: 'LuigiSquirrel' });
    });

    it('says the sig is due after 59 s, then goes away', () => {
        expect(rollTimer({ collapsed_at: collapsed }, at(60))).toMatchObject({ phase: 'due', overdue: 1 });
        expect(rollTimer({ collapsed_at: collapsed }, at(ROLL_SIG_DUE_SECONDS + ROLL_DUE_SHOWN_SECONDS + 1))).toBeNull();
    });

    it('treats a clock slightly behind the server as just collapsed', () => {
        expect(rollTimer({ collapsed_at: collapsed }, at(-2))).toMatchObject({ phase: 'counting', elapsed: 0 });
    });

    it('formats the countdown rounded up', () => {
        expect(formatCountdown(41)).toBe('0:41');
        expect(formatCountdown(0.2)).toBe('0:01');
        expect(formatCountdown(0)).toBe('0:00');
        expect(formatCountdown(65)).toBe('1:05');
    });
});
