import { getTypesByCategory, signatureCategories } from '@/const/signatures';
import { estimateHoleMass, type TGuessType } from '@/lib/massEstimate';
import { wormholeMass } from '@/lib/wormholeMass';

/**
 * Patch 18: the hole types the size guess picks from (every wormhole type with
 * a known mass), built once.
 */
let cached: TGuessType[] | null = null;

function guessTypes(): TGuessType[] {
    if (cached) return cached;
    const categoryId = signatureCategories.find((category) => category.code === 'wormhole')?.id ?? null;
    const list: TGuessType[] = [];
    if (categoryId !== null) {
        for (const type of getTypesByCategory(categoryId)) {
            const mass = wormholeMass(type.signature);
            if (mass) list.push({ name: type.signature, target_class: type.target_class, total: mass.total, maxJump: mass.maxJump });
        }
    }
    cached = list;
    return list;
}

/** The guessed total mass of a hole whose type isn't known, or null. */
export function guessHoleMass(params: { k162Class?: string | null; classes: readonly (string | null | undefined)[] }): number | null {
    return estimateHoleMass({ ...params, types: guessTypes() });
}
