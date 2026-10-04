import { elbowPoints } from '@/map/core/geometry/paths';
import { pillNextToSystem, PILL_SYSTEM_GAP, type PillSpot } from '@/map/core/geometry/pills';
import type { MapStore } from '@/map/store/mapStore';
import { useTreeGeometries } from '@/map/store/treeGeometries';
import { computed, shallowReactive, type ComputedRef } from 'vue';

/**
 * Patch 21: where every pipe's pill goes on the tree map (jumped pipes by
 * connection id, dotted ones by placeholder node id). Patch 21b: right next to
 * the system the pipe runs into, sized with the zoom. Each pipe reports its
 * pill's size (at zoom 1); null in the free layout (pills sit mid-pipe there).
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
        // Patch 21b: pills grow and shrink with the zoom, and sit right next to the system the pipe runs into.
        const spots = new Map<number, PillSpot>();
        for (const [id, size] of sizes) {
            const geometry = routed.get(id);
            if (!geometry || geometry.kind !== 'elbow') continue;
            // Patch 22: a hub lane's pill sits by the hole, whichever way the pipe is stored.
            const points = elbowPoints(geometry, scale);
            if (geometry.pillAt === 'from') points.reverse();
            const at = pillNextToSystem(points, size.width * scale, size.height * scale, PILL_SYSTEM_GAP * scale);
            if (at) spots.set(id, { ...at, dot: false });
        }
        return spots;
    });
    spotsCache.set(store, spots);
    return spots;
}
