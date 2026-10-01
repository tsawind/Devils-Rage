import { computeBandLayout, isLoopEdge, type BandLayoutInput, type BandLayoutResult } from '@/map/core/layout/bandLayout';
import { buildPlaceholders, foldLaneHoles, type TPlaceholder } from '@/lib/placeholders';
import { compareSystems } from '@/map/core/sorting';
import type { Vec2 } from '@/map/core/types';
import { TMap, TMapConnection, TMapSolarsystem, TSolarsystem } from '@/pages/maps';
import { computed, type ComputedRef, type Ref, type ShallowRef } from 'vue';
import type { EntityState } from './entities';
import type { ViewState } from './viewState';

/** Map metadata without the entity collections (those live in the keyed Maps). */
export type TMapMeta = Omit<TMap, 'map_solarsystems' | 'map_connections'>;

export type TResolvedConnection = {
    connection: TMapConnection;
    source: TMapSolarsystem;
    target: TMapSolarsystem;
};

/**
 * External route state the store derives edge highlighting from. Injected by the
 * component root (where the routing composables are available) and optional so
 * the store stays testable without the routing worker.
 */
export type RouteDeps = {
    path?: Readonly<Ref<readonly TSolarsystem[] | null>>;
    getRallyRouteInfo?: (fromSolarsystemId: number, toSolarsystemId: number) => { onRoute: boolean; reversed: boolean };
};

export type DerivedState = ReturnType<typeof createDerivedState>;

