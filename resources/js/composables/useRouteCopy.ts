import { useRoutingSetup } from '@/composables/routing/useRoutingSetup';
import { useNavigationSystems } from '@/composables/useNavigationSystems';
import { findRoute } from '@/composables/useRoutingWorker';
import { useShowMap } from '@/composables/useShowMap';
import { useStaticSolarsystems } from '@/composables/useStaticSolarsystems';
import { displayAlias } from '@/lib/alias';
import { FLEET_RULES } from '@/lib/fleetRules';
import { holdFacts, routeSummary, scanPlan, type TRouteHole, type TRouteHop, type TRouteInput, type TRouteStep, type TScanCandidate } from '@/lib/routeSummary';
import { wormholeMass } from '@/lib/wormholeMass';
import { tryUseMapStore } from '@/map/store/mapStore';
import type { TMapConnection } from '@/pages/maps';
import type { RouteStep, RoutingConnection, RoutingSettings } from '@/routing/types';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Patch 26: copy a route for fleet chat (see routeSummary): Default (your route settings),
 * Shortest and Safest (frigate-only holes skipped), and a backup for each (the next route by
 * jumps once one of the first route's holes is gone). Plus the experimental "Will it hold?"
 * and "Scan plan". From is the route planner's From (home while it's empty).
 */
export type TRouteKind = 'default' | 'shortest' | 'shortestBackup' | 'safest' | 'safestBackup';

export const ROUTE_KIND_LABELS: Record<TRouteKind, string> = {
    default: 'Default',
    shortest: 'Shortest',
    shortestBackup: 'Shortest backup',
    safest: 'Safest',
    safestBackup: 'Safest backup',
};

/** Patch 29c: what a rally ping includes. */
export type TPingPicks = { kinds: TRouteKind[]; roundTrip: boolean; hold: boolean; scan: boolean };
export type TPingSection = { title: string; text: string };

const ROUND_TRIP_KEY = 'route-copy-round-trip';
function readRoundTrip(): boolean {
    try {
        return window.localStorage.getItem(ROUND_TRIP_KEY) === '1';
    } catch {
        return false;
    }
}
/** In and back out the same way (half the mass); one way by default. Remembered in this browser. */
const roundTrip = ref(readRoundTrip());
watch(roundTrip, (value) => {
    try {
        window.localStorage.setItem(ROUND_TRIP_KEY, value ? '1' : '0');
    } catch {
        // Storage unavailable: it still works for this visit.
    }
});

const SHIP_SIZE_MAX_JUMP: Record<string, number> = {
    frigate: 5_000_000,
    medium: 62_000_000,
    large: 375_000_000,
    xlarge: 2_000_000_000,
};

