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
 * How long the hole may still live, as worst cases:
 * - End of life: under 4 h from when it was marked; critical: under 1 h.
 * - Otherwise its type's life (or the range of the types it could be) minus its age on the map.
 */
export function lifeLine(params: { lifetime: string | null | undefined; markedAt: string | null | undefined; hours: [number, number] | null; addedAt: string | null | undefined; now: number }): string {
    const { lifetime, now } = params;
    const hoursSince = (iso: string | null | undefined) => (iso && Number.isFinite(Date.parse(iso)) ? (now - Date.parse(iso)) / 3_600_000 : null);
    if (lifetime === 'eol' || lifetime === 'critical') {
        const limit = lifetime === 'critical' ? 1 : 4;
        const since = hoursSince(params.markedAt);
        const label = lifetime === 'critical' ? 'EOL! (under 1 h)' : 'End of life (under 4 h)';
        if (since === null) return label;
        const left = limit - since;
        return left > 0 ? `${label} · marked ${ago(since * 3_600_000)} · under ${formatHours(left)} left` : `${label} · marked ${ago(since * 3_600_000)} · may be gone`;
    }
    const hours = params.hours;
    if (!hours) return 'Fresh';
    const age = hoursSince(params.addedAt) ?? 0;
    const [low, high] = hours;
    const type = low === high ? `${low} h hole` : `${low}–${high} h hole`;
    const leftLow = Math.max(0, low - age);
    const leftHigh = Math.max(0, high - age);
    const left = low === high ? `about ${formatHours(leftHigh)} left at most` : `${formatHours(leftLow)}–${formatHours(leftHigh)} left at most`;
    return `Fresh · ${type} · ${left}`;
}

function formatHours(hours: number): string {
    if (hours < 1) return `${Math.max(0, Math.round(hours * 60))} min`;
    return `${Math.floor(hours)} h`;
}

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
