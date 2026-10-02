/**
 * How much mass a wormhole has left (patch 12): drawn as the thickness of its
 * connection on the map.
 *
 * A new hole has its type's total mass ±10% (a D845: 4.5–5.5 B kg). Logged
 * jumps (both directions) come off that, and the status a scanner sets narrows
 * it: fresh is at least 50% of the hole, reduced is under 50% (at least 10%),
 * critical is under 10%. Only jumps the mapper logged count (tracked pilots,
 * base ship mass), which is why the status keeps the estimate honest.
 */

export type TMassEstimate = {
    /** The most a new hole of this type can hold (total +10%): the pipe's outline. */
    capacity: number;
    /** At least this much is left. */
    min: number;
    /** At most this much is left. */
    max: number;
};

/** The biggest hole in game is 5.5 B kg (+10%): that draws at the full width. */
export const PIPE_FULL_MASS = 5_500_000_000;
/** Patch 13: a straight scale, 2 px + 30 px × share of the biggest hole (a new D845 is 32 px, 1 B about 8). */
export const PIPE_FULL_WIDTH = 32;
export const PIPE_MIN_WIDTH = 2;
/** Holes that only let frigates through (max jump 5 M kg) always draw thin. */
export const FRIGATE_JUMP_MASS = 5_000_000;

/** Whether a hole type only lets frigates through. */
export function isFrigateHole(maximumJumpMass: number | null | undefined): boolean {
    return (maximumJumpMass ?? 0) > 0 && (maximumJumpMass ?? 0) <= FRIGATE_JUMP_MASS;
}

export function estimateMass(params: { totalMass: number | null | undefined; jumped: number | null | undefined; status: string | null | undefined }): TMassEstimate | null {
    const total = params.totalMass ?? 0;
    if (!(total > 0)) return null;

    const low = total * 0.9;
    const high = total * 1.1;
    const jumped = Math.max(0, params.jumped ?? 0);

    // Logged jumps are only part of what went through (unlogged pilots, prop
    // mods): they cap what can be left, but only bound the low end while they
    // agree with the status.
    const fromJumps = low - jumped;
    let max = high - jumped;
    let statusMin = 0;

    if (params.status === 'reduced') {
        max = Math.min(max, high * 0.5);
        statusMin = low * 0.1;
    } else if (params.status === 'critical') {
        max = Math.min(max, high * 0.1);
    } else {
        statusMin = Math.min(low * 0.5, fromJumps);
    }

    max = Math.max(0, max);
    const min = fromJumps <= max ? Math.max(fromJumps, statusMin) : statusMin;
    return { capacity: high, min: Math.max(0, Math.min(min, max)), max };
}

/** Pipe width for a mass: 2 px + 30 px × (mass ÷ 5.5 B), so 32 px for the biggest hole (0 for nothing). */
export function pipeWidth(mass: number): number {
    if (!(mass > 0)) return 0;
    return PIPE_MIN_WIDTH + (PIPE_FULL_WIDTH - PIPE_MIN_WIDTH) * (Math.min(mass, PIPE_FULL_MASS) / PIPE_FULL_MASS);
}

/** "1.6 B", "450 M", "0". */
export function formatMass(kg: number): string {
    if (kg >= 1_000_000_000) return `${(kg / 1_000_000_000).toLocaleString('en-US', { maximumFractionDigits: kg >= 10_000_000_000 ? 0 : 2 })} B`;
    if (kg >= 1_000_000) return `${Math.round(kg / 1_000_000).toLocaleString('en-US')} M`;
    return kg > 0 ? `${Math.round(kg).toLocaleString('en-US')}` : '0';
}

/** "about 1.6–2.6 B kg left", "about 0–550 M kg left". */
export function describeEstimate(estimate: TMassEstimate): string {
    const billions = estimate.max >= 1_000_000_000;
    const unit = billions ? 1_000_000_000 : 1_000_000;
    const value = (kg: number) => (kg / unit).toLocaleString('en-US', { maximumFractionDigits: billions ? 2 : 0 });
    return `about ${value(estimate.min)}–${value(estimate.max)} ${billions ? 'B' : 'M'} kg left`;
}

/**
 * Patch 18 (replaces patch 17's C5/C6 rule): a size to draw while a hole's
 * type isn't known. A K162 sits in the system the real hole leads into, so
 * its size is the smallest non-frigate hole type leading into that class. An
 * untyped hole could go either way: the smaller of both ends. Null when no
 * class is known. Drawn with "≈" so it reads as a guess.
 */
export type TGuessType = { name: string; target_class: string | null | undefined; total: number; maxJump: number };

export function estimateHoleMass(params: { k162Class?: string | null; classes: readonly (string | null | undefined)[]; types: readonly TGuessType[] }): number | null {
    const norm = (value: string | null | undefined) => String(value ?? '').toLowerCase().replace(/^c/, '');
    const into = (cls: string): number | null => {
        let best: number | null = null;
        for (const type of params.types) {
            if (type.name.toUpperCase().startsWith('K162') || norm(type.target_class) !== cls) continue;
            if (!(type.total > 0) || isFrigateHole(type.maxJump)) continue;
            if (best === null || type.total < best) best = type.total;
        }
        return best;
    };
    const targets = params.k162Class ? [norm(params.k162Class)] : params.classes.map(norm);
    const sizes = targets.filter((cls) => cls && cls !== 'unknown').map(into).filter((size): size is number => size !== null);
    return sizes.length ? Math.min(...sizes) : null;
}
