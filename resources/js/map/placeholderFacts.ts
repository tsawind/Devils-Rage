import { getTypesByCategory, signatureCategories, signatureTypeById } from '@/const/signatures';
import { isK162Frigate, k162Classes, k162ShipSize } from '@/lib/k162';
import type { TPlaceholder } from '@/lib/placeholders';
import { SHIP_SIZE_LETTERS, shipSizeFromJumpMass } from '@/lib/shipSize';
import { wormholeMass } from '@/lib/wormholeMass';
import { guessHole } from '@/map/holeGuess';
import type { TMapSolarsystem } from '@/pages/maps';

const wormholeTypes = getTypesByCategory(signatureCategories.find((category) => category.code === 'wormhole')?.id ?? 0);

/**
 * Patch 20: the size letter of a dotted pipe when it is known: a typed hole's own
 * size (arrow away: it spawned here), a K162's size worked out from the wormhole data
 * (arrow toward you), a K162 frigate S. Unknown or guessed: no letter.
 */
export function knownSize(placeholder: { wormhole: string | null; signatureTypeId?: number | null; targetClass?: string | null }, parentClass: string | null): { letter: string; arrow: string } | null {
    const name = (placeholder.wormhole ?? '').toUpperCase();
    if (!name) return null;
    if (name !== 'K162') {
        const mass = wormholeMass(name);
        const size = mass ? shipSizeFromJumpMass(mass.maxJump) : null;
        return size ? { letter: SHIP_SIZE_LETTERS[size], arrow: '↗' } : null;
    }
    const type =
        (placeholder.signatureTypeId ? signatureTypeById.get(placeholder.signatureTypeId) : null) ??
        ({ id: 0, signature: 'K162', target_class: placeholder.targetClass ?? null, extra: null } as const);
    const size = k162ShipSize(type, parentClass, wormholeTypes);
    return size ? { letter: SHIP_SIZE_LETTERS[size], arrow: '↙' } : null;
}

/** Patch 21: what the map knows about an unjumped hole: its type, the size it must be, or a guess. */
export function holeFacts(placeholder: TPlaceholder, parent: TMapSolarsystem) {
    const typeMass = wormholeMass(placeholder.wormhole);
    const isK162 = (placeholder.wormhole ?? '').toUpperCase().startsWith('K162');
    const parentClass = parent.solarsystem?.class === undefined || parent.solarsystem?.class === null ? null : String(parent.solarsystem.class);
    // Patch 20: a K162 is sized from the holes that come from its far side (the class picked:
    // "K162 C2/3", "K162 C5"), into the class it sits in, so the pipe matches its badge.
    const holeTypeInfo = placeholder.signatureTypeId ? (signatureTypeById.get(placeholder.signatureTypeId) ?? null) : null;
    const farClasses = isK162 ? (holeTypeInfo ? k162Classes(holeTypeInfo) : placeholder.targetClass && placeholder.targetClass !== 'unknown' ? [placeholder.targetClass] : []) : [];
    const guess =
        typeMass || placeholder.shipSize === 'frigate' || isK162Frigate(holeTypeInfo) || (placeholder.wormhole && !isK162)
            ? null
            : guessHole(isK162 ? { k162Class: parentClass, spawnClasses: farClasses } : { endClasses: [parentClass, placeholder.targetClass ?? null] });
    const size = knownSize(placeholder, parentClass);
    return { typeMass, isK162, parentClass, holeTypeInfo, guess, size };
}

