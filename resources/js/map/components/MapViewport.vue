<script setup lang="ts">
import { ContextMenu, ContextMenuTrigger } from '@/components/ui/context-menu';
import { useMapBackground } from '@/composables/useMapBackground';
import MapScrollbar from '@/map/components/MapScrollbar.vue';
import { useCombat } from '@/composables/combat/useCombat';
import { centerScroll, RAGE_ROOM_SCREENS } from '@/map/core/centerScroll';
import { clientToBase } from '@/map/core/coords';
import type { Vec2 } from '@/map/core/types';
import { resolveNodeId, usePointerGestures, type Gesture } from '@/map/interactions/gestures';
import { useMapScrollbars } from '@/map/interactions/useMapScrollbars';
import { useMapStore } from '@/map/store/mapStore';
import { useElementSize } from '@vueuse/core';
import { computed, nextTick, onBeforeUnmount, onMounted, useTemplateRef, watch } from 'vue';

/**
 * The scroll container plus its visual chrome (grid, background image modes,
 * custom scrollbars, marquee box), ported from the old MapComponent template.
 * The container stays overflow-hidden like the old one — that's what hides the
 * native scrollbars while keeping programmatic scrolling (panning, thumb drags)
 * working — and the pointer-gesture arbiter is mounted on it.
 *
 * Slots: default = canvas content (edges + nodes), #context-menu = the menu
 * content of the canvas-wide ContextMenu, #overlays = panel-fixed chrome
 * (rally badge, options, …) rendered above the scroll surface.
 */
const { gestures } = defineProps<{
    gestures: Gesture[];
}>();

const emit = defineEmits<{
    (e: 'contextMenuOpenChange', open: boolean): void;
    (e: 'surfaceContextMenu', payload: { nodeId: number | null; basePoint: Vec2; placeholderSignatureId: number | null }): void;
}>();

const store = useMapStore();

const surface = useTemplateRef<HTMLElement>('surface');

defineExpose({ surface });

usePointerGestures(surface, gestures, store);

const { is_combat } = useCombat();
const { width: viewWidth, height: viewHeight } = useElementSize(surface);

// Patch 15/16: Center. Only moves the map when your system nears an edge (or a forced
// request), then puts it a third in from the left (rage scanning: 30% in, 40% down).
// Patch 16 fix: requests settle first (a new system is drawn once before its link lands,
// then slides into its lane), and your system is measured where it's really drawn.
const SETTLE_MS = 300;
let settleTimer: ReturnType<typeof setTimeout> | null = null;
let pendingForce = false;
/** Where a smooth scroll we started is heading (judged against instead of mid-animation). */
let heading: { left: number; top: number; until: number } | null = null;

/** Your system's card centre in canvas pixels: from the DOM when it's drawn, else the layout. */
function currentPoint(element: HTMLElement, fallback: { x: number; y: number } | null): { x: number; y: number } | null {
    const id = store.currentSystemId.value;
    const node = id === null ? null : element.querySelector<HTMLElement>(`[data-node-id="${id}"]`);
    const scale = store.scale.value;
    if (node) {
        const box = element.getBoundingClientRect();
        const rect = node.getBoundingClientRect();
        return {
            x: rect.left - box.left + element.scrollLeft + 90 * scale,
            y: rect.top - box.top + element.scrollTop + 20 * scale,
        };
    }
    return fallback ? { x: fallback.x * scale, y: fallback.y * scale } : null;
}

function scrollToCenter(behavior: ScrollBehavior, force: boolean): void {
    const element = surface.value;
    const request = store.centerRequest.value;
    if (!element || !request) return;
    const point = currentPoint(element, request);
    if (!point) return;
    const moving = heading && heading.until > Date.now() ? heading : null;
    const view = {
        scrollLeft: moving?.left ?? element.scrollLeft,
        scrollTop: moving?.top ?? element.scrollTop,
        width: element.clientWidth,
        height: element.clientHeight,
    };
    const target = centerScroll(view, point, { rage: is_combat.value, force });
    const maxTop = element.scrollHeight - element.clientHeight;
    const maxLeft = element.scrollWidth - element.clientWidth;
    console.debug('[center]', { rage: is_combat.value, force, point, view, target, maxLeft, maxTop });
    if (!target) return;
    const clamped = { left: Math.min(target.left, Math.max(0, maxLeft)), top: Math.min(target.top, Math.max(0, maxTop)) };
    heading = behavior === 'smooth' ? { ...clamped, until: Date.now() + 700 } : null;
    element.scrollTo({ ...clamped, behavior });
}

