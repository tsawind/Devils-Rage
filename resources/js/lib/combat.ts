import { isWormholeClass } from '@/const/solarsystemClasses';
import type { TStringedSolarsystemClass } from '@/types/models';

/**
 * Combat mode (The Devil's Rage): chains started from a combat home, kept
 * apart by color, plus the dead-end and route helpers that go with them.
 */

/** Chain colors in the order they are handed out. Kept in sync with CombatModeAction::COLORS. */
export const COMBAT_COLORS = ['red', 'blue', 'green', 'yellow', 'purple', 'orange'] as const;

export type TCombatColor = (typeof COMBAT_COLORS)[number];

const COMBAT_COLOR_META: Record<TCombatColor, { label: string; hex: string }> = {
    red: { label: 'Red', hex: '#ef4444' },
    blue: { label: 'Blue', hex: '#3b82f6' },
    green: { label: 'Green', hex: '#22c55e' },
    yellow: { label: 'Yellow', hex: '#eab308' },
    purple: { label: 'Purple', hex: '#a855f7' },
    orange: { label: 'Orange', hex: '#f97316' },
};

function isCombatColor(color: string | null | undefined): color is TCombatColor {
    return typeof color === 'string' && color in COMBAT_COLOR_META;
}

/** "Red", or null when the system isn't in a chain. */
export function combatColorLabel(color: string | null | undefined): string | null {
    return isCombatColor(color) ? COMBAT_COLOR_META[color].label : null;
}

/** The chain's display color, or null when the system isn't in a chain. */
export function combatColorHex(color: string | null | undefined): string | null {
    return isCombatColor(color) ? COMBAT_COLOR_META[color].hex : null;
}

type TChainSystem = {
    alias?: string | null;
    combat_color?: string | null;
    combat_home?: boolean | null;
};

/**
 * The aliases that count when numbering holes in `system`. Each combat chain
 * numbers from 1 on its own, so a chain only sees its own systems; the main
 * chain sees every system outside a combat chain, plus the combat homes (they
 * keep their main-chain alias, e.g. "A1").
 */
export function chainAliases(systems: readonly TChainSystem[], system: TChainSystem | null | undefined): string[] {
    const color = system?.combat_color ?? null;

    return systems
        .filter((candidate) => (color ? candidate.combat_color === color : !candidate.combat_color || Boolean(candidate.combat_home)))
        .map((candidate) => candidate.alias)
        .filter((alias): alias is string => Boolean(alias && alias.trim()));
}

// ---- Dead ends --------------------------------------------------------------

/** A scan older than this can't be trusted to show every hole (new K162s spawn). */
export const DEAD_END_STALE_MS = 4 * 60 * 60 * 1000;

export type TDeadEndSystem = {
    combat_home?: boolean | null;
    scanned_at?: string | null;
    signatures_count?: number | null;
    wormhole_signatures_count?: number | null;
    uncategorized_signatures_count?: number | null;
    solarsystem: { class?: TStringedSolarsystemClass | null };
};

/**
 * A wormhole system is a dead end when a scan was pasted in the last 4 hours,
 * every signature in it is identified, and its only wormhole is the one
 * connection you came in by. Home and combat homes never count.
 */
export function isDeadEnd(system: TDeadEndSystem, connectionCount: number, now: number, isHome: boolean): boolean {
    if (isHome || system.combat_home) return false;
    if (!isWormholeClass(system.solarsystem.class)) return false;

    const scannedAt = system.scanned_at ? Date.parse(system.scanned_at) : Number.NaN;
    if (!Number.isFinite(scannedAt) || now - scannedAt > DEAD_END_STALE_MS) return false;

    if ((system.signatures_count ?? 0) < 1) return false;
    if ((system.uncategorized_signatures_count ?? 0) > 0) return false;

    return (system.wormhole_signatures_count ?? 0) === 1 && connectionCount === 1;
}

// ---- Route texts ------------------------------------------------------------

const SECURITY_LABELS: Record<string, string> = { h: 'highsec', l: 'lowsec', n: 'nullsec', p: 'Pochven' };

type TRouteSystem = {
    alias?: string | null;
    combat_color?: string | null;
    solarsystem: { name: string; class?: TStringedSolarsystemClass | null; region?: { name?: string | null } | null };
};

/**
 * What a chain system leads to, for pasting in fleet chat:
 * "Red route 1121 → highsec exit (Amarr, Domain)", "Red route 112 → C3 wormhole".
 */
export function describeChainRoute(system: TRouteSystem): string {
    const color = combatColorLabel(system.combat_color);
    const who = system.alias?.trim() || system.solarsystem.name;
    const solarsystemClass = system.solarsystem.class ?? null;

    let destination: string;
    if (isWormholeClass(solarsystemClass)) {
        destination = `C${solarsystemClass} wormhole`;
    } else {
        const region = system.solarsystem.region?.name;
        const security = (solarsystemClass && SECURITY_LABELS[solarsystemClass]) || 'k-space';
        destination = `${security} exit (${system.solarsystem.name}${region ? `, ${region}` : ''})`;
    }

    return `${color ? `${color} route` : 'Route'} ${who} → ${destination}`;
}

/** One system on a route, with how you got into it (null for the start). */
export type TRouteHop = {
    name: string;
    alias?: string | null;
    class?: TStringedSolarsystemClass | null;
    via: 'stargate' | 'wormhole' | 'evescout' | null;
};

/**
 * A short route text, e.g.
 * "Nearest highsec: 1121 → Tama (lowsec) → 3 gates → Nourvukaiken — 4 jumps".
 * Mapped systems use their alias; a run of gate jumps collapses to "N gates";
 * k-space you pass through shows its security.
 */
export function describeRoute(hops: readonly TRouteHop[], label: string): string | null {
    if (hops.length === 0) return null;

    const nameOf = (hop: TRouteHop): string => hop.alias?.trim() || hop.name;
    const parts: string[] = [nameOf(hops[0])];
    let gates = 0;

    for (let index = 1; index < hops.length; index++) {
        const hop = hops[index];
        const isLast = index === hops.length - 1;

        if (hop.via === 'stargate') {
            gates++;
            if (!isLast && hops[index + 1].via === 'stargate') continue;
            if (gates > 1) parts.push(`${gates} gates`);
            gates = 0;
        }

        const security = hop.class ? SECURITY_LABELS[hop.class] : undefined;
        const showSecurity = !isLast && !isWormholeClass(hop.class ?? null) && Boolean(security) && !hop.alias?.trim();
        parts.push(showSecurity ? `${nameOf(hop)} (${security})` : nameOf(hop));
    }

    const jumps = hops.length - 1;
    return `${label}: ${parts.join(' → ')} — ${jumps} ${jumps === 1 ? 'jump' : 'jumps'}`;
}
