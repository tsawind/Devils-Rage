import { FLEET_RULES, sizeLabel } from '@/lib/fleetRules';
import { estimateMass, isFrigateHole, type TMassEstimate } from '@/lib/massEstimate';

/**
 * Patch 26: a route as one line for fleet chat: only the bookmarks people can't work out in
 * game, the size and mass the whole way can take (and how many cruisers / BCs / battleships
 * that is), its chokepoints and its risks. Plus the experimental "Will it hold?" and
 * "Scan plan" lines. All numbers come from FLEET_RULES.
 */

export type TRouteStep = {
    /** The map name (alias) or the system name. */
    name: string;
    /** On the map. */
    mapped: boolean;
    /** Jumps from home over the map (null when not on the map or not linked to home). */
    depth: number | null;
    /** In a rage chain. */
    rage: boolean;
};

export type TRouteHole = {
    typeName: string | null;
    /** The hole's total mass when new; null when the type isn't known. */
    totalMass: number | null;
    /** The most one ship may weigh per jump; null when not known. */
    maxJumpMass: number | null;
    jumpedMass: number;
    massStatus: string | null;
    lifetimeStatus: string | null;
    /** When it was marked end-of-life (ISO), if it is. */
    eolSince: string | null;
    /** The near side's signature ("ABC-123"), for the way out of the map. */
    nearSignature: string | null;
};

export type TRouteHop = { via: 'wormhole' | 'stargate' | 'evescout'; hole: TRouteHole | null };

export type TRouteInput = {
    steps: readonly TRouteStep[];
    /** hops[i] links steps[i] and steps[i + 1]. */
    hops: readonly TRouteHop[];
    /** In and back out the same way: half the mass. */
    roundTrip: boolean;
    now: Date;
    /** Patch 29d: which route this is ("Safest", "Shortest backup"), named at the front of the copy. */
    kind?: string | null;
};

type THoleOnRoute = { index: number; label: string; hole: TRouteHole; estimate: TMassEstimate | null };

const M = 1_000_000;

/** "2,100m" (millions, the way people say it in chat). */
export function mill(kg: number): string {
    return `${Math.round(kg / M).toLocaleString('en-US')}m`;
}

/** About: 2.04 B → "2b", 1.55 B → "1.6b", 640 M → "640m". */
function about(kg: number): string {
    if (kg >= 1_000_000_000) return `${(kg / 1_000_000_000).toLocaleString('en-US', { maximumFractionDigits: kg >= 1_950_000_000 ? 0 : 1 })}b`;
    return mill(kg);
}

/** Minutes → "40m", "1h20m". */
function duration(minutes: number): string {
    const rounded = Math.max(0, Math.round(minutes));
    const hours = Math.floor(rounded / 60);
    const rest = rounded % 60;
    return hours ? `${hours}h${rest ? `${String(rest).padStart(2, '0')}m` : ''}` : `${rest}m`;
}

/** Which steps a fleet needs written down, and which go home-ward (a * way-back bookmark). */
export function condenseRoute(steps: readonly TRouteStep[], hops: readonly TRouteHop[]): string {
    if (steps.length === 0) return '';
    const depth = (index: number) => steps[index].depth ?? Infinity;
    const keep = steps.map((_, index) => index === 0 || index === steps.length - 1);
    for (let index = 1; index < steps.length - 1; index++) {
        const before = hops[index - 1]?.via === 'stargate' ? 'gate' : 'hole';
        const after = hops[index]?.via === 'stargate' ? 'gate' : 'hole';
        const step = steps[index];
        if (before !== after) keep[index] = true; // into or out of k-space by gate
        if (step.mapped !== steps[index + 1].mapped || step.mapped !== steps[index - 1].mapped) keep[index] = true; // leaving / entering the map
        if (step.rage && (!steps[index - 1].rage || !steps[index + 1].rage)) keep[index] = true; // a rage run's first and last
        const up = depth(index) < depth(index - 1);
        const nextUp = depth(index + 1) < depth(index);
        if (step.mapped && up !== nextUp) keep[index] = true; // turns from home-ward to out-ward (or back)
    }
    const parts: string[] = [];
    let previous = -1;
    steps.forEach((step, index) => {
        if (!keep[index]) return;
        // Patch 29d: a stretch of k-space by gate says how many jumps it is ("Niarja → 7j → Amarr").
        if (previous >= 0 && hops.slice(previous, index).every((hop) => hop?.via === 'stargate')) parts.push(`${index - previous}j`);
        previous = index;
        // Reached by going home-ward: the bookmark there is a way-back one (*).
        const homeward = index > 0 && step.mapped && depth(index) < depth(index - 1);
        const hop = hops[index];
        // The way out of the map into k-space: the hole's signature, so a scout can find it.
        const exitSig = step.mapped && hop?.via !== 'stargate' && steps[index + 1] && !steps[index + 1].mapped ? hop?.hole?.nearSignature?.slice(0, 3).toUpperCase() : null;
        parts.push(`${homeward ? '*' : ''}${step.name}${exitSig ? ` (${exitSig})` : ''}`);
    });
    return parts.join(' → ');
}

