import { nodeRect } from '@/map/core/coords';
import { computeTreeEdgeGeometries } from '@/map/core/geometry/treeRouting';
import type { EdgeGeometry, EdgeInput, Rect, Vec2 } from '@/map/core/types';
import type { MapStore } from '@/map/store/mapStore';
import { computed, type ComputedRef } from 'vue';

/**
 * The tree layout's routed pipes, jumped and unjumped (patch 20: one routing
 * pass for both, so the dotted pipes to unjumped holes share the same exits
 * out of a system's top, right and bottom as the jumped ones, and are packed
 * into the same column gaps). Connections keep their own ids; a dotted pipe
 * uses its placeholder's node id (negative, never a connection id). Null in
 * the free layout.
 */
const cache = new WeakMap<MapStore, ComputedRef<Map<number, EdgeGeometry> | null>>();

export function useTreeGeometries(store: MapStore): ComputedRef<Map<number, EdgeGeometry> | null> {
    const existing = cache.get(store);
    if (existing) return existing;

    const geometries = computed<Map<number, EdgeGeometry> | null>(() => {
        if (!store.isLayoutLocked.value) return null;

        const edges: EdgeInput[] = [];
        for (const connection of store.connections.values()) {
            edges.push({ id: connection.id, sourceId: connection.from_map_solarsystem_id, targetId: connection.to_map_solarsystem_id });
        }

        // Every measured node, not just the connected ones: the router keeps runs out of
        // the columns they pass, so it has to see whatever could be in the way.
        const rects = new Map<number, Rect>();
        const anchors = new Map<number, Vec2>();
        for (const nodeId of store.systems.keys()) {
            const anchor = store.renderPosition(nodeId);
            if (!anchor) continue;
            anchors.set(nodeId, anchor);
            const size = store.nodeSizes.get(nodeId);
            if (size) {
                rects.set(nodeId, nodeRect(anchor, size));
            }
        }

        // Placeholder systems (patch 12) are in the way too, and get their dotted pipes routed here.
        const layout = store.bandLayout.value;
        for (const placeholder of store.placeholders.value) {
            const anchor = store.renderPosition(placeholder.nodeId);
            const compact = layout?.bandOf.get(placeholder.nodeId) === 'lane' && !placeholder.armedBy;
            if (!anchor) continue;
            anchors.set(placeholder.nodeId, anchor);
            rects.set(placeholder.nodeId, nodeRect(anchor, compact ? { width: 100, height: 26 } : { width: 180, height: 40 }));
            edges.push({ id: placeholder.nodeId, sourceId: placeholder.parentId, targetId: placeholder.nodeId });
        }

        return computeTreeEdgeGeometries(edges, rects, anchors);
    });
    cache.set(store, geometries);
    return geometries;
}
