<script setup lang="ts">
import { useLayout } from '@/composables/useLayout';
import { useMapUserSettings } from '@/composables/useMapUserSettings';
import { usePath } from '@/composables/usePath';
import usePermission from '@/composables/usePermission';
import { useRallyRoute } from '@/composables/useRallyRoute';
import StaticCertainDialog from '@/components/signatures/StaticCertainDialog.vue';
import WayBackPopup from '@/components/signatures/WayBackPopup.vue';
import { useStaticCertainty } from '@/composables/signatures/useStaticCertainty';
import { useActiveMapCharacter } from '@/composables/useActiveMapCharacter';
import { centerOnMe } from '@/composables/useCenterOnMe';
import { useUserEvents } from '@/composables/useUserEvents';
import { deleteSelectedMapSolarsystems } from '@/map/actions/deleteSelectedMapSolarsystems';
import EdgeLayer from '@/map/components/edges/EdgeLayer.vue';
import LayoutDecorations from '@/map/components/LayoutDecorations.vue';
import MapViewport from '@/map/components/MapViewport.vue';
import MapNode from '@/map/components/nodes/MapNode.vue';
import PlaceholderLayer from '@/map/components/nodes/PlaceholderLayer.vue';
import PlaceholderContextMenu from '@/map/components/overlays/PlaceholderContextMenu.vue';
import ConnectionPopover from '@/map/components/overlays/ConnectionPopover.vue';
import ClearChainDialog from '@/map/components/overlays/ClearChainDialog.vue';
import MapAddConnectionDialog from '@/map/components/overlays/MapAddConnectionDialog.vue';
import MapConnectionContextMenu from '@/map/components/overlays/MapConnectionContextMenu.vue';
import MapContextMenu from '@/map/components/overlays/MapContextMenu.vue';
import MapOptions from '@/map/components/overlays/MapOptions.vue';
import MapRallyBadge from '@/map/components/overlays/MapRallyBadge.vue';
import MapSolarsystemContextMenu from '@/map/components/overlays/MapSolarsystemContextMenu.vue';
import type { Vec2 } from '@/map/core/types';
import type { Gesture } from '@/map/interactions/gestures';
import { createLinkDragGesture } from '@/map/interactions/linkDrag';
import { createMarqueeGesture } from '@/map/interactions/marquee';
import { createNodeDragGesture } from '@/map/interactions/nodeDrag';
import { createPanGesture } from '@/map/interactions/pan';
import { useIsUsingInput } from '@/map/interactions/useIsUsingInput';
import { createMapStore, provideMapStore } from '@/map/store/mapStore';
import { useMapSync } from '@/map/sync/useMapSync';
import { TMap, TMapConnection, TSolarsystem } from '@/pages/maps';
import { TMapConfig } from '@/types/map';
import { useMagicKeys, whenever } from '@vueuse/core';
import { computed, ref, useTemplateRef, watch, watchEffect } from 'vue';

/**
 * The root of the rewritten map canvas — same external contract as the old
 * MapComponent: it takes the resolved map and the canvas config, everything
 * else comes from page props. It owns the store, the gestures, and all the
 * canvas-level overlays (context menus, connection popover, options, dialogs).
 */
const { map, config } = defineProps<{
    map: TMap;
    config: TMapConfig;
}>();

const { path } = usePath();
const { getRallyRouteInfo } = useRallyRoute();

// usePath deep-freezes its ref; unwrap it into the shallow-readonly shape the
// store's RouteDeps expects (the store only ever reads it).
const routePath = computed(() => path.value as readonly TSolarsystem[] | null);

const store = createMapStore({ path: routePath, getRallyRouteInfo });
provideMapStore(store);

watch(
    () => map,
    (value) => store.reconcileMap(value),
    { immediate: true },
);

watch(
    () => config,
    (value) => {
        store.config.value = value;
    },
    { immediate: true },
);

// The zoom level lives in the shared layout prop (cookie-persisted via
// useLayout); the old createLayout mirrored it into mapState.scale the same way.
const { layout } = useLayout();
watchEffect(() => {
    store.scale.value = layout.value?.scale ?? 1;
});

// Mirror the viewer's personal layout override (map_user_settings.layout_override,
// written by MapOptions) into the store so the effective layout derives from it.
const mapUserSettings = useMapUserSettings();
watchEffect(() => {
    store.userLayoutOverride.value = mapUserSettings.value?.layout_override ?? null;
});

useMapSync(store, () => map.id);
// Patch 13: mark a static once it is certain, after your own paste / type change.
useStaticCertainty(store);
useUserEvents();

// Patch 15: the system you're in (its rage-lane holes stay open), and Center:
// keep the map on it after every jump and whenever it moves on the map.
const activeCharacter = useActiveMapCharacter();
const locationMapSystemId = computed(() => {
    const solarsystemId = activeCharacter.value?.status?.solarsystem_id ?? null;
    if (!solarsystemId) return null;
    for (const system of store.systems.values()) if (system.solarsystem_id === solarsystemId) return system.id;
    return null;
});
watch(locationMapSystemId, (id) => (store.currentSystemId.value = id), { immediate: true });
watch(
    () => {
        const id = locationMapSystemId.value;
        if (!centerOnMe.value || id === null) return null;
        const point = store.renderPosition(id);
        return point ? `${id}:${Math.round(point.x)}:${Math.round(point.y)}` : null;
    },
    (key) => {
        const id = locationMapSystemId.value;
        if (!key || id === null) return;
        const point = store.renderPosition(id);
        // The anchor is the card's top-left plus ANCHOR_OFFSET; aim at the card's middle.
        if (point) store.requestCenter({ x: point.x + 50, y: point.y });
    },
    { immediate: true },
);

