/**
 * Patch 37: how much is left in a hole, from what people saw.
 *
 * A hole's real size is somewhere between 90% and 110% of its nominal size. Each status
 * someone saw, with the mass logged through it at that moment, narrows that down:
 * - Fresh at P: more than half was left, so the size is over 2P;
 * - Reduced at P: at most half left (size ≤ 2P) but more than 10% (size > P / 0.9);
 *   made Reduced by a jump of m: it was still fresh before it (size > 2 (P − m));
 * - Crit at P: at most 10% left (size ≤ P / 0.9), still open (size > P);
 *   made Crit by a jump of m: it was above 10% before it (size > (P − m) / 0.9).
 * What's left now = size − everything logged. This only holds when every jump through the
 * hole was logged ("only our jumps"); otherwise use the plain estimate.
 */
export type TObservation = {
    status: 'fresh' | 'reduced' | 'critical';
    /** Everything logged through the hole when it was seen. */
    logged: number;
    /** The jump that made it this status, when someone said so. */
    flip: number | null;
    flipBy?: string | null;
    by?: string | null;
    at?: string | null;
};

export type THoleWindow = {
    /** The hole's real size, smallest and biggest it can be. */
    total: [number, number];
    /** What's left now: worst case (low) and best case (high). */
    left: [number, number];
    /** Why, one line per thing someone saw that narrowed it. */
    reasons: string[];
    /** False when the log and the marks can't all be true (a jump missing, a wrong mark…). */
    consistent: boolean;
};

const m = (kg: number) => {
    const value = kg / 1_000_000;
    return `${value < 100 ? value.toFixed(1) : Math.round(value).toLocaleString('en-US')}m`;
};

export function holeWindow(params: { nominal: number; observations: readonly TObservation[]; loggedNow: number }): THoleWindow {
    let low = params.nominal * 0.9;
    let high = params.nominal * 1.1;
    const reasons: string[] = [];
    const raise = (value: number, why: string) => {
        if (value > low) {
            low = value;
            reasons.push(why);
        }
    };
    const lower = (value: number, why: string) => {
        if (value < high) {
            high = value;
            reasons.push(why);
        }
    };

    for (const seen of params.observations) {
        const p = seen.logged;
        const flipText = seen.flip ? `, made by ${seen.flipBy ? `${seen.flipBy}'s ` : 'a '}${m(seen.flip)}` : '';
        if (seen.status === 'fresh') {
            raise(2 * p, `fresh at ${m(p)} → size over ${m(2 * p)}`);
        } else if (seen.status === 'reduced') {
            lower(2 * p, `reduced at ${m(p)} → size at most ${m(2 * p)}`);
            raise(p / 0.9, `reduced at ${m(p)} → size over ${m(p / 0.9)}`);
            if (seen.flip) raise(2 * (p - seen.flip), `reduced at ${m(p)}${flipText} → size over ${m(2 * (p - seen.flip))}`);
        } else {
            lower(p / 0.9, `crit at ${m(p)} → size at most ${m(p / 0.9)}`);
            raise(p, `crit at ${m(p)} → still open, size over ${m(p)}`);
            if (seen.flip) raise((p - seen.flip) / 0.9, `crit at ${m(p)}${flipText} → size over ${m((p - seen.flip) / 0.9)}`);
        }
    }

    const consistent = low <= high;
    return {
        total: [low, high],
        left: [Math.max(0, low - params.loggedNow), Math.max(0, high - params.loggedNow)],
        reasons,
        consistent,
    };
}
