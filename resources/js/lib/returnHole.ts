/**
 * Return-hole detection after a paste: which pasted wormhole is the hole you
 * just came through, and whether the mapper may link it without asking.
 *
 * Right after a jump you sit on the return hole, so it is the one wormhole on
 * grid. EVE shows on-grid distances in m (under 10 km) or km, and anything off
 * grid in AU.
 */

export type TScanDistance = {
    /** Distance in meters, for sorting. */
    meters: number;
    /** Whether the signature is on grid (shown in m or km). */
    onGrid: boolean;
    /** The distance as EVE showed it, for display. */
    text: string;
};

const METERS_PER_AU = 149_597_870_700;

/** How long after your own jump a single on-grid wormhole is linked without asking. */
export const AUTO_LINK_WINDOW_MS = 80_000;

/**
 * Parse a probe-scanner distance such as "2,600 m", "505 km", "12.56 AU",
 * "1.234,5 km" or "2 600 m". Returns null for anything that isn't a distance
 * (e.g. the signal column "100.0%").
 */
export function parseScanDistance(value: string | null | undefined): TScanDistance | null {
    const text = (value ?? '').replace(/ | /g, ' ').trim();
    const match = /^([\d\s.,]+?)\s*(km|m|au)$/i.exec(text);
    if (!match) return null;

    const unit = match[2].toLowerCase();
    const amount = parseLocalizedNumber(match[1], unit !== 'au');
    if (amount === null) return null;

    const meters = unit === 'km' ? amount * 1000 : unit === 'au' ? amount * METERS_PER_AU : amount;

    return { meters, onGrid: unit !== 'au', text };
}

/**
 * "2,600" → 2600, "12.56" → 12.56, "1.234,5" → 1234.5, "2 600" → 2600. When
 * only one kind of separator appears, it is a thousands separator if it is
 * followed by exactly three digits and `preferThousands` is set (m / km),
 * otherwise a decimal point.
 */
function parseLocalizedNumber(raw: string, preferThousands: boolean): number | null {
    let digits = raw.replace(/\s/g, '');
    if (!/\d/.test(digits)) return null;

    const lastComma = digits.lastIndexOf(',');
    const lastDot = digits.lastIndexOf('.');

    if (lastComma !== -1 && lastDot !== -1) {
        const decimal = lastComma > lastDot ? ',' : '.';
        const thousands = decimal === ',' ? '.' : ',';
        digits = digits.split(thousands).join('').replace(decimal, '.');
    } else if (lastComma !== -1 || lastDot !== -1) {
        const separator = lastComma !== -1 ? ',' : '.';
        const parts = digits.split(separator);
        const looksLikeThousands = parts.length > 2 || (preferThousands && parts[parts.length - 1].length === 3);
        digits = looksLikeThousands ? parts.join('') : parts.join('.');
    }

    const value = Number(digits);
    return Number.isFinite(value) ? value : null;
}

/** Find the distance column in a pasted scanner row: the last cell that reads as a distance. */
export function distanceFromScanRow(cells: string[]): TScanDistance | null {
    for (let index = cells.length - 1; index >= 0; index--) {
        const distance = parseScanDistance(cells[index]);
        if (distance) return distance;
    }
    return null;
}

export type TReturnCandidate = {
    id: number;
    distance?: TScanDistance | null;
};

/** Candidates nearest first; ones without a known distance go last, keeping their order. */
export function sortByDistance<T extends TReturnCandidate>(candidates: T[]): T[] {
    return candidates.toSorted((a, b) => (a.distance?.meters ?? Number.POSITIVE_INFINITY) - (b.distance?.meters ?? Number.POSITIVE_INFINITY));
}

export type TReturnHoleDecision =
    | { mode: 'none' }
    | { mode: 'auto'; candidateId: number }
    | { mode: 'ask'; preselectId: number | null; ordered: number[] };

/**
 * Decide what to do after a paste in a system that still has a connection
 * without a signature on this side:
 * - no wormhole candidates: nothing;
 * - within 80 s of your own jump into this system and exactly one wormhole on
 *   grid: link it automatically;
 * - otherwise ask, nearest first, preselecting the nearest one if it is on grid.
 */
export function decideReturnHole(params: {
    candidates: TReturnCandidate[];
    /** When the mapper saw you jump into this system (ms), or null if you didn't. */
    jumpedAt: number | null;
    now: number;
    windowMs?: number;
}): TReturnHoleDecision {
    const { candidates, jumpedAt, now, windowMs = AUTO_LINK_WINDOW_MS } = params;
    if (candidates.length === 0) return { mode: 'none' };

    const ordered = sortByDistance(candidates);
    const onGrid = ordered.filter((candidate) => candidate.distance?.onGrid);

    const withinWindow = jumpedAt !== null && now - jumpedAt >= 0 && now - jumpedAt <= windowMs;
    if (withinWindow && onGrid.length === 1) {
        return { mode: 'auto', candidateId: onGrid[0].id };
    }

    return { mode: 'ask', preselectId: onGrid[0]?.id ?? null, ordered: ordered.map((candidate) => candidate.id) };
}

export type TOpenConnection = {
    id: number;
    /** The EVE system id at the other end of the connection. */
    otherSolarsystemId: number;
    createdAt: string;
};

/**
 * Order the connections still missing a signature on this side: the one back
 * to the system you just jumped from first, then newest first.
 */
export function orderOpenConnections<T extends TOpenConnection>(connections: T[], jumpedFromSolarsystemId: number | null): T[] {
    return connections.toSorted((a, b) => {
        const aFrom = a.otherSolarsystemId === jumpedFromSolarsystemId ? 0 : 1;
        const bFrom = b.otherSolarsystemId === jumpedFromSolarsystemId ? 0 : 1;
        if (aFrom !== bFrom) return aFrom - bFrom;
        return b.createdAt.localeCompare(a.createdAt);
    });
}

/** A row in the return-hole popup. */
export type TReturnHoleOption = {
    id: number;
    signatureId: string;
    typeLabel: string;
    distanceText: string | null;
    onGrid: boolean;
};

/** A connection choice in the return-hole popup. */
export type TReturnConnectionOption = {
    id: number;
    label: string;
};