watch(
    () => store.centerRequest.value,
    (request) => {
        if (!request) return;
        pendingForce ||= request.force;
        if (settleTimer) clearTimeout(settleTimer);
        settleTimer = setTimeout(() => {
            settleTimer = null;
            const force = pendingForce;
            pendingForce = false;
            scrollToCenter('smooth', force);
        }, SETTLE_MS);
    },
);
// A request made before the map was on screen (page load with Center on).
onMounted(() => nextTick(() => scrollToCenter('auto', true)));
onBeforeUnmount(() => {
    if (settleTimer) clearTimeout(settleTimer);
});

const { backgroundImageUrl, backgroundMode } = useMapBackground();

// The manual map uses the full configured canvas. The tree layout instead sizes the
// canvas to its own content (plus padding) so the SVG viewBox covers every connection
// without leaving a large empty scroll area below a small tree. The container's
// `h-full w-full` keeps it at least viewport-sized.
const contentSize = computed(() => {
    const scale = store.scale.value;
    // Padding leaves room for a node's body/handles past its anchor, so edge nodes
    // aren't clipped by the canvas's overflow-hidden.
    const padding = 240 * scale;
    if (!store.isTreeLayout.value) {
        const maxSize = store.config.value.max_size;
        return { x: maxSize.x * scale + padding, y: maxSize.y * scale + padding };
    }
    let maxX = 0;
    let maxY = 0;
    for (const id of store.systems.keys()) {
        const position = store.renderPosition(id);
        if (!position) continue;
        maxX = Math.max(maxX, position.x * scale);
        maxY = Math.max(maxY, position.y * scale);
    }
    // Placeholder systems, ghosts and lane outlines (patch 12) count too.
    const layout = store.isTreeLayout.value ? store.bandLayout.value : null;
    if (layout) {
        for (const placeholder of store.placeholders.value) {
            const position = layout.positions.get(placeholder.nodeId);
            if (!position) continue;
            maxX = Math.max(maxX, position.x * scale);
            maxY = Math.max(maxY, position.y * scale);
        }
        for (const ghost of layout.ghosts) {
            maxX = Math.max(maxX, ghost.position.x * scale);
            maxY = Math.max(maxY, ghost.position.y * scale);
        }
        for (const lane of layout.lanes) {
            maxX = Math.max(maxX, lane.maxX * scale);
            maxY = Math.max(maxY, lane.maxY * scale);
        }
    }
    // Patch 16: rage scanning keeps 1.5 screens of empty room down and right of the
    // last system, so Center can keep you up and left and the map rarely has to move.
    if (is_combat.value) {
        return { x: maxX + Math.max(padding, viewWidth.value * RAGE_ROOM_SCREENS), y: maxY + Math.max(padding, viewHeight.value * RAGE_ROOM_SCREENS) };
    }
    return { x: maxX + padding, y: maxY + padding };
});

const mapContainerStyle = computed(() => {
    const cell = `${store.config.value.grid_size * store.scale.value}px`;
    const baseStyle = {
        backgroundSize: `${cell} ${cell}`,
        minHeight: `${contentSize.value.y}px`,
        minWidth: `${contentSize.value.x}px`,
    };

    // In "grid" mode the image is painted onto the scaled map content, so it
    // spans the whole grid and pans / zooms together with the systems.
    if (backgroundImageUrl.value && backgroundMode.value === 'grid') {
        return {
            ...baseStyle,
            backgroundImage: `linear-gradient(to right, rgba(0, 0, 0, 0.3) 1px, transparent 1px), linear-gradient(to bottom, rgba(0, 0, 0, 0.3) 1px, transparent 1px), url(${backgroundImageUrl.value})`,
            backgroundSize: `${cell} ${cell}, ${cell} ${cell}, cover`,
            backgroundRepeat: 'repeat, repeat, no-repeat',
            backgroundPosition: '0 0, 0 0, center center',
            backgroundAttachment: 'scroll, scroll, scroll',
        };
    }

    return baseStyle;
});

// In "viewport" mode the image lives on the (non-scrolling) scrollable container,
// so it stays fixed to the visible panel regardless of panning or zoom.
const scrollableContainerStyle = computed(() => {
    if (!backgroundImageUrl.value || backgroundMode.value !== 'viewport') {
        return undefined;
    }

    return {
        backgroundImage: `url(${backgroundImageUrl.value})`,
        backgroundSize: 'cover',
        backgroundPosition: 'center center',
        backgroundRepeat: 'no-repeat',
    };
});

const {
    scrollbars_visible,
    has_vertical,
    has_horizontal,
    v_thumb_size,
    v_thumb_offset,
    v_track_height,
    h_thumb_size,
    h_thumb_offset,
    h_track_width,
    scrollbar_size,
    onThumbMousedown,
    onTrackMousedown,
    onScrollAreaEnter,
    onScrollAreaMousemove,
} = useMapScrollbars(surface, () => contentSize.value, store.scale);

