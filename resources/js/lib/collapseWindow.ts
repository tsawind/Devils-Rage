import { FLEET_RULES } from '@/lib/fleetRules';

/**
 * Patch 37: when an end-of-life hole will collapse, as a window in EVE time.
 *
 * In game a hole shows "Less than 4 hours remaining" (EOL) and then "Less than 1 hour
 * remaining" (EOL!, Critical). So:
 * - Latest: 4 h after it was FIRST marked EOL, or 1 h after it was first marked Critical.
 * - Earliest: someone who saw plain EOL knows it had at least 1 h left; the newest look
 *   (an EOL mark or ✓ Checked in game) guarantees CHECK_GUARANTEE_MINUTES (1 h minus a few
 *   minutes between looking and clicking). A Critical hole guarantees nothing: "now".
 * - "scout it" only near the end: the last SCOUT_WARN_MINUTES before the earliest time
 *   (EOL) or the latest time (Critical), and once it's past.
 */
export const CHECK_GUARANTEE_MINUTES = 55;
export const SCOUT_WARN_MINUTES = 10;

export type TCollapseWindow = {
    /** Earliest collapse (ms), or null: it could go any moment ("now"). */
    earliest: number | null;
    /** Latest collapse (ms). */
    latest: number;
    /** Past the latest time: probably gone. */
    gone: boolean;
    scout: boolean;
    critical: boolean;
};

const MINUTE = 60_000;

function time(iso: string | null | undefined): number | null {
    if (!iso) return null;
    const value = Date.parse(iso);
    return Number.isFinite(value) ? value : null;
}

export function collapseWindow(params: {
    lifetime: string | null | undefined;
    eolSince: string | null | undefined;
    criticalSince?: string | null | undefined;
    /** Newest look at the hole: a life mark or ✓ Checked in game. */
    lastLookAt?: string | null | undefined;
    now: number;
}): TCollapseWindow | null {
    const { lifetime, now } = params;
    if (lifetime !== 'eol' && lifetime !== 'critical') return null;
    const eolSince = time(params.eolSince);
    const criticalSince = time(params.criticalSince);
    const lastLook = time(params.lastLookAt);
    const critical = lifetime === 'critical';

    const eolLatest = eolSince !== null ? eolSince + FLEET_RULES.eolHours * 60 * MINUTE : null;
    const criticalStart = criticalSince ?? (critical ? (lastLook ?? eolSince) : null);
    const criticalLatest = criticalStart !== null ? criticalStart + 60 * MINUTE : null;
    const candidates = critical ? [criticalLatest, eolLatest] : [eolLatest];
    const known = candidates.filter((value): value is number => value !== null);
    if (known.length === 0) return null;
    const latest = Math.min(...known);

    let earliest: number | null = null;
    if (!critical) {
        const look = Math.max(lastLook ?? 0, eolSince ?? 0);
        earliest = look > 0 ? Math.min(look + CHECK_GUARANTEE_MINUTES * MINUTE, latest) : null;
        if (earliest !== null && earliest <= now) earliest = null;
    }

    const gone = now > latest;
    const warnFrom = (critical || earliest === null ? latest : earliest) - SCOUT_WARN_MINUTES * MINUTE;
    return { earliest, latest, gone, scout: gone || now >= warnFrom, critical };
}

/** "14:52" in EVE time (UTC). */
export function eveClock(ms: number): string {
    const date = new Date(ms);
    return `${String(date.getUTCHours()).padStart(2, '0')}:${String(date.getUTCMinutes()).padStart(2, '0')}`;
}

/** "collapsing 14:52–15:32", "collapsing now–15:32", "probably gone (since 15:32)". */
export function collapseText(window: TCollapseWindow): string {
    if (window.gone) return `probably gone (since ${eveClock(window.latest)})`;
    return `collapsing ${window.earliest === null ? 'now' : eveClock(window.earliest)}–${eveClock(window.latest)}`;
}

/** "55m–3h29m" until the two times ("0–42m" for "now"). */
export function collapseIn(window: TCollapseWindow, now: number): string {
    const span = (ms: number) => {
        const minutes = Math.max(0, Math.round(ms / MINUTE));
        const hours = Math.floor(minutes / 60);
        const rest = minutes % 60;
        return hours ? `${hours}h${rest ? `${String(rest).padStart(2, '0')}m` : ''}` : `${rest}m`;
    };
    return `${window.earliest === null ? '0' : span(window.earliest - now)}–${span(window.latest - now)}`;
}
