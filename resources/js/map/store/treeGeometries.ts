import { nodeRect } from '@/map/core/coords';
import { computeTreeEdgeGeometries } from '@/map/core/geometry/treeRouting';
import type { EdgeGeometry, EdgeInput, Rect, Vec2 } from '@/map/core/types';
import { pipeWidth } from '@/lib/massEstimate';
import { wormholeMass } from '@/lib/wormholeMass';
import type { MapStore } from '@/map/store/mapStore';
import type { TMapConnection } from '@/pages/maps';
import { computed, type ComputedRef } from 'vue';

/**
 * The tree layout's routed pipes, jumped and unjumped (patch 20: one routing
 * pass for both, so the dotted pipes to unjumped holes share the same exits
 * out of a system's top, right and bottom as the jumped ones, and are packed
 * into the same column gaps). Connections keep their own ids; a dotted pipe
 * uses its placeholder's node id (negative, never a connection id). Null in
 * the free layout.
 */
/** Patch 21: a typical hole's mass for each size, for spacing a pipe whose type isn't known. */
const SIZE_MASS: Record<string, number> = { frigate: 0, medium: 1_000_000_000, large: 2_000_000_000, xlarge: 3_300_000_000 };
const TYPICAL_MASS = 2_000_000_000;

/** Patch 21: about how wide a pipe is drawn (base units, the outline included), so exits and lanes keep pipes apart. */
export function approximatePipeWidth(mass: number | null | undefined, shipSize?: string | null, factor = 1): number {
    if (shipSize === 'frigate') return 4;
    const total = mass && mass > 0 ? mass : shipSize && shipSize in SIZE_MASS ? SIZE_MASS[shipSize] : TYPICAL_MASS;
    return Math.max(3, pipeWidth(total) * factor + 2);
}

function connectionWidth(connection: TMapConnection): number | undefined {
    if (connection.type === 'stargate') return 3;
    const typed = (connection.signatures ?? []).find((signature) => signature.wormhole && !signature.wormhole.name.startsWith('K162') && signature.wormhole.total_mass > 0);
    return approximatePipeWidth(typed?.wormhole?.total_mass ?? null, connection.ship_size);
}

/**
 * Every measured box on the tree map in base units (systems and the dotted
 * placeholder boxes), and the placeholder boxes drawn small (rage lanes).
 */
export function treeRects(store: MapStore): { rects: Map<number, Rect>; anchors: Map<number, Vec2>; compact: Set<number> } {
    // Every measured node, not just the connected ones: the router keeps runs out of
    // the columns they pass, so it has to see whatever could be in the way.
    const rects = new Map<number, Rect>();
    const anchors = new Map<number, Vec2>();
    const compact = new Set<number>();
    for (const nodeId of store.systems.keys()) {
        const anchor = store.renderPosition(nodeId);
        if (!anchor) continue;
        anchors.set(nodeId, anchor);
        const size = store.nodeSizes.get(nodeId);
        if (size) {
            rects.set(nodeId, nodeRect(anchor, size));
        }
    }
    // Placeholder systems (patch 12) are in the way too.
    const layout = store.bandLayout.value;
    for (const placeholder of store.placeholders.value) {
        const anchor = store.renderPosition(placeholder.nodeId);
        if (!anchor) continue;
        const small = layout?.bandOf.get(placeholder.nodeId) === 'lane' && !placeholder.armedBy;
        if (small) compact.add(placeholder.nodeId);
        anchors.set(placeholder.nodeId, anchor);
        rects.set(placeholder.nodeId, nodeRect(anchor, small ? { width: 100, height: 26 } : { width: 180, height: 40 }));
    }
    return { rects, anchors, compact };
}

const cache = new WeakMap<MapStore, ComputedRef<Map<number, EdgeGeometry> | null>>();

export function useTreeGeometries(store: MapStore): ComputedRef<Map<number, EdgeGeometry> | null> {
    const existing = cache.get(store);
    if (existing) return existing;

    const geometries = computed<Map<number, EdgeGeometry> | null>(() => {
        if (!store.isLayoutLocked.value) return null;

        const edges: EdgeInput[] = [];
        // Patch 22: a hub's holes get their straight lanes.
        const hubLanes = store.bandLayout.value?.hubLanes;
        const laneFor = (a: number, b: number) => {
            const lane = hubLanes?.get(b);
            if (lane && lane.hubId === a) return lane;
            const reverse = hubLanes?.get(a);
            return reverse && reverse.hubId === b ? reverse : null;
        };
        for (const connection of store.connections.values()) {
            edges.push({
                id: connection.id,
                sourceId: connection.from_map_solarsystem_id,
                targetId: connection.to_map_solarsystem_id,
                width: connectionWidth(connection),
                lane: laneFor(connection.from_map_solarsystem_id, connection.to_map_solarsystem_id),
            });
        }

        const { rects, anchors, compact } = treeRects(store);
        for (const placeholder of store.placeholders.value) {
            if (!anchors.has(placeholder.nodeId)) continue;
            const width = approximatePipeWidth(wormholeMass(placeholder.wormhole)?.total ?? null, placeholder.shipSize, compact.has(placeholder.nodeId) ? 0.5 : 1.1);
            edges.push({ id: placeholder.nodeId, sourceId: placeholder.parentId, targetId: placeholder.nodeId, width, lane: laneFor(placeholder.parentId, placeholder.nodeId) });
        }

        return computeTreeEdgeGeometries(edges, rects, anchors);
    });
    cache.set(store, geometries);
    return geometries;
}