const { canEdit: canWrite } = usePermission();

const systemIds = computed(() => [...store.systems.keys()]);

// --- Gestures ------------------------------------------------------------

const viewport = useTemplateRef('viewport');
const surface = computed<HTMLElement | null>(() => viewport.value?.surface ?? null);

const { gesture: linkDragGesture, pendingTo } = createLinkDragGesture(store);
const gestures: Gesture[] = [linkDragGesture, createNodeDragGesture(store), createPanGesture(store, surface), createMarqueeGesture(store)];

/** The ghost edge's fixed end: the drag-origin node's rendered anchor. */
const pendingFrom = computed<Vec2 | null>(() => {
    const originId = store.linkDragOriginId.value;
    return originId === null ? null : store.renderPosition(originId);
});

// --- Connection popover / context menus ----------------------------------

const selectedConnectionId = ref<number | null>(null);

const selectedConnection = computed(() => {
    const id = selectedConnectionId.value;
    if (id === null) return null;
    const resolved = store.resolveConnection(id);
    if (!resolved) return null;
    return { ...resolved.connection, source: resolved.source, target: resolved.target };
});

const connectionPopoverOpen = ref(false);
const connectionPopoverPosition = ref<Vec2 | null>(null);

function handleConnectionContextMenu(_event: MouseEvent, connection: TMapConnection): void {
    selectedConnectionId.value = connection.id;
}

function handleConnectionClick(event: MouseEvent, connection: TMapConnection): void {
    connectionPopoverOpen.value = true;
    // Store the click in viewport coordinates.
    connectionPopoverPosition.value = { x: event.clientX, y: event.clientY };
    selectedConnectionId.value = connection.id;
}

// A virtual anchor at the click point. Positioning the popover off a real element fails
// when the map (which scrolls, and whose ancestors may form a containing block) shifts
// it; a virtual reference is read in viewport coordinates, like the context menu.
const connectionPopoverReference = computed(() => {
    const position = connectionPopoverPosition.value;
    if (!position) {
        return undefined;
    }

    return { getBoundingClientRect: () => new DOMRect(position.x, position.y, 0, 0) };
});

/** The node under the last right-click and where it landed, in base units. */
const contextMenuNodeId = ref<number | null>(null);
const contextMenuBasePoint = ref<Vec2 | null>(null);
/** A placeholder system under the last right-click (its signature id, patch 12). */
const contextMenuPlaceholderId = ref<number | null>(null);

function handleSurfaceContextMenu(payload: { nodeId: number | null; basePoint: Vec2; placeholderSignatureId: number | null }): void {
    contextMenuNodeId.value = payload.nodeId;
    contextMenuBasePoint.value = payload.basePoint;
    contextMenuPlaceholderId.value = payload.placeholderSignatureId;
}

const contextMenuPlaceholder = computed(() => {
    const id = contextMenuPlaceholderId.value;
    return id === null ? null : (store.placeholders.value.find((placeholder) => placeholder.signatureId === id) ?? null);
});

const contextMenuSystem = computed(() => {
    const id = contextMenuNodeId.value;
    return id === null ? null : (store.systems.get(id) ?? null);
});

// Connection wins like in the old root (selected_connection drove the type);
// the node menu replaces the old per-node ContextMenu wrapper, which the new
// MapNode no longer renders.
const contextMenuType = computed<'connection' | 'node' | 'placeholder' | 'map'>(() => {
    if (selectedConnection.value) return 'connection';
    if (contextMenuSystem.value) return 'node';
    if (contextMenuPlaceholder.value) return 'placeholder';
    return 'map';
});

function handleContextMenuOpenChange(open: boolean): void {
    if (!open) {
        selectedConnectionId.value = null;
        contextMenuNodeId.value = null;
        contextMenuPlaceholderId.value = null;
    }
}

// --- Keyboard ------------------------------------------------------------

const { Delete } = useMagicKeys();
const isUsingInput = useIsUsingInput();

whenever(Delete, () => {
    if (!isUsingInput.value) {
        deleteSelectedMapSolarsystems();
    }
});
</script>

<template>
    <MapViewport
        ref="viewport"
        :gestures="gestures"
        @context-menu-open-change="handleContextMenuOpenChange"
        @surface-context-menu="handleSurfaceContextMenu"
    >
        <LayoutDecorations />
        <PlaceholderLayer />
        <EdgeLayer
            :pending-from="pendingFrom"
            :pending-to="pendingTo"
            @connection-click="handleConnectionClick"
            @connection-context-menu="handleConnectionContextMenu"
        />
        <MapNode v-for="id in systemIds" :id="id" :key="id" />
        <template #context-menu>
            <MapContextMenu v-if="contextMenuType === 'map' && canWrite" :position="contextMenuBasePoint" />
            <MapSolarsystemContextMenu v-else-if="contextMenuType === 'node' && contextMenuSystem" :map_solarsystem="contextMenuSystem" />
            <PlaceholderContextMenu v-else-if="contextMenuType === 'placeholder' && contextMenuPlaceholder" :placeholder="contextMenuPlaceholder" />
            <MapConnectionContextMenu
                v-else-if="contextMenuType === 'connection' && selectedConnection && canWrite"
                :map_connection="selectedConnection"
            />
        </template>
        <template #overlays>
            <MapRallyBadge />
            <MapOptions />
            <WayBackPopup />
        </template>
    </MapViewport>
    <ConnectionPopover
        v-if="selectedConnection"
        :key="selectedConnection.id"
        v-model:open="connectionPopoverOpen"
        :connection="selectedConnection"
        :reference="connectionPopoverReference"
    />
    <MapAddConnectionDialog />
    <ClearChainDialog />
    <StaticCertainDialog />
</template>

<style scoped></style>
