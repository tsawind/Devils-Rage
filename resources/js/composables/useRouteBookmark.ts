import { useRoutingSetup } from '@/composables/routing/useRoutingSetup';
import { useNavigationSystems } from '@/composables/useNavigationSystems';
import { findRoute } from '@/composables/useRoutingWorker';
import { useShowMap } from '@/composables/useShowMap';
import { useStaticSolarsystems } from '@/composables/useStaticSolarsystems';
import { displayAlias } from '@/lib/alias';
import { estimateMass } from '@/lib/massEstimate';
import { routeBookmark, type TRouteHole } from '@/lib/routeBookmark';
import { classCode } from '@/lib/staticCertainty';
import { toast } from 'vue-sonner';
import { wormholeMass } from '@/lib/wormholeMass';
import { computed } from 'vue';

/**
 * Patch 25: copy the route from the origin (Routing's From; the map's home while it's empty) to a system: the chain,
 * the way out, the mass the whole way can take and its chokepoint (see routeBookmark).
 * Only on a map page.
 */
export function useRouteBookmark() {
    const page = useShowMap();
    const { getSolarsystemById, resolveSolarsystem } = useStaticSolarsystems();
    const mapConnections = computed(() => page.props.map.map_connections ?? []);
    // The routing setup wants the map's systems with their static data, like the map page has them.
    const mapSolarsystems = computed(() =>
        (page.props.map.map_solarsystems ?? []).map((system) => ({ ...system, solarsystem: resolveSolarsystem(system.solarsystem_id) })),
    );
    const { routingSettings, getConnections } = useRoutingSetup({ mapConnections, mapSolarsystems });

    const bySolarsystem = computed(() => new Map(mapSolarsystems.value.map((system) => [system.solarsystem_id, system])));

    function nameOf(solarsystemId: number): string {
        const alias = bySolarsystem.value.get(solarsystemId)?.alias;
        return displayAlias(alias) || getSolarsystemById(solarsystemId)?.name || String(solarsystemId);
    }

    function holeBetween(from: number, to: number): TRouteHole | null {
        const near = bySolarsystem.value.get(from);
        const far = bySolarsystem.value.get(to);
        if (!near || !far) return null;
        const connection = mapConnections.value.find(
            (candidate) =>
                (candidate.from_map_solarsystem_id === near.id && candidate.to_map_solarsystem_id === far.id) ||
                (candidate.from_map_solarsystem_id === far.id && candidate.to_map_solarsystem_id === near.id),
        );
        if (!connection || connection.type === 'stargate') return null;
        const signatures = connection.signatures ?? [];
        const typed = signatures.find((signature) => signature.wormhole && !signature.wormhole.name.toUpperCase().startsWith('K162'))?.wormhole ?? null;
        const total = typed?.total_mass ?? wormholeMass(typed?.name)?.total ?? null;
        const nearSide = signatures.find((signature) => signature.map_solarsystem_id === near.id) ?? null;
        return {
            typeName: typed?.name ?? null,
            estimate: total ? estimateMass({ totalMass: total, jumped: connection.jumps_mass_sum, status: connection.mass_status }) : null,
            nearSignature: nearSide?.signature_id ?? null,
            nearIsStatic: Boolean(nearSide?.is_static),
        };
    }

    // Patch 25: the origin is the route planner's From (Routing box); home while it's empty.
    const { fromSystemId } = useNavigationSystems();

    async function routeTo(solarsystemId: number, originId: number | null = fromSystemId.value ?? page.props.map.home_solarsystem_id): Promise<string | null> {
        if (!originId) return null;
        if (originId === solarsystemId) return nameOf(originId);
        const { dynamicConnections, eveScoutConnections } = getConnections();
        const result = await findRoute({ ...routingSettings.value }, originId, solarsystemId, dynamicConnections, eveScoutConnections, []);
        if (result.route.length === 0) return null;
        return routeBookmark({
            steps: result.route.map((step) => step.id),
            nameOf,
            isMapped: (id) => bySolarsystem.value.has(id),
            classOf: (id) => classCode(getSolarsystemById(id)?.class ?? null),
            holeBetween,
        });
    }

    async function copyRouteTo(solarsystemId: number, who: string): Promise<void> {
        const text = await routeTo(solarsystemId);
        if (!text) {
            toast.warning(`No route to ${who}`, { description: 'Nothing on the map or by gate links the origin (Routing → From, or home) to where they are.' });
            return;
        }
        try {
            await navigator.clipboard.writeText(text);
            toast.success(`Route to ${who} copied`, { description: text });
        } catch {
            toast.info(`Route to ${who}`, { description: text, duration: 15_000 });
        }
    }

    return { nameOf, copyRouteTo };
}
