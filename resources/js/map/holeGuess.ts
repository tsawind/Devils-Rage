import { getTypesByCategory, signatureCategories } from '@/const/signatures';
import { estimateHoleMass, guessFromCandidates, possibleHoleTypes, type TPossibleType } from '@/lib/massEstimate';
import { shipSizeFromJumpMass } from '@/lib/shipSize';
import type { TShipSize } from '@/types/models';
import { wormholeMass } from '@/lib/wormholeMass';

/**
 * Patch 18: the hole types the size guess picks from (every wormhole type with
 * a known mass), built once.
 */
let cached: TPossibleType[] | null = null;

function guessTypes(): TPossibleType[] {
    if (cached) return cached;
    const categoryId = signatureCategories.find((category) => category.code === 'wormhole')?.id ?? null;
    const list: TPossibleType[] = [];
    if (categoryId !== null) {
        for (const type of getTypesByCategory(categoryId)) {
            const mass = wormholeMass(type.signature);
            if (mass) list.push({ name: type.signature, target_class: type.target_class, total: mass.total, maxJump: mass.maxJump, spawn_areas: type.spawn_areas, extra: type.extra });
        }
    }
    cached = list;
    return list;
}

/** The guessed total mass of a hole whose type isn't known, or null. */
export function guessHoleMass(params: { k162Class?: string | null; classes: readonly (string | null | undefined)[] }): number | null {
    return estimateHoleMass({ ...params, types: guessTypes() });
}

/**
 * Patch 20: the best guess for a hole whose type isn't known. First the hole
 * types that fit what the map knows (K162 side, far side's class or picked
 * range, both ends): one type left = "probably N432", drawn as that type;
 * several of one size = that size. Otherwise the old smallest-hole guess,
 * with no type or size.
 */
export type THoleGuess = { total: number; name: string | null; size: TShipSize | null };

export function guessHole(params: {
    k162Class?: string | null;
    spawnClasses?: readonly string[] | null;
    endClasses?: readonly (string | null | undefined)[] | null;
}): THoleGuess | null {
    const types = guessTypes();
    const fit = guessFromCandidates(possibleHoleTypes({ ...params, types }));
    if (fit) return { total: fit.total, name: fit.name, size: shipSizeFromJumpMass(fit.maxJump) };
    const classes = params.endClasses?.length ? params.endClasses : [params.k162Class ?? null];
    const fallback = estimateHoleMass({ k162Class: params.k162Class ?? null, classes, types });
    return fallback ? { total: fallback, name: null, size: null } : null;
}

/**
 * Patch 37: every hole type that fits what the map knows ("Could be: N432, H296, V753"
 * in the details box), by name, without frigate-only holes unless nothing else fits.
 */
export function holeCandidates(params: {
    k162Class?: string | null;
    spawnClasses?: readonly string[] | null;
    endClasses?: readonly (string | null | undefined)[] | null;
    frigate?: boolean | null;
}): TPossibleType[] {
    // Patch 37: a K162 whose far side isn't known yet could come from anywhere.
    const anywhere = params.k162Class && params.k162Class !== 'unknown' && !(params.spawnClasses ?? []).some((cls) => cls && cls !== 'unknown');
    const fits = possibleHoleTypes({ ...params, spawnClasses: anywhere ? ANY_CLASS : params.spawnClasses, types: guessTypes() });
    const seen = new Set<string>();
    return fits
        .filter((type) => {
            const name = type.name.toUpperCase();
            if (seen.has(name)) return false;
            seen.add(name);
            return true;
        })
        .toSorted((a, b) => a.name.localeCompare(b.name));
}

const ANY_CLASS = ['1', '2', '3', '4', '5', '6', '13', 'h', 'l', 'n', 'p'];

/** Patch 37: the "Could be" line: up to 8 names, "+N more" after. */
export function couldBeNames(candidates: readonly TPossibleType[]): string[] {
    const names = candidates.map((candidate) => candidate.name.toUpperCase());
    return names.length > 8 ? [...names.slice(0, 7), `+${names.length - 7} more`] : names;
}
