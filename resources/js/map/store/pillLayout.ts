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
const PILL_HEIGHT = 20;

const widthsCache = new WeakMap<MapStore, Map<number, number>>();
const spotsCache = new WeakMap<MapStore, ComputedRef<Map<number, PillSpot> | null>>();

/** The pill widths reported by each pipe, in screen pixels (0 or missing = no pill). */
export function pillWidths(store: MapStore): Map<number, number> {
    let widths = widthsCache.get(store);
    if (!widths) {
        widths = shallowReactive(new Map<number, number>());
        widthsCache.set(store, widths);
    }
    return widths;
}

export function setPillWidth(store: MapStore, id: number, width: number | null): void {
    const widths = pillWidths(store);
    if (!width) {
        if (widths.has(id)) widths.delete(id);
        return;
    }
    if (widths.get(id) !== width) widths.set(id, width);
}

export function usePillSpots(store: MapStore): ComputedRef<Map<number, PillSpot> | null> {
    const existing = spotsCache.get(store);
    if (existing) return existing;
    const geometries = useTreeGeometries(store);
    const spots = computed<Map<number, PillSpot> | null>(() => {
        const routed = geometries.value;
        if (!routed) return null;
        const scale = store.scale.value;
        const widths = pillWidths(store);
        const obstacles: PillBox[] = [...treeRects(store).rects.values()].map((rect) => ({
            minX: rect.minX * scale,
            minY: rect.minY * scale,
            maxX: rect.maxX * scale,
            maxY: rect.maxY * scale,
        }));
        const requests: PillRequest[] = [];
        for (const [id, width] of widths) {
            const geometry = routed.get(id);
            if (!geometry || geometry.kind !== 'elbow' || !width) continue;
            requests.push({ id, points: elbowPoints(geometry, scale), width, height: PILL_HEIGHT });
        }
        return placePills(requests, obstacles);
    });
    spotsCache.set(store, spots);
    return spots;
}
