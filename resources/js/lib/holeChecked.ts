/**
 * Patch 37: a jumped hole nobody has looked at for a while. Anything that shows
 * someone saw it in game resets the clock: a type, mass or life status picked
 * (even the same one again), ✓ Checked in game, or a jump logged (tracked or
 * added by hand). A paste doesn't count.
 */
export const UNCHECKED_AFTER_MS = 4 * 60 * 60 * 1000;

type TCheckFacts = {
    created_at?: string | null;
    lifetime_status_updated_at?: string | null;
    mass_status_updated_at?: string | null;
    checked_at?: string | null;
    jumps?: readonly { created_at: string }[] | null;
};

/** When the hole was last checked, jumped or changed (ms), or null when nothing is known. */
export function lastCheckedAt(connection: TCheckFacts): number | null {
    const times = [connection.created_at, connection.lifetime_status_updated_at, connection.mass_status_updated_at, connection.checked_at, ...(connection.jumps ?? []).map((jump) => jump.created_at)]
        .map((value) => (value ? Date.parse(value) : NaN))
        .filter((value) => Number.isFinite(value));
    return times.length ? Math.max(...times) : null;
}

/** "4 h 12 min" since the last check once it is 4 h or more, else null. */
export function uncheckedFor(connection: TCheckFacts, now: number): string | null {
    const last = lastCheckedAt(connection);
    if (last === null) return null;
    const elapsed = now - last;
    if (elapsed < UNCHECKED_AFTER_MS) return null;
    const minutes = Math.floor(elapsed / 60_000);
    return `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}