/** The live marquee box in screen pixels within the canvas. */
const marqueeRect = computed(() => {
    const box = store.marquee.value;
    if (!box) return null;
    const scale = store.scale.value;
    return {
        x: Math.min(box.start.x, box.end.x) * scale,
        y: Math.min(box.start.y, box.end.y) * scale,
        width: Math.abs(box.start.x - box.end.x) * scale,
        height: Math.abs(box.start.y - box.end.y) * scale,
    };
});

function toBasePoint(event: MouseEvent): Vec2 {
    const element = surface.value;
    if (!element) {
        return { x: 0, y: 0 };
    }
    const rect = element.getBoundingClientRect();
    return clientToBase(event.clientX, event.clientY, {
        rectLeft: rect.left,
        rectTop: rect.top,
        scrollLeft: element.scrollLeft,
        scrollTop: element.scrollTop,
        scale: store.scale.value,
    });
}

function handleContextMenu(event: MouseEvent): void {
    // Prevent the default context menu on middle click (the pan button).
    if (event.button === 1) {
        event.preventDefault();
        return;
    }
    // A placeholder system (patch 12) carries its signature's id.
    const placeholder = event.target instanceof Element ? event.target.closest<HTMLElement>('[data-placeholder-id]') : null;
    const placeholderSignatureId = placeholder?.dataset.placeholderId ? Number(placeholder.dataset.placeholderId) : null;
    emit('surfaceContextMenu', { nodeId: resolveNodeId(event.target), basePoint: toBasePoint(event), placeholderSignatureId });
}
</script>

<template>
    <div
        class="relative h-full w-full overflow-hidden bg-card ring-1 ring-border ring-offset-[-0.5px]"
        @mouseenter="onScrollAreaEnter"
        @mousemove="onScrollAreaMousemove"
    >
        <div
            ref="surface"
            class="relative h-full w-full overflow-hidden bg-neutral-100 dark:bg-neutral-950"
            :class="{ 'cursor-grab': store.isTreeLayout.value }"
            :style="scrollableContainerStyle"
            @contextmenu="handleContextMenu"
        >
            <ContextMenu @update:open="(open) => emit('contextMenuOpenChange', open)">
                <ContextMenuTrigger>
                    <div
                        class="relative grid h-full w-full overflow-hidden"
                        :class="{ 'bg-grid': !store.isTreeLayout.value }"
                        :style="mapContainerStyle"
                        @dragover.prevent
                    >
                        <slot />
                        <svg v-if="marqueeRect" class="pointer-events-none absolute inset-0 h-full w-full" xmlns="http://www.w3.org/2000/svg">
                            <rect
                                :x="marqueeRect.x"
                                :y="marqueeRect.y"
                                :width="marqueeRect.width"
                                :height="marqueeRect.height"
                                class="fill-amber-500/10 stroke-amber-500 stroke-1"
                                :rx="4"
                                :ry="4"
                                stroke-dasharray="2,2"
                            />
                        </svg>
                    </div>
                </ContextMenuTrigger>
                <slot name="context-menu" />
            </ContextMenu>
        </div>
        <MapScrollbar
            v-if="has_vertical"
            orientation="vertical"
            :thumb_size="v_thumb_size"
            :thumb_offset="v_thumb_offset"
            :visible="scrollbars_visible"
            :track_size="v_track_height"
            :scrollbar_size="scrollbar_size"
            @track-mousedown="onTrackMousedown('vertical', $event)"
            @thumb-mousedown="onThumbMousedown('vertical', $event)"
        />
        <MapScrollbar
            v-if="has_horizontal"
            orientation="horizontal"
            :thumb_size="h_thumb_size"
            :thumb_offset="h_thumb_offset"
            :visible="scrollbars_visible"
            :track_size="h_track_width"
            :scrollbar_size="scrollbar_size"
            @track-mousedown="onTrackMousedown('horizontal', $event)"
            @thumb-mousedown="onThumbMousedown('horizontal', $event)"
        />
        <slot name="overlays" />
    </div>
</template>

<style scoped>
.bg-grid {
    background-image: linear-gradient(to right, var(--grid) 1px, transparent 1px), linear-gradient(to bottom, var(--grid) 1px, transparent 1px);
}

html.dark .bg-grid {
    background-image: linear-gradient(to right, var(--grid) 1px, transparent 1px), linear-gradient(to bottom, var(--grid) 1px, transparent 1px);
}

/* Ensure custom background images work properly with grid overlay */
.bg-grid[style*='background-image: url'] {
    background-image: inherit; /* Use the inline style from the computed property */
}
</style>
