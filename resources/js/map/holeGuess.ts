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