export function createDerivedState(entities: EntityState, view: ViewState, meta: ShallowRef<TMapMeta | null>, routeDeps: RouteDeps = {}) {
    const effectiveLayout = computed<'manual' | 'tree'>(() => {
        const metaValue = meta.value;
        if (!metaValue) return 'manual';
        if (metaValue.allow_layout_override && view.userLayoutOverride.value) {
            return view.userLayoutOverride.value;
        }
        return metaValue.layout;
    });

    const isTreeLayout = computed(() => effectiveLayout.value === 'tree');

    /**
     * Auto layouts position nodes for you, so manual dragging and the selection
     * marquee are disabled while one is active. Derives from the tree flag so
     * there is no second source of truth to keep in sync.
     */
    const isLayoutLocked = isTreeLayout;

    const isConstantWidthEnabled = computed(() => meta.value?.constant_width_enabled ?? false);

    /** Unjumped wormhole signatures shown as placeholder systems (tree layout, when switched on). */
    const allPlaceholders: ComputedRef<TPlaceholder[]> = computed(() => {
        if (!meta.value || !isTreeLayout.value || !view.showPlaceholders.value) return [];
        const linked = new Set<number>();
        for (const connection of entities.connections.values()) {
            for (const signature of connection.signatures ?? []) linked.add(signature.id);
        }
        const systems = [...entities.systems.values()];
        const connections = [...entities.connections.values()];
        const homeSolarsystemId = meta.value.home_solarsystem_id;
        const homeId = homeSolarsystemId !== null ? (systems.find((system) => system.solarsystem_id === homeSolarsystemId)?.id ?? null) : null;
        return buildPlaceholders(systems, meta.value, linked, { connections, parentOf: chainParents(systems, connections, homeId), homeId });
    });

    /**
     * Patch 15: in rage lanes a system's unjumped holes fold into a chip;
     * armed ones, the system you're in and opened systems keep theirs.
     */
    const foldedPlaceholders = computed(() =>
        foldLaneHoles(
            allPlaceholders.value,
            (parentId) => Boolean(entities.systems.get(parentId)?.combat_color),
            view.openedHoleParents.value,
            view.currentSystemId.value,
        ),
    );
    const placeholders: ComputedRef<TPlaceholder[]> = computed(() => foldedPlaceholders.value.visible);
    /** Rage-lane system id → how many unjumped holes are folded into its chip. */
    const foldedHoles: ComputedRef<Map<number, number>> = computed(() => foldedPlaceholders.value.folded);

    /**
     * The band layout (patch 12): main band, side chains, combat lanes. Always
     * computed (cheap) since the side chains also drive the side-chain letters;
     * its positions are only used in the tree layout. Recomputed only when the
     * structure changes (systems, connections, pins, home, colors) — live drag
     * positions and node sizes don't feed in, so it stays out of hot paths.
     */
    const bandLayout: ComputedRef<BandLayoutResult | null> = computed(() => {
        if (!meta.value) return null;
        return computeBandLayout(toBandInput(entities, meta.value, placeholders.value), {
            gridSize: view.config.value.grid_size,
            // The tree layout always draws nodes at the fixed 180 width (see MapNode).
            nodeWidth: 180,
            // Patch 15: rage lanes use readable full cards (alias, class, statics, a pilot).
            laneNodeWidth: 180,
            laneNodeHeight: 60,
            laneColumnGap: 300,
            laneRowGap: 80,
        });
    });

    /** Base-unit tree positions: the band layout's, when the tree layout is active. */
    const treePositions: ComputedRef<Map<number, Vec2> | null> = computed(() => {
        if (!isTreeLayout.value) return null;
        return bandLayout.value?.positions ?? null;
    });

    /** Connections that aren't how either end was found: drawn as dashed loop lines (patch 12). */
    const loopConnectionIds: ComputedRef<ReadonlySet<number>> = computed(() => {
        const ids = new Set<number>();
        const layout = bandLayout.value;
        if (!layout) return ids;
        for (const connection of entities.connections.values()) {
            if (connection.type === 'stargate') continue;
            if (isLoopEdge(layout.parentOf, connection.from_map_solarsystem_id, connection.to_map_solarsystem_id)) ids.add(connection.id);
        }
        return ids;
    });

    /** The anchor a node renders at: the auto layout when active, else the live position. */
    function renderPosition(id: number): Vec2 | null {
        return treePositions.value?.get(id) ?? entities.positions.get(id) ?? null;
    }

    function resolveConnection(id: number): TResolvedConnection | null {
        const connection = entities.connections.get(id);
        if (!connection) return null;
        const source = entities.systems.get(connection.from_map_solarsystem_id);
        const target = entities.systems.get(connection.to_map_solarsystem_id);
        if (!source || !target) return null;
        return { connection, source, target };
    }

    /** How many map connections each system has (for spotting dead ends). */
    const connectionCounts: ComputedRef<ReadonlyMap<number, number>> = computed(() => {
        const counts = new Map<number, number>();
        for (const connection of entities.connections.values()) {
            counts.set(connection.from_map_solarsystem_id, (counts.get(connection.from_map_solarsystem_id) ?? 0) + 1);
            counts.set(connection.to_map_solarsystem_id, (counts.get(connection.to_map_solarsystem_id) ?? 0) + 1);
        }
        return counts;
    });

    /** Connections joining two adjacent systems on the active route. */
    const routeConnectionIds: ComputedRef<ReadonlySet<number>> = computed(() => {
        const path = routeDeps.path?.value;
        const ids = new Set<number>();
        if (!path || path.length < 2) return ids;
        const indexBySolarsystem = new Map(path.map((solarsystem, index) => [solarsystem.id, index]));
        for (const connection of entities.connections.values()) {
            const source = entities.systems.get(connection.from_map_solarsystem_id);
            const target = entities.systems.get(connection.to_map_solarsystem_id);
            if (!source || !target) continue;
            const fromIndex = indexBySolarsystem.get(source.solarsystem_id);
            const toIndex = indexBySolarsystem.get(target.solarsystem_id);
            if (fromIndex !== undefined && toIndex !== undefined && Math.abs(fromIndex - toIndex) === 1) {
                ids.add(connection.id);
            }
        }
        return ids;
    });

    /** Connections on the home→rally route, with the direction the animation runs. */
    const rallyEdgeDirections: ComputedRef<ReadonlyMap<number, 'forward' | 'reverse'>> = computed(() => {
        const directions = new Map<number, 'forward' | 'reverse'>();
        const getRallyRouteInfo = routeDeps.getRallyRouteInfo;
        if (!getRallyRouteInfo) return directions;
        for (const connection of entities.connections.values()) {
            const source = entities.systems.get(connection.from_map_solarsystem_id);
            const target = entities.systems.get(connection.to_map_solarsystem_id);
            if (!source || !target) continue;
            const info = getRallyRouteInfo(source.solarsystem_id, target.solarsystem_id);
            if (info.onRoute) {
                directions.set(connection.id, info.reversed ? 'reverse' : 'forward');
            }
        }
        return directions;
    });

    return {
        effectiveLayout,
        isTreeLayout,
        isLayoutLocked,
        isConstantWidthEnabled,
        bandLayout,
        placeholders,
        foldedHoles,
        loopConnectionIds,
        treePositions,
        renderPosition,
        resolveConnection,
        connectionCounts,
        routeConnectionIds,
        rallyEdgeDirections,
    };
}