function holesOnRoute(input: TRouteInput): THoleOnRoute[] {
    const result: THoleOnRoute[] = [];
    input.hops.forEach((hop, index) => {
        if (hop.via === 'stargate' || !hop.hole) return;
        const hole = hop.hole;
        result.push({
            index,
            label: `${input.steps[index].name}↔${input.steps[index + 1].name}`,
            hole,
            estimate: hole.totalMass ? estimateMass({ totalMass: hole.totalMass, jumped: hole.jumpedMass, status: hole.massStatus }) : null,
        });
    });
    return result;
}

function share(input: TRouteInput, kg: number): number {
    return input.roundTrip ? kg / 2 : kg;
}

/** The size, mass and fleet count line ("Battleship sized · 2,100m–2,600m kg ≈ 13 BS / …"). */
function massLine(input: TRouteInput, holes: THoleOnRoute[]): string | null {
    const sized = holes.map((entry) => entry.hole.maxJumpMass).filter((mass): mass is number => mass !== null);
    const estimated = holes.filter((entry): entry is THoleOnRoute & { estimate: TMassEstimate } => entry.estimate !== null);
    if (sized.length === 0 && estimated.length === 0) return null;
    const parts: string[] = [];
    const maxJump = sized.length ? Math.min(...sized) : null;
    if (maxJump !== null) parts.push(`${sizeLabel(maxJump)} sized`);
    // Patch 32: a frigate-only route: the mass doesn't matter.
    if (maxJump !== null && isFrigateHole(maxJump)) return parts.join(' · ');
    if (estimated.length) {
        const low = share(input, Math.min(...estimated.map((entry) => entry.estimate.min)));
        const high = share(input, Math.min(...estimated.map((entry) => entry.estimate.max)));
        const { shipMass } = FLEET_RULES;
        const fits = (ship: number) => maxJump === null || ship <= maxJump;
        const counts = [
            fits(shipMass.battleship) ? `${Math.floor(low / shipMass.battleship)} BS` : null,
            fits(shipMass.battlecruiser) ? `${Math.floor(low / shipMass.battlecruiser)} BC` : null,
            fits(shipMass.cruiser) ? `${Math.floor(low / shipMass.cruiser)} cruisers` : null,
        ].filter(Boolean);
        parts.push(`${mill(low)}–${mill(high)} kg${input.roundTrip ? ' in+out' : ''}${counts.length ? ` ≈ ${counts.join(' / ')}` : ''}`);
    }
    return parts.join(' · ');
}

function chokepoints(holes: THoleOnRoute[]): THoleOnRoute[] {
    const estimated = holes.filter((entry) => entry.estimate);
    if (estimated.length === 0) return [];
    const tightest = Math.min(...estimated.map((entry) => entry.estimate!.max));
    return estimated
        .filter((entry) => entry.estimate!.max <= tightest * FLEET_RULES.chokeFactor)
        .sort((a, b) => a.estimate!.max - b.estimate!.max)
        .slice(0, FLEET_RULES.maxChokepoints);
}

function chokeText(entry: THoleOnRoute): string {
    const middle = entry.estimate ? (entry.estimate.min + entry.estimate.max) / 2 : null;
    const status = entry.hole.massStatus && entry.hole.massStatus !== 'fresh' ? ` ${entry.hole.massStatus}` : '';
    return `${entry.label} "${entry.hole.typeName ?? '?'}"${middle !== null ? ` ${about(middle)}±` : ''}${status}`;
}

/** Worst case minutes left on an end-of-life hole (null when it isn't end-of-life). */
export function eolMinutesLeft(hole: TRouteHole, now: Date): number | null {
    if (hole.lifetimeStatus !== 'eol' && hole.lifetimeStatus !== 'critical') return null;
    if (!hole.eolSince) return null;
    const since = (now.getTime() - new Date(hole.eolSince).getTime()) / 60_000;
    return FLEET_RULES.eolHours * 60 - since;
}

function riskText(entry: THoleOnRoute, now: Date): string | null {
    const left = eolMinutesLeft(entry.hole, now);
    if (left !== null) {
        const ago = duration(FLEET_RULES.eolHours * 60 - left);
        return left > 0 ? `${entry.label} EOL, worst case ≤ ${duration(left)} left (marked ${ago} ago): scout it` : `${entry.label} EOL, may be gone (marked ${ago} ago): scout it`;
    }
    if (entry.hole.lifetimeStatus === 'eol' || entry.hole.lifetimeStatus === 'critical') return `${entry.label} EOL: scout it`;
    if (entry.hole.massStatus === 'critical') return `${entry.label} crit`;
    return null;
}

