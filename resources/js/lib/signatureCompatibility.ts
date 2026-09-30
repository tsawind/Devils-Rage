import { signatureCategories } from '@/const/signatures';
import type { TSignature, TStringedSolarsystemClass } from '@/types/models';

/**
 * Whether a scanned signature can be a wormhole connection at all: only
 * signatures categorised as wormholes, or not yet categorised, qualify.
 * Gas, data, relic, combat and ore sites are never connections.
 */
export function signatureCanBeConnection(signature: TSignature): boolean {
    const categoryCode = signature.signature_category?.code;

    return !categoryCode || categoryCode === 'wormhole';
}

/**
 * Whether a signature's assigned wormhole type could lead into a system of the
 * given class. A type with a concrete destination class only fits that exact
 * class — jumping a "leads to Nullsec" hole cannot land you in a C4.
 * Unresolved types and types with an unknown destination (e.g. a bare K162)
 * can always fit.
 */
export function signatureCanLeadToClass(signature: TSignature, targetClass: TStringedSolarsystemClass | null | undefined): boolean {
    const destinationClass = signature.signature_type?.target_class;
    if (!destinationClass || destinationClass === 'unknown') {
        return true;
    }

    if (!targetClass) {
        return true;
    }

    return destinationClass === targetClass;
}

export type TSignatureOptionGroups = {
    /** Unmapped signatures whose type (if any) can lead to the target class. */
    likely: TSignature[];
    /** Signatures already tied to a mapped connection. */
    connected: TSignature[];
    /** Unmapped signatures typed with a destination class that cannot match. */
    unlikely: TSignature[];
};

/**
 * Group a system's signatures into the jump prompt's three sections. Site
 * signatures are dropped entirely; a signature that is already part of a
 * mapped connection cannot be the newly jumped hole regardless of its type.
 */
export function groupSignatureOptions(signatures: TSignature[], targetClass: TStringedSolarsystemClass | null | undefined): TSignatureOptionGroups {
    const groups: TSignatureOptionGroups = { likely: [], connected: [], unlikely: [] };

    for (const signature of signatures) {
        if (!signatureCanBeConnection(signature)) {
            continue;
        }

        if (signature.map_connection_id) {
            groups.connected.push(signature);
        } else if (signatureCanLeadToClass(signature, targetClass)) {
            groups.likely.push(signature);
        } else {
            groups.unlikely.push(signature);
        }
    }

    groups.likely = groups.likely.toSorted((a, b) => likelyRank(a, targetClass) - likelyRank(b, targetClass));

    return groups;
}

/**
 * Order within the "likely" section, so the hole you most probably jumped is
 * first (and gets preselected): a wormhole whose identified type leads to
 * exactly the class you landed in, then other wormholes (untyped or K162),
 * then signatures not yet categorised. Ties keep their existing order.
 */
function likelyRank(signature: TSignature, targetClass: TStringedSolarsystemClass | null | undefined): number {
    const destinationClass = signature.signature_type?.target_class;
    const hasKnownDestination = Boolean(destinationClass) && destinationClass !== 'unknown';

    if (hasKnownDestination && targetClass && String(destinationClass) === String(targetClass)) {
        return 0;
    }

    return isWormholeSignature(signature) || hasKnownDestination || Boolean(signature.wormhole) ? 1 : 2;
}

/** Whether a signature is categorised as a wormhole, whichever shape the data arrived in. */
function isWormholeSignature(signature: TSignature): boolean {
    const category = signature.signature_category;
    if (category?.code === 'wormhole' || category?.name === 'Wormhole') return true;

    const wormholeCategoryId = signatureCategories.find((candidate) => candidate.code === 'wormhole')?.id;
    return wormholeCategoryId !== undefined && signature.signature_category_id === wormholeCategoryId;
}
