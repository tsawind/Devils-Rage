import { collapseIn, collapseText, collapseWindow, eveClock } from '@/lib/collapseWindow';
import type { TMassEstimate, TMassHints } from '@/lib/massEstimate';

/**
 * Patch 37: the words and numbers of the pipe details box (jumped and dotted pipes
 * alike): when and by whom, how long it may live, how much mass may be left.
 */

/** "3 h 12 min ago", "12 min ago", "just now". */
export function ago(ms: number): string {
    const minutes = Math.max(0, Math.floor(ms / 60_000));
    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes} min ago`;
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    return hours < 10 && rest > 0 ? `${hours} h ${rest} min ago` : `${hours} h ago`;
}

/** EVE time (UTC) of a moment: "06:02 EVE". */
export function eveTime(iso: string): string {
    const date = new Date(iso);
    return `${String(date.getUTCHours()).padStart(2, '0')}:${String(date.getUTCMinutes()).padStart(2, '0')} EVE`;
}

/** "06:02 EVE · 3 h ago" (or null without a time). */
export function whenLine(iso: string | null | undefined, now: number): string | null {
    if (!iso) return null;
    const at = Date.parse(iso);
    if (!Number.isFinite(at)) return null;
    return `${eveTime(iso)} · ${ago(now - at)}`;
}

/**
 * How long the hole may still live:
 * - End of life / EOL!: the collapse window in EVE time (patch 37: see collapseWindow).
 * - Otherwise its type's life (or the range of the types it could be) minus its age on the map.
 */
export function lifeLine(params: {
    lifetime: string | null | undefined;
    markedAt: string | null | undefined;
    eolSince?: string | null;
    criticalSince?: string | null;
    imminentSince?: string | null;
    hours: [number, number] | null;
    addedAt: string | null | undefined;
    now: number;
}): string {
    const { lifetime, now } = params;
    // Patch 37: "Expired, closure imminent": it could close any moment.
    if (lifetime === 'imminent') {
        const window = collapseWindow({ lifetime, eolSince: params.eolSince ?? null, criticalSince: params.criticalSince ?? null, imminentSince: params.imminentSince ?? params.markedAt, now });
        return window ? `Imminent collapse · ${collapseText(window)} EVE` : 'Imminent collapse · closing now';
    }
    if (lifetime === 'eol' || lifetime === 'critical') {
        const label = lifetime === 'critical' ? 'EOL! (under 1 h)' : 'End of life (under 4 h)';
        const window = collapseWindow({
            lifetime,
            eolSince: params.eolSince ?? params.markedAt,
            criticalSince: params.criticalSince ?? (lifetime === 'critical' ? params.markedAt : null),
            lastLookAt: params.markedAt,
            now,
        });
        if (!window) return label;
        if (window.gone) return `${label} · probably gone (since ${eveClock(window.latest)} EVE)`;
        return `${label} · ${collapseText(window)} EVE (in ${collapseIn(window, now)})`;
    }
    const hours = params.hours;
    if (!hours) return 'Healthy';
    const [low, high] = hours;
    // Patch 39: a hole's clock starts when it is first warped to, which the mapper can't see, so
    // "Added" is only information: no countdown from it. The life marks and ✓ Checked drive the
    // collapse window once it shows end of life.
    return `Healthy · ${low === high ? `${low} h hole` : `${low}–${high} h hole`} · EOL shows its last ${EOL_HOURS} h`;
}

/** A hole shows end-of-life for its last 4 h. */
const EOL_HOURS = 4;


/** The mass bar: shares (0–100) of the biggest the hole can be. */
export type TMassBar = { left: number; width: number; loggedLeft: number; loggedWidth: number; tone: 'fresh' | 'reduced' | 'critical' };

export function massBar(params: { estimate: TMassEstimate; loggedSinceMark: number; status: string | null | undefined; hints: TMassHints }): TMassBar {
    const { estimate } = params;
    const share = (kg: number) => Math.max(0, Math.min(100, (kg / estimate.capacity) * 100));
    const left = share(estimate.min);
    const right = share(estimate.max);
    const logged = Math.max(0, params.loggedSinceMark);
    const loggedRight = share(estimate.max + logged);
    const tone: TMassBar['tone'] =
        params.status === 'critical' || params.hints.shouldBe === 'critical' ? 'critical' : params.status === 'reduced' || params.hints.mayBe || params.hints.shouldBe ? 'reduced' : 'fresh';
    return { left, width: Math.max(right - left, right > 0 ? 1 : 0), loggedLeft: right, loggedWidth: Math.max(0, loggedRight - right), tone };
}

/** "may be Crit now: check Show Info" / "should be Crit by now: check Show Info", or null. */
export function hintLine(hints: TMassHints): string | null {
    const word = (status: 'critical' | 'reduced') => (status === 'critical' ? 'Crit' : 'Reduced');
    if (hints.shouldBe) return `Should be ${word(hints.shouldBe)} by now: check Show Info`;
    if (hints.mayBe) return `May be ${word(hints.mayBe)} now: check Show Info`;
    return null;
}

/** The most recent look at the hole: a status pick or ✓ Checked, or a logged jump. */
export function lastLook(candidates: readonly { at: string | null | undefined; by: string | null | undefined; note: string }[]): { at: number; by: string | null; note: string } | null {
    let best: { at: number; by: string | null; note: string } | null = null;
    for (const candidate of candidates) {
        const at = candidate.at ? Date.parse(candidate.at) : NaN;
        if (!Number.isFinite(at)) continue;
        if (!best || at > best.at) best = { at, by: candidate.by ?? null, note: candidate.note };
    }
    return best;
}