/** The whole copy for a route, cut to fit FLEET_RULES.maxCopyLength (extras go first, the route stays). */
export function routeSummary(input: TRouteInput, extra: string | null = null): string {
    // Patch 29d: "Safest 12j: …" (the kind when known, and the total jumps).
    const jumps = Math.max(0, input.steps.length - 1);
    const route = `${input.kind ? `${input.kind} ` : ''}${jumps}j: ${condenseRoute(input.steps, input.hops)}`;
    const holes = holesOnRoute(input);
    const mass = massLine(input, holes);
    // Patch 32: no chokepoints on a frigate-only route (mass doesn't matter there).
    const frigateOnly = holes.some((entry) => isFrigateHole(entry.hole.maxJumpMass));
    const chokes = frigateOnly ? [] : chokepoints(holes).map(chokeText);
    const risks = holes
        .map((entry) => riskText(entry, input.now))
        .filter((text): text is string => text !== null)
        .slice(0, FLEET_RULES.maxRisks);
    const build = (c: string[], r: string[], m: string | null, e: string | null) =>
        [route, m, c.length ? `Choke: ${c.join(', ')}` : null, r.length ? `Risk: ${r.join(', ')}` : null, e].filter(Boolean).join(' | ');
    // Drop detail until it fits: extra chokepoints, extra risks, the extra line, then the mass line.
    const attempts: [string[], string[], string | null, string | null][] = [
        [chokes, risks, mass, extra],
        [chokes.slice(0, 1), risks, mass, extra],
        [chokes.slice(0, 1), risks.slice(0, 1), mass, extra],
        [chokes.slice(0, 1), risks.slice(0, 1), mass, null],
        [chokes.slice(0, 1), [], null, null],
        [[], [], null, null],
    ];
    for (const attempt of attempts) {
        const text = build(...attempt);
        if (text.length <= FLEET_RULES.maxCopyLength) return text;
    }
    return route.slice(0, FLEET_RULES.maxCopyLength);
}

/** Experimental: the facts about each risky hole, no verdicts. */
export function holdFacts(input: TRouteInput): string {
    const holes = holesOnRoute(input);
    const choke = new Set(chokepoints(holes).map((entry) => entry.index));
    const { shipMass } = FLEET_RULES;
    const facts: string[] = [];
    for (const entry of holes) {
        const status = entry.hole.massStatus;
        const left = eolMinutesLeft(entry.hole, input.now);
        const risky = choke.has(entry.index) || status === 'reduced' || status === 'critical' || left !== null;
        if (!risky) continue;
        const parts: string[] = [];
        if (entry.estimate) {
            const low = share(input, entry.estimate.min);
            const high = share(input, entry.estimate.max);
            parts.push(`${status && status !== 'fresh' ? `${status}: ` : ''}${mill(low)}–${mill(high)} kg left${input.roundTrip ? ' (in+out)' : ''}`);
            const capitalFits = entry.hole.maxJumpMass === null || entry.hole.maxJumpMass >= shipMass.capital;
            parts.push(`fits ${Math.floor(low / shipMass.battleship)} BS worst case, ${Math.floor(high / shipMass.battleship)} best case`);
            if (capitalFits) parts.push(low >= shipMass.capital ? 'a capital fits' : high >= shipMass.capital ? 'a capital only best case (may collapse it)' : 'no capital');
        } else if (status && status !== 'fresh') {
            parts.push(`${status}, type unknown`);
        }
        if (left !== null) parts.push(left > 0 ? `EOL worst case ≤ ${duration(left)}` : 'EOL, may be gone');
        facts.push(`${entry.label}${entry.hole.typeName ? ` "${entry.hole.typeName}"` : ''}: ${parts.join('; ')}`);
    }
    const head = `Will it hold? (experimental${input.roundTrip ? ', in+out' : ', one way'})`;
    const text = facts.length ? `${head} ${facts.join(' | ')}` : `${head} Nothing risky known on this route.`;
    return text.length <= FLEET_RULES.maxCopyLength ? text : `${text.slice(0, FLEET_RULES.maxCopyLength - 1)}…`;
}

export type TScanCandidate = { name: string; unscannedSigs: number; unfoundStatics: string[] };

/** Experimental: where to scan if the chokepoint goes (candidates closest to it first). */
export function scanPlan(candidates: readonly TScanCandidate[], noBackup: boolean): string {
    const picks = candidates
        .filter((candidate) => candidate.unscannedSigs > 0 || candidate.unfoundStatics.length > 0)
        .slice(0, FLEET_RULES.maxScanTargets)
        .map((candidate) => {
            const why = [
                candidate.unscannedSigs ? `${candidate.unscannedSigs} unscanned sig${candidate.unscannedSigs === 1 ? '' : 's'}` : null,
                ...candidate.unfoundStatics.map((leadsTo) => `${leadsTo.toUpperCase()} static not found`),
            ].filter(Boolean);
            return `${candidate.name} (${why.join(', ')})`;
        });
    const head = noBackup ? 'No backup route. ' : '';
    return picks.length ? `${head}Scan (experimental): ${picks.join(', ')}` : `${head}Scan (experimental): nothing unscanned near the chokepoint.`;
}
