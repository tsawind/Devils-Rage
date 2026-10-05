import type { TMassEstimate } from '@/lib/massEstimate';

/**
 * Patch 25: the text copied when you click a character's location on its pill: how to get
 * there from the origin (Daisy by default), how much mass the way can take, and its
 * chokepoint. E.g.
 *
 *   Daisy → … → A111-121 ABC HSs → Amarr (Mass available: 900m kg ↔ 2,200m kg) Chokepoint - A111-12↔A111-121 "B274" 2b kg+-
 */

export type TRouteHole = {
    /** The hole's type ("B274"); null when not known. */
    typeName: string | null;
    /** What's left of it; null for a stargate or an unknown size. */
    estimate: TMassEstimate | null;
    /** The signature on the near side (the side the route leaves from), e.g. "ABC-123". */
    nearSignature: string | null;
    /** Whether that near-side signature is the near system's static. */
    nearIsStatic: boolean;
};

export type TRouteBookmarkInput = {
    /** Solar system ids from the origin to the character, both included. */
    steps: readonly number[];
    /** The map's name for a system (alias) or its real name. */
    nameOf: (solarsystemId: number) => string;
    /** Whether the system is on the map. */
    isMapped: (solarsystemId: number) => boolean;
    /** "c5", "hs", "ls", "ns"; null when not known. */
    classOf: (solarsystemId: number) => string | null;
    /** The map connection between two neighbouring steps (from `from`'s side); null for a gate. */
    holeBetween: (from: number, to: number) => TRouteHole | null;
};

/** 900 M → "900m", 2.2 B → "2,200m". */
function millions(kg: number): string {
    return `${Math.round(kg / 1_000_000).toLocaleString('en-US')}m`;
}

/** About: 2.04 B → "2b", 1.55 B → "1.6b", 640 M → "640m". */
function about(kg: number): string {
    if (kg >= 1_000_000_000) return `${(kg / 1_000_000_000).toLocaleString('en-US', { maximumFractionDigits: kg >= 1_950_000_000 ? 0 : 1 })}b`;
    return millions(kg);
}

export function routeBookmark(input: TRouteBookmarkInput): string {
    const { steps, nameOf, isMapped, classOf, holeBetween } = input;
    if (steps.length === 0) return '';
    if (steps.length === 1) return nameOf(steps[0]);

    const origin = steps[0];
    const destination = steps[steps.length - 1];

    // How far along the route stays on the map (the origin always counts).
    let lastMapped = 0;
    while (lastMapped + 1 < steps.length && isMapped(steps[lastMapped + 1])) lastMapped++;

    const parts: string[] = [nameOf(origin)];
    if (lastMapped === steps.length - 1) {
        // The character is somewhere on the map: origin → … → where they are.
        if (steps.length > 3) parts.push('…');
        else for (const step of steps.slice(1, -1)) parts.push(nameOf(step));
        parts.push(nameOf(destination));
    } else {
        // They left the map: the way out (system, signature, where it leads), then where they are.
        const exit = steps[lastMapped];
        const beyond = steps[lastMapped + 1];
        if (lastMapped >= 2) parts.push('…');
        const hole = holeBetween(exit, beyond);
        const sig = hole?.nearSignature ? hole.nearSignature.slice(0, 3).toUpperCase() : null;
        // Only a wormhole out gets its signature and where it leads (a gate out is just the system).
        const leadsTo = hole ? (classOf(beyond)?.toUpperCase() ?? null) : null;
        const label = [nameOf(exit), sig, leadsTo ? `${leadsTo}${hole?.nearIsStatic ? 's' : ''}` : null].filter(Boolean).join(' ');
        if (lastMapped === 0) parts[0] = label;
        else parts.push(label);
        parts.push(nameOf(destination));
    }
    let text = parts.join(' → ');

    // The mass the whole way can take: the tightest hole decides.
    const holes: { from: number; to: number; hole: TRouteHole & { estimate: TMassEstimate } }[] = [];
    for (let index = 0; index + 1 < steps.length; index++) {
        const hole = holeBetween(steps[index], steps[index + 1]);
        if (hole?.estimate) holes.push({ from: steps[index], to: steps[index + 1], hole: { ...hole, estimate: hole.estimate } });
    }
    if (holes.length > 0) {
        const low = Math.min(...holes.map((entry) => entry.hole.estimate.min));
        const high = Math.min(...holes.map((entry) => entry.hole.estimate.max));
        const choke = holes.reduce((tightest, entry) => (entry.hole.estimate.max < tightest.hole.estimate.max ? entry : tightest));
        const middle = (choke.hole.estimate.min + choke.hole.estimate.max) / 2;
        text += ` (Mass available: ${millions(low)} kg ↔ ${millions(high)} kg)`;
        text += ` Chokepoint - ${nameOf(choke.from)}↔${nameOf(choke.to)} "${choke.hole.typeName ?? '?'}" ${about(middle)} kg+-`;
    }
    return text;
}
