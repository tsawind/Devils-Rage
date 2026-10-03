import { isFrigateHole } from '@/lib/massEstimate';
import { shipSizeFromJumpMass } from '@/lib/shipSize';
import { wormholeMass } from '@/lib/wormholeMass';
import type { TShipSize } from '@/types/models';

/**
 * Patch 20: K162s narrowed down from Show Info.
 *
 * A K162's Show Info says how dangerous the far side is ("unknown" = C1–C3,
 * "dangerous" = C4–C5, "deadly" = C6) and how big ships can be. Together with
 * the class you stand in that narrows the far side down:
 * - in a C1 every hole says "medium": unknown = K162 C1/2/3;
 * - in a C2–C6, unknown + medium = a C1, unknown + large = K162 C2/C3;
 * - in a C1–C4, dangerous = K162 C4/C5;
 * - in a C5–C6, dangerous + large = a C4, + very large = a C5;
 * - deadly = C6 anywhere.
 * Plus one "K162 frigate" option (frigate-thin, class unknown).
 *
 * The grouped types are their own signature types (target class "unknown",
 * the range in `extra`), so a bookmark made from one never needs changing:
 * the jump settles the class on the map.
 */

type TTypeLike = { id: number; name?: string | null; signature?: string | null; target_class?: string | null; extra?: string | null; spawn_areas?: readonly string[] | null };

export const K162_FRIGATE = 'frigate';

const isK162 = (type: { signature?: string | null } | null | undefined) => (type?.signature ?? '').toUpperCase() === 'K162';

/** The classes a K162 type stands for: ["4", "5"] for K162 C4/5, ["5"] for K162 C5, [] when unknown or frigate. */
export function k162Classes(type: TTypeLike | null | undefined): string[] {
    if (!type || !isK162(type)) return [];
    const extra = (type.extra ?? '').trim();
    const range = /^C(\d)(?:\/(\d))?(?:\/(\d))?$/i.exec(extra);
    if (range) {
        const first = Number(range[1]);
        const rest = [range[2], range[3]].filter(Boolean).map(Number);
        // "C1/2/3" and "C2/3": each later number is its own class.
        return [first, ...rest].map(String);
    }
    const target = type.target_class ?? '';
    return target && target !== 'unknown' ? [target] : [];
}

export function isK162Range(type: TTypeLike | null | undefined): boolean {
    return k162Classes(type).length > 1;
}

export function isK162Frigate(type: TTypeLike | null | undefined): boolean {
    return isK162(type) && (type?.extra ?? '').toLowerCase() === K162_FRIGATE;
}

/** "C4/5", "C2/3", "C1/2/3" for a grouped K162, else null. */
export function k162RangeLabel(type: TTypeLike | null | undefined): string | null {
    const classes = k162Classes(type);
    return classes.length > 1 ? `C${classes.join('/')}` : null;
}

const wormholeNumber = (value: string | number | null | undefined): number | null => {
    const parsed = Number(String(value ?? '').replace(/^c/i, ''));
    return Number.isInteger(parsed) && parsed >= 1 && parsed <= 6 ? parsed : null;
};

/** Whether a grouped K162 is offered where you stand (class 1–6). Plain K162s are always offered. */
export function offersK162(type: TTypeLike, standingClass: string | number | null | undefined): boolean {
    if (!isK162(type)) return true;
    if (isK162Frigate(type)) return true;
    const label = k162RangeLabel(type);
    if (!label) return true;
    const here = wormholeNumber(standingClass);
    if (here === null) return false;
    if (label === 'C1/2/3') return here === 1;
    if (label === 'C4/5') return here >= 1 && here <= 4;
    if (label === 'C2/3') return here >= 2;
    return true;
}

/** The Show Info reading that points to this K162 where you stand ("dangerous · large"), or null. */
export function k162Hint(type: TTypeLike, standingClass: string | number | null | undefined): string | null {
    if (!isK162(type)) return null;
    if (isK162Frigate(type)) return 'small';
    const label = k162RangeLabel(type);
    const here = wormholeNumber(standingClass);
    if (label === 'C1/2/3') return 'unknown';
    if (label === 'C2/3') return 'unknown · large';
    if (label === 'C4/5') return 'dangerous';
    const target = type.target_class ?? '';
    if (target === '6') return 'deadly';
    if (here === null) return null;
    if (target === '1' && here >= 2) return 'unknown · medium';
    if (target === '4' && here >= 5) return 'dangerous · large';
    if (target === '5' && here >= 5) return 'dangerous · very large';
    return null;
}

/** Sort weight inside the K162 group: the class-narrowing options first, in class order. */
export function k162Order(type: TTypeLike): number {
    if (isK162Frigate(type)) return 90;
    const classes = k162Classes(type);
    if (classes.length === 0) return 95;
    const first = wormholeNumber(classes[0]);
    if (first === null) return 80 + (type.target_class ?? '').charCodeAt(0) / 1000;
    return first * 2 + (classes.length > 1 ? 1 : 0);
}

/**
 * Patch 20: the size a K162 must be, from the wormhole data: every hole type
 * that spawns in the far side's class(es) and leads into the class you stand
 * in. One size for all of them = that size; anything else (sizes differ, a
 * type with no data, nothing known) = null, no badge. Frigate holes and
 * special holes (Thera, drifters…) are left out: a K162 frigate is its own option.
 */
export function k162ShipSize(
    type: TTypeLike | null | undefined,
    standingClass: string | number | null | undefined,
    allTypes: readonly TTypeLike[],
): TShipSize | null {
    if (!type || !isK162(type)) return null;
    if (isK162Frigate(type)) return 'frigate';
    const far = k162Classes(type);
    const here = String(standingClass ?? '');
    if (far.length === 0 || !here) return null;

    const sizes = new Set<TShipSize | null>();
    for (const candidate of allTypes) {
        if (isK162(candidate) || candidate.extra) continue;
        if ((candidate.target_class ?? '') !== here) continue;
        if (!(candidate.spawn_areas ?? []).some((area) => far.includes(String(area)))) continue;
        const mass = wormholeMass(candidate.signature ?? null);
        if (!mass) {
            sizes.add(null);
            continue;
        }
        if (isFrigateHole(mass.maxJump)) continue;
        sizes.add(shipSizeFromJumpMass(mass.maxJump));
    }
    if (sizes.size !== 1) return null;
    return [...sizes][0] ?? null;
}

/** For the build check: every K162 option per class you stand in, with its hint and size. */
export function k162Table(allTypes: readonly TTypeLike[]): { standing: string; option: string; hint: string | null; size: TShipSize | null }[] {
    const rows: { standing: string; option: string; hint: string | null; size: TShipSize | null }[] = [];
    for (const standing of ['1', '2', '3', '4', '5', '6']) {
        for (const type of allTypes.filter((candidate) => isK162(candidate) && offersK162(candidate, standing))) {
            rows.push({ standing: `C${standing}`, option: type.name ?? '', hint: k162Hint(type, standing), size: k162ShipSize(type, standing, allTypes) });
        }
    }
    return rows;
}
