import type { Vec2 } from '@/map/core/types';
import { TMapConfig } from '@/types/map';
import { ref, shallowRef, type Ref, type ShallowRef } from 'vue';

export type GestureKind = 'none' | 'pan' | 'marquee' | 'node-drag' | 'link-drag';

export type Marquee = { start: Vec2; end: Vec2 };

export type ViewState = ReturnType<typeof createViewState>;

const SHOW_PLACEHOLDERS_KEY = 'map-show-unjumped-holes';
/** Patch 22: the home layout (on unless switched off in this browser). */
const HOME_LAYOUT_KEY = 'map-home-layout';
const OPENED_HOLES_KEY = 'map-opened-holes';

/** Patch 23: the systems whose folded holes you opened, remembered in this browser. */
function readOpenedHoles(): Set<number> {
    try {
        const ids: unknown = JSON.parse(window.localStorage.getItem(OPENED_HOLES_KEY) ?? '[]');
        return new Set(Array.isArray(ids) ? ids.filter((id): id is number => typeof id === 'number') : []);
    } catch {
        return new Set();
    }
}

function readHomeLayout(): boolean {
    try {
        return typeof window === 'undefined' || window.localStorage.getItem(HOME_LAYOUT_KEY) !== '0';
    } catch {
        return true;
    }
}

function readShowPlaceholders(): boolean {
    try {
        return typeof window === 'undefined' || window.localStorage.getItem(SHOW_PLACEHOLDERS_KEY) !== '0';
    } catch {
        return true;
    }
}

/**
 * Interaction and viewport state. The selected set is replaced wholesale on every
 * change, so per-node `selectedIds.value.has(id)` computeds re-evaluate together
 * but only flip (and re-render) the nodes whose membership actually changed.
 */
export function createViewState() {
    const scale = ref(1);
    const config: ShallowRef<TMapConfig> = shallowRef({ max_size: { x: 4000, y: 2000 }, grid_size: 20 });

    const selectedIds: ShallowRef<ReadonlySet<number>> = shallowRef(new Set<number>());
    const hoveredSolarsystemId: Ref<number | null> = ref(null);
    /** The live marquee box in base units, or null when none is being drawn. */
    const marquee: ShallowRef<Marquee | null> = shallowRef(null);
    const activeGesture: Ref<GestureKind> = ref('none');
    const linkDragOriginId: Ref<number | null> = ref(null);
    /** The viewer's personal layout override (when the map allows it); null follows the map. */
    const userLayoutOverride: Ref<'manual' | 'tree' | null> = ref(null);
    /** Show unjumped wormhole signatures as placeholder systems (patch 12); remembered per browser. */
    const showPlaceholders: Ref<boolean> = ref(readShowPlaceholders());

    function setShowPlaceholders(value: boolean): void {
        showPlaceholders.value = value;
        try {
            window.localStorage.setItem(SHOW_PLACEHOLDERS_KEY, value ? '1' : '0');
        } catch {
            // Storage unavailable: the switch still works for this visit.
        }
    }

    /** Patch 22: the home layout (hubs with straight lanes); remembered per browser, on by default. */
    const homeLayout: Ref<boolean> = ref(readHomeLayout());

    function setHomeLayout(value: boolean): void {
        homeLayout.value = value;
        try {
            window.localStorage.setItem(HOME_LAYOUT_KEY, value ? '1' : '0');
        } catch {
            // Storage unavailable: the switch still works for this visit.
        }
    }

    /**
     * Patch 22: when the layout moves everything by the same amount (holes above home need
     * more rows), the map scrolls by it too, so home stays still on screen. Base units.
     */
    const scrollShift: ShallowRef<{ dx: number; dy: number; seq: number } | null> = shallowRef(null);

    /** Patch 15: rage-lane systems whose unjumped holes are opened (folded into a chip otherwise). */
    const openedHoleParents: ShallowRef<ReadonlySet<number>> = shallowRef(readOpenedHoles());
    /** Patch 15: the map system you are in (its holes stay open; Center follows it). */
    const currentSystemId: Ref<number | null> = ref(null);
    /** Patch 15: ask the viewport to center on a base point (bumped on every request). */
    const centerRequest: ShallowRef<{ x: number; y: number; at: number; force: boolean } | null> = shallowRef(null);

    function toggleHoleParent(id: number): void {
        const next = new Set(openedHoleParents.value);
        if (next.has(id)) next.delete(id);
        else next.add(id);
        openedHoleParents.value = next;
        try {
            window.localStorage.setItem(OPENED_HOLES_KEY, JSON.stringify([...next].slice(-200)));
        } catch {
            // Storage unavailable: it stays open for this visit.
        }
    }

    /** Patch 16: force re-centers even while your system is comfortably on screen. */
    function requestCenter(point: { x: number; y: number }, force = false): void {
        centerRequest.value = { x: point.x, y: point.y, at: Date.now(), force };
    }

    function setSelection(ids: Iterable<number>): void {
        selectedIds.value = new Set(ids);
    }

    function clearSelection(): void {
        if (selectedIds.value.size === 0) return;
        selectedIds.value = new Set();
    }

    function pruneSelection(removedId: number): void {
        if (!selectedIds.value.has(removedId)) return;
        const next = new Set(selectedIds.value);
        next.delete(removedId);
        selectedIds.value = next;
    }

    function isSelected(id: number): boolean {
        return selectedIds.value.has(id);
    }

    function isHovered(id: number): boolean {
        return hoveredSolarsystemId.value === id;
    }

    return {
        scale,
        config,
        selectedIds,
        hoveredSolarsystemId,
        marquee,
        activeGesture,
        linkDragOriginId,
        userLayoutOverride,
        showPlaceholders,
        setShowPlaceholders,
        homeLayout,
        setHomeLayout,
        scrollShift,
        openedHoleParents,
        toggleHoleParent,
        currentSystemId,
        centerRequest,
        requestCenter,
        setSelection,
        clearSelection,
        pruneSelection,
        isSelected,
        isHovered,
    };
}
