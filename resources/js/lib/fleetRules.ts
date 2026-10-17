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

    /** Patch 37: the mail and the route page list up to this many chokes and risks, one per line. */
    maxListed: 5,
    /** Patch 37: there a hole is a choke within this factor of the tightest hole. */
    wideChokeFactor: 2,
    /** Patch 37: a hole nobody has looked at for this many hours is a risk (the purple "?"). */
    uncheckedHours: 4,
    maxScanTargets: 3,

    /**
     * Patch 39: the watch banner's buttons, by EVE overview bracket. Each click logs the WORST
     * case for that class (kg): what the heaviest likely fit adds, so "could be Reduced" comes
     * early rather than late. `base` is a typical hull; the fit parts are added on top:
     * Higgs anchor doubles the hull, a 100MN prop adds 50 M (doubled with Higgs), a plate a few M.
     */
    watchClasses: [
        { key: 'frigate', label: 'Frigate', short: 'F', base: 1_500_000, higgs: false, prop: 0, plate: 500_000 },
        { key: 'destroyer', label: 'Destroyer', short: 'D', base: 2_000_000, higgs: false, prop: 0, plate: 800_000 },
        { key: 'cruiser', label: 'Cruiser', short: 'C', base: 12_000_000, higgs: false, prop: 50_000_000, plate: 1_250_000 },
        { key: 'battlecruiser', label: 'Battlecruiser', short: 'BC', base: 15_000_000, higgs: false, prop: 50_000_000, plate: 2_500_000 },
        { key: 'battleship', label: 'Battleship', short: 'BS', base: 100_000_000, higgs: true, prop: 50_000_000, plate: 3_000_000 },
        { key: 'industrial', label: 'Industrial', short: 'Ind', base: 20_000_000, higgs: false, prop: 0, plate: 2_500_000 },
        { key: 'capital', label: 'Capital', short: 'Cap', base: 1_300_000_000, higgs: false, prop: 500_000_000, plate: 0 },
    ],
} as const;

export type TWatchClass = (typeof FLEET_RULES.watchClasses)[number];

/** Patch 39: a hand-added jump's mass from its class and fit (each switch on = the worst case). */
export function watchJumpMass(shipClass: TWatchClass, fit: { higgs?: boolean; prop?: boolean; plate?: boolean } = { higgs: shipClass.higgs, prop: true, plate: true }): number {
    const higgs = Boolean(fit.higgs);
    const hull = shipClass.base * (higgs ? 2 : 1);
    const prop = fit.prop ? shipClass.prop * (higgs ? 2 : 1) : 0;
    return Math.round(hull + prop + (fit.plate ? shipClass.plate : 0));
}

/** Patch 39: the class of a hand-added jump by key. */
export function watchClass(key: string | null | undefined): TWatchClass | null {
    return FLEET_RULES.watchClasses.find((candidate) => candidate.key === key) ?? null;
}

export type TSizeLabel = (typeof FLEET_RULES.sizeBuckets)[number]['label'];

/** The size label for a max jump mass ("Battleship" for 375 M). */
export function sizeLabel(maxJumpMass: number): TSizeLabel {
    return FLEET_RULES.sizeBuckets.find((bucket) => maxJumpMass <= bucket.upTo)?.label ?? 'Capital';
}