export function useRouteCopy() {
    const page = useShowMap();
    const { getSolarsystemById, resolveSolarsystem } = useStaticSolarsystems();
    const { fromSystemId, toSystemId } = useNavigationSystems();
    const mapConnections = computed(() => page.props.map.map_connections ?? []);
    const mapSolarsystems = computed(() =>
        (page.props.map.map_solarsystems ?? []).map((system) => ({ ...system, solarsystem: resolveSolarsystem(system.solarsystem_id) })),
    );
    const { routingSettings, getConnections } = useRoutingSetup({ mapConnections, mapSolarsystems });

    const bySolarsystem = computed(() => new Map(mapSolarsystems.value.map((system) => [system.solarsystem_id, system])));
    const homeId = computed(() => page.props.map.home_solarsystem_id ?? null);
    const originId = computed(() => fromSystemId.value ?? homeId.value);

    function nameOf(solarsystemId: number): string {
        return displayAlias(bySolarsystem.value.get(solarsystemId)?.alias) || getSolarsystemById(solarsystemId)?.name || String(solarsystemId);
    }

    /** Jumps from home over the map, per solar system id. */
    const depths = computed(() => {
        const result = new Map<number, number>();
        const home = homeId.value;
        if (!home || !bySolarsystem.value.has(home)) return result;
        const toSolar = new Map(mapSolarsystems.value.map((system) => [system.id, system.solarsystem_id]));
        const neighbours = new Map<number, number[]>();
        for (const connection of mapConnections.value) {
            const a = toSolar.get(connection.from_map_solarsystem_id);
            const b = toSolar.get(connection.to_map_solarsystem_id);
            if (!a || !b) continue;
            neighbours.set(a, [...(neighbours.get(a) ?? []), b]);
            neighbours.set(b, [...(neighbours.get(b) ?? []), a]);
        }
        result.set(home, 0);
        const queue = [home];
        for (let index = 0; index < queue.length; index++) {
            const current = queue[index];
            for (const next of neighbours.get(current) ?? []) {
                if (result.has(next)) continue;
                result.set(next, result.get(current)! + 1);
                queue.push(next);
            }
        }
        return result;
    });

    function connectionBetween(from: number, to: number): TMapConnection | null {
        const near = bySolarsystem.value.get(from);
        const far = bySolarsystem.value.get(to);
        if (!near || !far) return null;
        return (
            mapConnections.value.find(
                (candidate) =>
                    (candidate.from_map_solarsystem_id === near.id && candidate.to_map_solarsystem_id === far.id) ||
                    (candidate.from_map_solarsystem_id === far.id && candidate.to_map_solarsystem_id === near.id),
            ) ?? null
        );
    }

    function holeOf(connection: TMapConnection, nearSolarsystemId: number): TRouteHole {
        const near = bySolarsystem.value.get(nearSolarsystemId);
        const signatures = connection.signatures ?? [];
        const typed = signatures.find((signature) => signature.wormhole && !signature.wormhole.name.toUpperCase().startsWith('K162'))?.wormhole ?? null;
        const nearSide = signatures.find((signature) => signature.map_solarsystem_id === near?.id) ?? null;
        return {
            typeName: typed?.name ?? null,
            totalMass: typed?.total_mass ?? wormholeMass(typed?.name)?.total ?? null,
            maxJumpMass: typed?.maximum_jump_mass ?? wormholeMass(typed?.name)?.maxJump ?? (connection.ship_size ? (SHIP_SIZE_MAX_JUMP[connection.ship_size] ?? null) : null),
            jumpedMass: connection.jumps_mass_sum ?? 0,
            massStatus: connection.mass_status ?? null,
            lifetimeStatus: connection.lifetime_status ?? null,
            eolSince: connection.lifetime_status === 'eol' || connection.lifetime_status === 'critical' ? connection.lifetime_status_updated_at : null,
            nearSignature: nearSide?.signature_id ?? null,
        };
    }

    function isFrigateOnly(connection: TMapConnection): boolean {
        if (connection.ship_size === 'frigate') return true;
        const typed = (connection.signatures ?? []).find((signature) => signature.wormhole && !signature.wormhole.name.toUpperCase().startsWith('K162'))?.wormhole;
        const maxJump = typed?.maximum_jump_mass ?? wormholeMass(typed?.name)?.maxJump ?? null;
        return maxJump !== null && maxJump <= 5_000_000;
    }

    function toInput(route: RouteStep[], roundTripOverride?: boolean, kind: TRouteKind | null = null): TRouteInput {
        const steps: TRouteStep[] = route.map((step) => ({
            name: nameOf(step.id),
            mapped: bySolarsystem.value.has(step.id),
            depth: depths.value.get(step.id) ?? null,
            rage: Boolean(bySolarsystem.value.get(step.id)?.combat_color),
        }));
        const hops: TRouteHop[] = route.slice(1).map((step, index) => {
            const from = route[index].id;
            const connection = connectionBetween(from, step.id);
            const via = step.via ?? (connection ? 'wormhole' : 'stargate');
            if (via === 'stargate' || !connection || connection.type === 'stargate') return { via: 'stargate', hole: null };
            return { via, hole: holeOf(connection, from) };
        });
        return { steps, hops, roundTrip: roundTripOverride ?? roundTrip.value, now: new Date(), kind: kind ? ROUTE_KIND_LABELS[kind] : null };
    }

    const sameEdge = (edge: RoutingConnection, a: number, b: number) => (edge.from === a && edge.to === b) || (edge.from === b && edge.to === a);

    async function search(settings: RoutingSettings, from: number, to: number, skipFrigates: boolean, without: [number, number][] = []): Promise<RouteStep[]> {
        const { dynamicConnections, eveScoutConnections } = getConnections();
        const frigateOnly = skipFrigates ? mapConnections.value.filter(isFrigateOnly) : [];
        const toSolar = new Map(mapSolarsystems.value.map((system) => [system.id, system.solarsystem_id]));
        const blocked: [number, number][] = [
            ...without,
            ...frigateOnly.map((connection) => [toSolar.get(connection.from_map_solarsystem_id) ?? 0, toSolar.get(connection.to_map_solarsystem_id) ?? 0] as [number, number]),
        ];
        const edges = dynamicConnections.filter((edge) => !blocked.some(([a, b]) => sameEdge(edge, a, b)));
        const result = await findRoute({ ...settings }, from, to, edges, eveScoutConnections, []);
        return result.route;
    }

    /** The route for a kind (null: none); `backup` also says when a backup was asked for but none exists. */
    async function routeFor(kind: TRouteKind, from: number, to: number): Promise<{ route: RouteStep[] | null; noBackup: boolean }> {
        const base = routingSettings.value;
        const settings: RoutingSettings =
            kind === 'shortest' || kind === 'shortestBackup' ? { ...base, routePreference: 'shorter' } : kind === 'safest' || kind === 'safestBackup' ? { ...base, routePreference: 'safer' } : base;
        const skipFrigates = kind !== 'default';
        const primary = await search(settings, from, to, skipFrigates);
        if (primary.length === 0) return { route: null, noBackup: false };
        if (kind !== 'shortestBackup' && kind !== 'safestBackup') return { route: primary, noBackup: false };

        // The next route by jumps once one of the first route's holes is gone.
        const key = (route: RouteStep[]) => route.map((step) => step.id).join('>');
        const holes: [number, number][] = [];
        primary.slice(1).forEach((step, index) => {
            if (connectionBetween(primary[index].id, step.id)) holes.push([primary[index].id, step.id]);
        });
        let best: RouteStep[] | null = null;
        for (const hole of holes) {
            const other = await search(settings, from, to, skipFrigates, [hole]);
            if (other.length === 0 || key(other) === key(primary)) continue;
            if (!best || other.length < best.length) best = other;
        }
        return { route: best, noBackup: best === null };
    }

    /** Systems on the near side of the route's tightest hole, closest to it first. */
    function scanCandidates(route: RouteStep[]): TScanCandidate[] {
        const input = toInput(route);
        let chokeIndex = -1;
        let tightest = Infinity;
        input.hops.forEach((hop, index) => {
            if (!hop.hole?.totalMass) return;
            const left = hop.hole.totalMass * 1.1 - hop.hole.jumpedMass;
            if (left < tightest) {
                tightest = left;
                chokeIndex = index;
            }
        });
        const nearSide = (chokeIndex >= 0 ? route.slice(0, chokeIndex + 1) : route).filter((step) => bySolarsystem.value.has(step.id)).reverse();
        const placeholders = tryUseMapStore()?.allPlaceholders.value ?? [];
        return nearSide.map((step) => {
            const system = bySolarsystem.value.get(step.id)!;
            return {
                name: nameOf(step.id),
                unscannedSigs: system.uncategorized_signatures_count ?? 0,
                unfoundStatics: placeholders.filter((placeholder) => placeholder.expected && placeholder.parentId === system.id).map((placeholder) => placeholder.wormhole ?? 'a'),
            };
        });
    }

    async function writeOut(title: string, text: string): Promise<void> {
        try {
            await navigator.clipboard.writeText(text);
            toast.success(`${title} copied (${text.length}/${FLEET_RULES.maxCopyLength})`, { description: text, duration: 8000 });
        } catch {
            toast.info(title, { description: text, duration: 15_000 });
        }
    }

    function endpoints(to: number | null = toSystemId.value): { from: number; to: number } | null {
        const from = originId.value;
        if (!from || !to) {
            toast.warning('Pick where to go first', { description: 'Routing → To (From is home while it is empty).' });
            return null;
        }
        return { from, to };
    }

    async function copyRoute(kind: TRouteKind, to: number | null = toSystemId.value, who: string | null = null): Promise<void> {
        const ends = endpoints(to);
        if (!ends) return;
        const label = who ? `Route to ${who}` : `${ROUTE_KIND_LABELS[kind]} route`;
        if (ends.from === ends.to) return writeOut(label, nameOf(ends.from));
        const { route, noBackup } = await routeFor(kind, ends.from, ends.to);
        if (!route) {
            const primary = noBackup ? (await routeFor(kind === 'shortestBackup' ? 'shortest' : 'safest', ends.from, ends.to)).route : null;
            if (noBackup && primary) return writeOut(`${label}`, scanPlan(scanCandidates(primary), true));
            toast.warning(`No route to ${who ?? nameOf(ends.to)}`, { description: 'Nothing on the map or by gate links From (or home) to it.' });
            return;
        }
        await writeOut(label, routeSummary(toInput(route, undefined, kind)));
    }

    /** Patch 29: the route text without copying it (the rally ping posts it); null when there is no route. */
    async function routeText(kind: TRouteKind, to: number | null): Promise<string | null> {
        const from = originId.value;
        if (!from || !to) return null;
        if (from === to) return nameOf(from);
        const { route } = await routeFor(kind, from, to);
        return route ? routeSummary(toInput(route, undefined, kind)) : null;
    }

    /**
     * Patch 29c: the sections a rally ping posts (one per ticked box), from Routing's From (or
     * home) to `to`. `roundTrip` here is the ping's own box, not the copy buttons' setting.
     */
    async function pingSections(to: number | null, picks: TPingPicks): Promise<TPingSection[]> {
        const from = originId.value;
        if (!from || !to) return [];
        if (from === to) return [{ title: 'Route', text: `${nameOf(from)} (you are there)` }];
        const sections: TPingSection[] = [];
        for (const kind of picks.kinds) {
            const { route, noBackup } = await routeFor(kind, from, to);
            const title = `${ROUTE_KIND_LABELS[kind]} route`;
            if (route) sections.push({ title, text: routeSummary(toInput(route, picks.roundTrip, kind)) });
            else sections.push({ title, text: noBackup ? 'No backup: every other way shares a hole with the first route.' : 'No route found.' });
        }
        if (picks.hold || picks.scan) {
            const { route } = await routeFor('default', from, to);
            if (route && picks.hold) sections.push({ title: 'Will it hold? (experimental)', text: holdFacts(toInput(route, picks.roundTrip)) });
            if (route && picks.scan) {
                const backup = await routeFor('shortestBackup', from, to);
                sections.push({ title: 'Scan plan (experimental)', text: scanPlan(scanCandidates(route), backup.noBackup) });
            }
        }
        return sections;
    }

    async function copyHoldFacts(): Promise<void> {
        const ends = endpoints();
        if (!ends) return;
        const { route } = await routeFor('default', ends.from, ends.to);
        if (!route) return void toast.warning('No route', { description: 'Nothing links From (or home) to To.' });
        await writeOut('Will it hold? (experimental)', holdFacts(toInput(route)));
    }

    async function copyScanPlan(): Promise<void> {
        const ends = endpoints();
        if (!ends) return;
        const { route } = await routeFor('default', ends.from, ends.to);
        if (!route) return void toast.warning('No route', { description: 'Nothing links From (or home) to To.' });
        const backup = await routeFor('shortestBackup', ends.from, ends.to);
        await writeOut('Scan plan (experimental)', scanPlan(scanCandidates(route), backup.noBackup));
    }

    return { roundTrip, nameOf, copyRoute, routeText, pingSections, copyHoldFacts, copyScanPlan, canCopy: computed(() => Boolean(originId.value && toSystemId.value)) };
}
