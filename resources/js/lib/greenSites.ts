/**
 * Patch 37: what the combat anomalies in a C5 / C6 are worth (million ISK), two ways:
 * - Recon: the site plus the drifter recon wave (100);
 * - Dread: the site plus the avengers a dread spawns (C5 3, C6 4, 50 each) and the bigger
 *   drifter (300) instead of the recon.
 * Site values from the corp's guide (Rykki's, updated).
 */
export type TGreenSite = { name: string; wormholeClass: 5 | 6; base: number };

export const GREEN_SITES: TGreenSite[] = [
    { name: 'Core Garrison', wormholeClass: 5, base: 253.4 },
    { name: 'Core Stronghold', wormholeClass: 5, base: 234.9 },
    { name: 'Oruze Osobnyk', wormholeClass: 5, base: 164.7 },
    { name: 'Quarantine Area', wormholeClass: 5, base: 146.9 },
    { name: 'Core Bastion', wormholeClass: 6, base: 445.6 },
    { name: 'Core Citadel', wormholeClass: 6, base: 310.1 },
    { name: 'Strange Energy Readings', wormholeClass: 6, base: 291.8 },
    { name: 'The Mirror', wormholeClass: 6, base: 363.2 },
];

export const RECON = 100;
export const DREAD_DRIFTER = 300;
export const AVENGER = 50;
const AVENGERS: Record<5 | 6, number> = { 5: 3, 6: 4 };

export function siteValue(site: TGreenSite): { recon: number; dread: number } {
    return { recon: site.base + RECON, dread: site.base + AVENGERS[site.wormholeClass] * AVENGER + DREAD_DRIFTER };
}

export type TGreenTotal = { recon: number; dread: number; lines: { name: string; count: number; recon: number; dread: number }[] };

/** The sites among these names (signature type or pasted name), counted and totalled. Null when none. */
export function greenTotal(names: readonly (string | null | undefined)[]): TGreenTotal | null {
    const counts = new Map<TGreenSite, number>();
    for (const name of names) {
        const lower = (name ?? '').toLowerCase();
        const site = GREEN_SITES.find((candidate) => lower.includes(candidate.name.toLowerCase()));
        if (site) counts.set(site, (counts.get(site) ?? 0) + 1);
    }
    if (!counts.size) return null;
    const lines = GREEN_SITES.filter((site) => counts.has(site)).map((site) => {
        const count = counts.get(site)!;
        const value = siteValue(site);
        return { name: site.name, count, recon: value.recon * count, dread: value.dread * count };
    });
    return { recon: lines.reduce((sum, line) => sum + line.recon, 0), dread: lines.reduce((sum, line) => sum + line.dread, 0), lines };
}

/** "1,413". */
export function isk(million: number): string {
    return Math.round(million).toLocaleString('en-US');
}
