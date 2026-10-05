/**
 * Patch 26: every number behind the route copies and the (experimental) predictions, in one
 * place so they can be tuned after testing. Masses in kg.
 */
export const FLEET_RULES = {
    /** Longest text a copy may be (fleet chat cut at ~1020, some channels at ~990). */
    maxCopyLength: 800,

    /**
     * Average ship mass per class, cold (prop off), with a typical plate fitted: how fleets
     * normally jump. Used to turn a hole's mass into "≈ 13 BS / 140 BC / 170 cruisers".
     */
    shipMass: {
        cruiser: 12_000_000 + 1_250_000,
        battlecruiser: 14_000_000 + 1_250_000,
        battleship: 100_000_000 + 3_000_000,
        capital: 1_300_000_000,
    },

    /** Hole size by the most a single ship may weigh per jump (a hole's max jump mass). */
    sizeBuckets: [
        { upTo: 5_000_000, label: 'Frig' },
        { upTo: 62_000_000, label: 'Medium' },
        { upTo: 375_000_000, label: 'Battleship' },
        { upTo: Infinity, label: 'Capital' },
    ],

    /** A hole marked end-of-life has at most this many hours left from when it was marked. */
    eolHours: 4,

    /** A hole is a chokepoint when what it has left (at most) is within this factor of the tightest hole's. */
    chokeFactor: 1.5,

    /** At most this many chokepoints, risks and scan targets in one copy. */
    maxChokepoints: 3,
    maxRisks: 3,
    maxScanTargets: 3,
} as const;

export type TSizeLabel = (typeof FLEET_RULES.sizeBuckets)[number]['label'];

/** The size label for a max jump mass ("Battleship" for 375 M). */
export function sizeLabel(maxJumpMass: number): TSizeLabel {
    return FLEET_RULES.sizeBuckets.find((bucket) => maxJumpMass <= bucket.upTo)?.label ?? 'Capital';
}
