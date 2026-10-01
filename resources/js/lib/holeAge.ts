/**
 * Patch 16: how old a hole is ("seen 14 h ago") and whether it is likely end
 * of life by now: within 4 hours of its type's lifetime, measured from when
 * it was first seen. Nobody's EOL / healthy check after that point counts as
 * a look at it, so the faint mark goes away.
 */

/** EVE calls a hole end of life with less than this left. */
export const EOL_WINDOW_SECONDS = 4 * 3600;

export type THoleAge = {
    /** Seconds since the hole was first seen. */
    ageSeconds: number;
    /** "seen 14 h ago", "seen 25 min ago". */
    label: string;
    /** Near the end of its type's lifetime and nobody checked since. */
    likelyEol: boolean;
};

export function formatSeenAgo(ageSeconds: number): string {
    const minutes = Math.max(0, Math.floor(ageSeconds / 60));
    if (minutes < 60) return `seen ${minutes} min ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 48) return `seen ${hours} h ago`;
    return `seen ${Math.floor(hours / 24)} d ago`;
}

export function holeAge(params: {
    /** When the connection and its signatures were created: the earliest counts. */
    seen: readonly (string | null | undefined)[];
    now: number;
    /** The hole type's lifetime in seconds (unknown: no EOL guess). */
    maximumLifetime?: number | null;
    lifetimeStatus?: string | null;
    lifetimeUpdatedAt?: string | null;
}): THoleAge | null {
    const times = params.seen.map((value) => (value ? Date.parse(value) : Number.NaN)).filter((value) => Number.isFinite(value));
    if (times.length === 0) return null;
    const first = Math.min(...times);
    const ageSeconds = Math.max(0, (params.now - first) / 1000);
    const lifetime = params.maximumLifetime ?? 0;
    let likelyEol = false;
    if (lifetime > EOL_WINDOW_SECONDS && (params.lifetimeStatus ?? 'healthy') === 'healthy') {
        const likelyFrom = first + (lifetime - EOL_WINDOW_SECONDS) * 1000;
        const checked = params.lifetimeUpdatedAt ? Date.parse(params.lifetimeUpdatedAt) : Number.NaN;
        likelyEol = params.now >= likelyFrom && !(Number.isFinite(checked) && checked >= likelyFrom);
    }
    return { ageSeconds, label: formatSeenAgo(ageSeconds), likelyEol };
}
