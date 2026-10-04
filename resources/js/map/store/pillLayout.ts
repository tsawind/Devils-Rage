import { elbowPoints } from '@/map/core/geometry/paths';
import { placePills, type PillBox, type PillRequest, type PillSpot } from '@/map/core/geometry/pills';
import type { MapStore } from '@/map/store/mapStore';
import { treeRects, useTreeGeometries } from '@/map/store/treeGeometries';
import { computed, shallowReactive, type ComputedRef } from 'vue';

/**
 * Patch 21: one pass placing every pipe's pill on the tree map (jumped pipes by
 * connection id, dotted ones by placeholder node id), clear of bends, system
 * boxes and each other. Each pipe reports how wide its pill is; null in the
 * free layout (pills then sit at the pipe's middle as before).
 */
type TPillSize = { width: number; height: number };

const widthsCache = new WeakMap<MapStore, Map<number, TPillSize>>();
const spotsCache = new WeakMap<MapStore, ComputedRef<Map<number, PillSpot> | null>>();

/** The pill sizes reported by each pipe, in screen pixels (missing = no pill). */
export function pillSizes(store: MapStore): Map<number, TPillSize> {
    let sizes = widthsCache.get(store);
    if (!sizes) {
        sizes = shallowReactive(new Map<number, TPillSize>());
        widthsCache.set(store, sizes);
    }
    return sizes;
}

export function setPillSize(store: MapStore, id: number, size: TPillSize | null): void {
    const sizes = pillSizes(store);
    if (!size || !size.width) {
        if (sizes.has(id)) sizes.delete(id);
        return;
    }
    const current = sizes.get(id);
    if (current?.width !== size.width || current?.height !== size.height) sizes.set(id, size);
}

export function usePillSpots(store: MapStore): ComputedRef<Map<number, PillSpot> | null> {
    const existing = spotsCache.get(store);
    if (existing) return existing;
    const geometries = useTreeGeometries(store);
    const spots = computed<Map<number, PillSpot> | null>(() => {
        const routed = geometries.value;
        if (!routed) return null;
        const scale = store.scale.value;
        const sizes = pillSizes(store);
        const obstacles: PillBox[] = [...treeRects(store).rects.values()].map((rect) => ({
            minX: rect.minX * scale,
            minY: rect.minY * scale,
            maxX: rect.maxX * scale,
            maxY: rect.maxY * scale,
        }));
        const requests: PillRequest[] = [];
        for (const [id, size] of sizes) {
            const geometry = routed.get(id);
            if (!geometry || geometry.kind !== 'elbow') continue;
            requests.push({ id, points: elbowPoints(geometry, scale), width: size.width, height: size.height });
        }
        return placePills(requests, obstacles);
    });
    spotsCache.set(store, spots);
    return spots;
}
