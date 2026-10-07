/**
 * How much mass a wormhole has left (patch 12): drawn as the thickness of its
 * connection on the map.
 *
 * A new hole has its type's total mass ±10% (a D845: 4.5–5.5 B kg). Patch 32:
 * the status is the band (fresh 50–100%, reduced 10–50%, critical 0–10%, each
 * widened by the ±10% roll), and jumps logged since the status was set bring the
 * top down. The bottom stays at the band's bottom: not every jump gets logged.
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
    // Patch 32: `jumped` is only what was logged since the status was last set (marking a hole
    // reduced or crit starts a new log). Unlogged pilots are always possible, so the bottom is
    // the status band's bottom; logged jumps only bring the top down.
    const jumped = Math.max(0, params.jumped ?? 0);
    const bands: Record<string, [number, number]> = { critical: [0, 0.1], reduced: [0.1, 0.5], unknown: [0, 1] };
    const [bandLow, bandHigh] = bands[params.status ?? ''] ?? [0.5, 1];

    const max = Math.max(0, high * bandHigh - jumped);
    const min = Math.min(low * bandLow, max);
    return { capacity: high, min: Math.max(0, min), max };
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

/**
 * Patch 20: the hole types that could make a pipe whose type isn't known,
 * from what the map knows: both ends' classes, and which end is the K162
 * (the hole spawned on the other end). A picked K162 range ("C2/3") narrows
 * the far side the same way. Frigate holes and special holes (Thera,
 * drifters, Pochven: they carry `extra`) are left out.
 *
 * One type left: the pipe is drawn as that type ("probably N432"). Several
 * of one size: that size. Otherwise null (the caller keeps the old guess).
 */
export type TPossibleType = TGuessType & { spawn_areas?: readonly string[] | null; extra?: string | null };

export function possibleHoleTypes(params: {
    /** The class the K162 sits in (the hole leads there), when known. */
    k162Class?: string | null;
    /** Where the hole spawned (the far side's class, or a picked range), when known. */
    spawnClasses?: readonly string[] | null;
    /** Both ends' classes (a jumped connection), for when neither side's type is known. */
    endClasses?: readonly (string | null | undefined)[] | null;
    types: readonly TPossibleType[];
}): TPossibleType[] {
    const norm = (value: string | null | undefined) => String(value ?? '').toLowerCase().replace(/^c(?=\d)/, '');
    const usable = params.types.filter(
        (type) => !type.name.toUpperCase().startsWith('K162') && !type.extra && type.total > 0 && !isFrigateHole(type.maxJump),
    );
    const spawnsIn = (type: TPossibleType, cls: string) => (type.spawn_areas ?? []).some((area) => norm(area) === cls);
    const into = params.k162Class ? norm(params.k162Class) : null;
    const from = (params.spawnClasses ?? []).map(norm).filter((cls) => cls && cls !== 'unknown');

    if (into && into !== 'unknown') {
        if (from.length === 0) return [];
        return usable.filter((type) => norm(type.target_class) === into && from.some((cls) => spawnsIn(type, cls)));
    }
    const [a, b] = (params.endClasses ?? []).map(norm);
    if (!a || !b || a === 'unknown' || b === 'unknown') return [];
    return usable.filter((type) => (spawnsIn(type, a) && norm(type.target_class) === b) || (spawnsIn(type, b) && norm(type.target_class) === a));
}

/** Patch 20: what the candidates say about the hole: its type when only one fits, its size when they agree. */
export function guessFromCandidates(candidates: readonly TPossibleType[]): { total: number; name: string | null; maxJump: number } | null {
    if (candidates.length === 0) return null;
    const names = [...new Set(candidates.map((type) => type.name.toUpperCase()))];
    const sizeOf = (jump: number) => (jump <= 62_000_000 ? 'medium' : jump < 2_000_000_000 ? 'large' : 'xlarge');
    const sizes = new Set(candidates.map((type) => sizeOf(type.maxJump)));
    if (sizes.size !== 1) return null;
    const smallest = candidates.reduce((best, type) => (type.total < best.total ? type : best));
    return { total: smallest.total, name: names.length === 1 ? names[0] : null, maxJump: smallest.maxJump };
}