/**
 * Who each system was reached from: a breadth-first walk out from home, then
 * from the remaining systems (lowest id first). The band layout has its own
 * tree, but it takes the placeholders as input, so this can't use it.
 */
function chainParents(systems: readonly TMapSolarsystem[], connections: readonly TMapConnection[], homeId: number | null): Map<number, number> {
    const neighbours = new Map<number, number[]>();
    for (const connection of connections) {
        const a = connection.from_map_solarsystem_id;
        const b = connection.to_map_solarsystem_id;
        if (!neighbours.has(a)) neighbours.set(a, []);
        if (!neighbours.has(b)) neighbours.set(b, []);
        neighbours.get(a)!.push(b);
        neighbours.get(b)!.push(a);
    }
    const parentOf = new Map<number, number>();
    const seen = new Set<number>();
    const roots = [...(homeId !== null ? [homeId] : []), ...systems.map((system) => system.id).toSorted((a, b) => a - b)];
    for (const root of roots) {
        if (seen.has(root)) continue;
        seen.add(root);
        const queue = [root];
        while (queue.length) {
            const current = queue.shift()!;
            for (const next of (neighbours.get(current) ?? []).toSorted((a, b) => a - b)) {
                if (seen.has(next)) continue;
                seen.add(next);
                parentOf.set(next, current);
                queue.push(next);
            }
        }
    }
    return parentOf;
}

/** Translates the entity maps into the structural input the band layout needs. */
function toBandInput(entities: EntityState, metaValue: TMapMeta, placeholders: readonly TPlaceholder[]): BandLayoutInput {
    const systems = [...entities.systems.values()];
    const systemsById = entities.systems;
    const homeSolarsystemId = metaValue.home_solarsystem_id;
    const homeId = homeSolarsystemId !== null ? (systems.find((system) => system.solarsystem_id === homeSolarsystemId)?.id ?? null) : null;

    // Lanes in the order the chains were started.
    const laneOrder = systems
        .filter((system) => system.combat_home && system.combat_color)
        .toSorted((a, b) => (a.combat_started_at ?? '').localeCompare(b.combat_started_at ?? '') || a.id - b.id)
        .map((system) => system.combat_color as string);

    return {
        nodes: [
            ...systems.map((system) => ({
                id: system.id,
                alias: system.alias,
                color: system.combat_color ?? null,
                home: Boolean(system.combat_home),
                pinned: Boolean(system.pinned),
            })),
            // Placeholders sit where the system will appear, in their parent's chain.
            ...placeholders.map((placeholder) => ({
                id: placeholder.nodeId,
                alias: placeholder.alias,
                color: placeholder.color,
                placeholder: true,
                armed: Boolean(placeholder.armedBy),
            })),
        ],
        edges: [
            ...[...entities.connections.values()].map((connection) => ({
                from: connection.from_map_solarsystem_id,
                to: connection.to_map_solarsystem_id,
            })),
            ...placeholders.map((placeholder) => ({ from: placeholder.parentId, to: placeholder.nodeId })),
        ],
        homeId,
        laneOrder,
        // Daisy's static is Alpha: its row is kept free (numeric scheme only).
        reservedAlias: metaValue.bookmark_alias_scheme === 'alphabetical' ? null : 'A',
        compareNodes: (a: number, b: number): number => {
            const systemA = systemsById.get(a);
            const systemB = systemsById.get(b);
            if (!systemA || !systemB) return 0;
            return compareSystems(systemA, systemB);
        },
    };
}
