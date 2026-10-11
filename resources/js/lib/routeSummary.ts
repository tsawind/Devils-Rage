import { collapseText, collapseWindow, type TCollapseWindow } from '@/lib/collapseWindow';
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
    /** Patch 37: the real system name of a mapped system (shown where the gates start: "A324-0 Turba"). */
    system?: string | null;
    /** Patch 37: the solar system id, for in-game links (Notepad, mail). */
    id?: number | null;
};

/**
 * Patch 37: how the copy is written.
 * - `layout`: 'line' = one line for fleet chat; 'lines' = one real system per line, so a
 *   double-click selects it for EVE's Auto Link (fleet chat keeps the line breaks).
 * - `links`: 'notepad' wraps systems in EVE link codes (paste in the in-game Notepad, then
 *   Copy Selected With Formatting into chat); 'mail' writes EVE mail links and line breaks.
 */
export type TRouteFormat = { layout?: 'line' | 'lines'; links?: 'none' | 'notepad' | 'mail' };

/** Patch 37: the EVE-Scout bookmark folder, exactly as it is named in game. */
export const EVE_SCOUT_FOLDER = 'EvE-Scout \\\\ Hubs';

export type TRouteHole = {
    typeName: string | null;
    /** The hole's total mass when new; null when the type isn't known. */
    totalMass: number | null;
    /** The most one ship may weigh per jump; null when not known. */
    maxJumpMass: number | null;
    jumpedMass: number;
    /** Patch 33: logged since the mass status was set (null: same as jumpedMass). */
    jumpedSinceStatus?: number | null;
    massStatus: string | null;
    lifetimeStatus: string | null;
    /** When it was first marked end-of-life (ISO), if it is. */
    eolSince: string | null;
    /** Patch 37: first marked Critical, and the newest look (a life mark or ✓ Checked in game). */
    criticalSince?: string | null;
    /** Patch 37: when it turned "closure imminent". */
    imminentSince?: string | null;
    lastLookAt?: string | null;
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

type TRouteEntry = {
    step: TRouteStep;
    label: string;
    /** The real system to link (k-space names; map names link to their system too). */
    linkName: string | null;
    homeward: boolean;
    exitSig: string | null;
    /** How the route leaves it for the next written stop. */
    leave: { kind: 'gates'; count: number } | { kind: 'evescout' } | { kind: 'hole' } | null;
};

/** Which steps a fleet needs written down, and how the route goes from one to the next. */
export function routeEntries(steps: readonly TRouteStep[], hops: readonly TRouteHop[]): TRouteEntry[] {
    if (steps.length === 0) return [];
    const depth = (index: number) => steps[index].depth ?? Infinity;
    const keep = steps.map((_, index) => index === 0 || index === steps.length - 1);
    for (let index = 1; index < steps.length - 1; index++) {
        const before = hops[index - 1]?.via === 'stargate' ? 'gate' : 'hole';
        const after = hops[index]?.via === 'stargate' ? 'gate' : 'hole';
        const step = steps[index];
        if (before !== after) keep[index] = true; // into or out of k-space by gate
        // Leaving / entering the map through a hole. Patch 37: by gate it isn't written down, the
        // gate jumps are counted in one go ("A324-0 Turba → 15j → Jita", not "→ 1j → Yooh → 14j →").
        if ((step.mapped !== steps[index + 1].mapped && after === 'hole') || (step.mapped !== steps[index - 1].mapped && before === 'hole')) keep[index] = true;
        // Patch 37: both ends of an EVE-Scout hole (Thera / Turnur) are always written down.
        if (hops[index - 1]?.via === 'evescout' || hops[index]?.via === 'evescout') keep[index] = true;
        if (step.rage && (!steps[index - 1].rage || !steps[index + 1].rage)) keep[index] = true; // a rage run's first and last
        const up = depth(index) < depth(index - 1);
        const nextUp = depth(index + 1) < depth(index);
        if (step.mapped && up !== nextUp) keep[index] = true; // turns from home-ward to out-ward (or back)
    }
    const kept = steps.map((_, index) => index).filter((index) => keep[index]);
    return kept.map((index, position) => {
        const step = steps[index];
        const next = kept[position + 1];
        const between = next === undefined ? [] : hops.slice(index, next);
        const leave: TRouteEntry['leave'] =
            next === undefined
                ? null
                : between.every((hop) => hop?.via === 'stargate')
                  ? { kind: 'gates', count: next - index }
                  : between.some((hop) => hop?.via === 'evescout')
                    ? { kind: 'evescout' }
                    : { kind: 'hole' };
        // Reached by going home-ward: the bookmark there is a way-back one (*).
        const homeward = index > 0 && step.mapped && depth(index) < depth(index - 1);
        const hop = hops[index];
        // The way out of the map into k-space: the hole's signature, so a scout can find it.
        const exitSig = step.mapped && hop?.via !== 'stargate' && steps[index + 1] && !steps[index + 1].mapped ? (hop?.hole?.nearSignature?.slice(0, 3).toUpperCase() ?? null) : null;
        // Patch 37: where the gates start from a mapped system, its real name too ("A324-0 Turba").
        const showsSystem = step.mapped && (hop?.via === 'stargate' || hop?.via === 'evescout') && Boolean(step.system) && step.system !== step.name;
        const label = showsSystem ? `${step.name} ${step.system}` : step.name;
        // A k-space system the fleet sets a destination to: unmapped, or mapped where gates / EVE-Scout start or end.
        const arrivesOutside = hops[index - 1]?.via === 'stargate' || hops[index - 1]?.via === 'evescout';
        const linkName = !step.mapped ? step.name : (showsSystem || arrivesOutside) && step.system ? step.system : null;
        return { step, label, linkName, homeward, exitSig, leave };
    });
}

/** The EVE-Scout hub on a route (Thera or Turnur), when it uses an EVE-Scout hole. */
export function eveScoutHub(steps: readonly TRouteStep[], hops: readonly TRouteHop[]): string | null {
    let hub: string | null = null;
    hops.forEach((hop, index) => {
        if (hop?.via !== 'evescout') return;
        for (const step of [steps[index], steps[index + 1]]) {
            const name = step?.system ?? step?.name ?? '';
            if (/^(thera|turnur)$/i.test(name)) hub = name;
        }
    });
    if (hub) return hub;
    return hops.some((hop) => hop?.via === 'evescout') ? 'Thera / Turnur' : null;
}

/** Patch 37: "bm-folder "EvE-Scout \\ Hubs" via Turnur". */
export function eveScoutNote(hub: string | null): string | null {
    return hub ? `bm-folder "${EVE_SCOUT_FOLDER}" via ${hub}` : null;
}

function linkTo(name: string, id: number | null | undefined, links: TRouteFormat['links']): string {
    if (!id || links === 'none' || links === undefined) return name;
    return links === 'mail' ? `<a href="showinfo:5//${id}">${name}</a>` : `<url=showinfo:5//${id}>${name}</url>`;
}

/** The written route: one line, or one real system per line (patch 37). */
export function renderRoute(entries: readonly TRouteEntry[], format: TRouteFormat = {}): string {
    const links = format.links ?? 'none';
    const text = (entry: TRouteEntry, name: string) => `${entry.homeward ? '*' : ''}${name}${entry.exitSig ? ` (${entry.exitSig})` : ''}`;
    if ((format.layout ?? 'line') === 'line') {
        const parts: string[] = [];
        entries.forEach((entry, index) => {
            // Map names link to their system too, so a click in game shows the real one.
            const name = entry.linkName && entry.label.includes(entry.linkName) ? entry.label.replace(entry.linkName, linkTo(entry.linkName, entry.step.id, links)) : linkTo(entry.label, entry.step.id, links);
            parts.push(text(entry, name));
            const leave = entry.leave;
            if (index === entries.length - 1 || !leave) return;
            parts.push(leave.kind === 'gates' ? ` → ${leave.count}j → ` : leave.kind === 'evescout' ? ' ⇢ES⇢ ' : ' → ');
        });
        return parts.join('');
    }
    // One real system per line: it starts the line, then how you leave it. Map names (J-space)
    // stay together on one line ("Daisy → A1 (GNC)"); a hole into the map continues the line.
    const lines: string[] = [];
    let current = '';
    const add = (piece: string) => {
        current = current ? `${current}${current.endsWith('→') ? ' ' : ' → '}${piece}` : piece;
    };
    entries.forEach((entry, index) => {
        const leave = index === entries.length - 1 ? null : entry.leave;
        if (!entry.linkName) {
            add(text(entry, linkTo(entry.label, entry.step.id, links)));
            return;
        }
        const mapPart = entry.step.mapped && entry.label !== entry.linkName ? entry.label.replace(` ${entry.linkName}`, '') : null;
        if (mapPart) add(text(entry, mapPart));
        if (current) lines.push(current);
        const after = !leave ? '' : leave.kind === 'gates' ? ` · ${leave.count} ${leave.count === 1 ? 'gate' : 'gates'}` : leave.kind === 'evescout' ? ' ⇢ EVE-Scout hole' : ' →';
        current = `${entry.homeward && !mapPart ? '*' : ''}${linkTo(entry.linkName, entry.step.id, links)}${after}`;
        if (leave?.kind !== 'hole') {
            lines.push(current);
            current = '';
        }
    });
    if (current) lines.push(current);
    return lines.join(links === 'mail' ? '<br>' : '\n');
}

/**
 * Patch 37: the in-game waypoints for a route: the end of every gate leg (autopilot can't
 * use holes; you take those yourself and autopilot re-routes to the next waypoint).
 */
export function gateWaypoints(steps: readonly TRouteStep[], hops: readonly TRouteHop[]): number[] {
    const entries = routeEntries(steps, hops);
    const ids: number[] = [];
    entries.forEach((entry, index) => {
        const previous = entries[index - 1];
        if (index > 0 && previous?.leave?.kind === 'gates' && entry.step.id) ids.push(entry.step.id);
    });
    return ids;
}

/** Which steps a fleet needs written down, and which go home-ward (a * way-back bookmark). */
export function condenseRoute(steps: readonly TRouteStep[], hops: readonly TRouteHop[], format: TRouteFormat = {}): string {
    return renderRoute(routeEntries(steps, hops), format);
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
            estimate: hole.totalMass ? estimateMass({ totalMass: hole.totalMass, jumped: hole.jumpedMass, jumpedSinceStatus: hole.jumpedSinceStatus, status: hole.massStatus }) : null,
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
        // Patch 37: battleships as worst ↔ best case; BCs and cruisers as "at least" (worst case +).
        const lowBs = Math.floor(low / shipMass.battleship);
        const highBs = Math.floor(high / shipMass.battleship);
        const counts = [
            fits(shipMass.battleship) ? `${lowBs === highBs ? lowBs : `${lowBs}↔${highBs}`} BS` : null,
            fits(shipMass.battlecruiser) ? `${Math.floor(low / shipMass.battlecruiser)}+ BC` : null,
            fits(shipMass.cruiser) ? `${Math.floor(low / shipMass.cruiser)}+ cruisers` : null,
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

/** Patch 37: when an end-of-life hole on the route collapses (null when it isn't end-of-life or nothing is known). */
export function holeCollapse(hole: TRouteHole, now: Date): TCollapseWindow | null {
    return collapseWindow({
        lifetime: hole.lifetimeStatus,
        eolSince: hole.eolSince,
        criticalSince: hole.criticalSince,
        imminentSince: hole.imminentSince,
        lastLookAt: hole.lastLookAt,
        now: now.getTime(),
    });
}

function riskText(entry: THoleOnRoute, now: Date): string | null {
    // Patch 37: "Daisy↔Alpha EOL, collapsing 14:52–15:32" (EVE time); "scout it" only near the end.
    const window = holeCollapse(entry.hole, now);
    if (window?.imminent) return `${entry.label} ${collapseText(window)}: scout it`;
    if (window) return `${entry.label} ${window.critical ? 'EOL!' : 'EOL'}, ${collapseText(window)}${window.scout ? ': scout it' : ''}`;
    if (entry.hole.lifetimeStatus === 'imminent') return `${entry.label} closing now: scout it`;
    if (entry.hole.lifetimeStatus === 'eol' || entry.hole.lifetimeStatus === 'critical') return `${entry.label} EOL: scout it`;
    if (entry.hole.massStatus === 'critical') return `${entry.label} crit`;
    return null;
}

/** The whole copy for a route, cut to fit FLEET_RULES.maxCopyLength (extras go first, the route stays). */
export function routeSummary(input: TRouteInput, extra: string | null = null, format: TRouteFormat = {}): string {
    // Patch 29d: "Safest 12j: …" (the kind when known, and the total jumps).
    const jumps = Math.max(0, input.steps.length - 1);
    const lines = format.layout === 'lines';
    const head = `${input.kind ? `${input.kind} ` : ''}${jumps}j`;
    const path = condenseRoute(input.steps, input.hops, format);
    const holes = holesOnRoute(input);
    const mass = massLine(input, holes);
    // Patch 37: an EVE-Scout hole on the route: where its bookmarks are.
    const scout = eveScoutNote(eveScoutHub(input.steps, input.hops));
    // Patch 32: no chokepoints on a frigate-only route (mass doesn't matter there).
    const frigateOnly = holes.some((entry) => isFrigateHole(entry.hole.maxJumpMass));
    const chokes = frigateOnly ? [] : chokepoints(holes).map(chokeText);
    const risks = holes
        .map((entry) => riskText(entry, input.now))
        .filter((text): text is string => text !== null)
        .slice(0, FLEET_RULES.maxRisks);
    const build = (c: string[], r: string[], m: string | null, e: string | null) => {
        const notes = [scout, c.length ? `Choke: ${c.join(', ')}` : null, r.length ? `Risk: ${r.join(', ')}` : null, e].filter((part): part is string => Boolean(part));
        if (lines) {
            // Patch 37: one real system per line; the head and the notes on their own lines.
            const breakAt = format.links === 'mail' ? '<br>' : '\n';
            return [[head, m].filter(Boolean).join(' · '), path, ...notes].join(breakAt);
        }
        return [`${head}: ${path}`, m, ...notes].filter(Boolean).join(' | ');
    };
    // Drop detail until it fits (what people see in chat: link codes don't count): extra
    // chokepoints, extra risks, the extra line, then the mass line.
    const visible = (text: string) => text.replace(/<url=[^>]*>|<\/url>|<a [^>]*>|<\/a>/g, '').replace(/<br>/g, '\n').length;
    const limit = format.links === 'mail' ? 7_500 : FLEET_RULES.maxCopyLength;
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
        if (visible(text) <= limit) return text;
    }
    const minimal = build([], [], null, null);
    return visible(minimal) <= limit ? minimal : minimal.slice(0, limit);
}

/** Experimental: the facts about each risky hole, no verdicts. */
export function holdFacts(input: TRouteInput): string {
    const holes = holesOnRoute(input);
    const choke = new Set(chokepoints(holes).map((entry) => entry.index));
    const { shipMass } = FLEET_RULES;
    const facts: string[] = [];
    for (const entry of holes) {
        const status = entry.hole.massStatus;
        const window = holeCollapse(entry.hole, input.now);
        const risky = choke.has(entry.index) || status === 'reduced' || status === 'critical' || window !== null;
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
        if (window) parts.push(window.imminent ? collapseText(window) : `${window.critical ? 'EOL!' : 'EOL'} ${collapseText(window)}`);
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
