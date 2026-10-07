<script setup lang="ts">
import { useMinuteClock } from '@/composables/combat/useMinuteClock';
import { isDeadEnd as isDeadEndSystem } from '@/lib/combat';
import NodeCard from '@/map/components/nodes/NodeCard.vue';
import SolarsystemConnectionHandle from '@/map/components/solarsystem/SolarsystemConnectionHandle.vue';
import SolarsystemDragHandle from '@/map/components/solarsystem/SolarsystemDragHandle.vue';
import { ANCHOR_OFFSET, scalePoint } from '@/map/core/coords';
import { useNodeMeasurement } from '@/map/interactions/measure';
import { useMapStore } from '@/map/store/mapStore';
import { wayBackBookmark } from '@/map/holeBookmark';
import { visibleBookmarkName } from '@/lib/bookmark';
import { signatureToast as toast } from '@/lib/signatureToast';
import { TShowMapProps } from '@/pages/maps';
import { show } from '@/routes/maps';
import { AppPageProps } from '@/types';
import { TCharacter } from '@/types/models';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, useTemplateRef, watch } from 'vue';

/**
 * Store-connected wrapper for one node: resolves the entity, position, and all
 * interaction/page state, then renders the presentational NodeCard plus the
 * drag/connection handles. Gestures and the context menu are wired in a later
 * phase against the data-node-id / data-drag-handle / data-connect-handle hooks.
 */
const { id } = defineProps<{ id: number }>();

const store = useMapStore();
const page = usePage<AppPageProps<TShowMapProps>>();

const system = computed(() => store.systems.get(id) ?? null);

/**
 * The stored position is the connection anchor; the node's top-left sits one
 * ANCHOR_OFFSET above/left of it. Both are emitted in screen units here (the
 * old tree did the same in two steps: left/top at the scaled anchor, then a
 * -scale*offset translate on an inner div).
 */
const wrapperStyle = computed(() => {
    const position = store.renderPosition(id);
    if (!position) {
        return null;
    }
    const scale = store.scale.value;
    const scaled = scalePoint(position, scale);
    return {
        transform: `translate(${scaled.x - ANCHOR_OFFSET.x * scale}px, ${scaled.y - ANCHOR_OFFSET.y * scale}px)`,
    };
});

const isSelected = computed(() => store.isSelected(id));
const isHovered = computed(() => store.isHovered(id));

function handlePointerEnter(): void {
    store.hoveredSolarsystemId.value = id;
}

function handlePointerLeave(): void {
    if (store.hoveredSolarsystemId.value === id) {
        store.hoveredSolarsystemId.value = null;
    }
}

const pilots = computed<TCharacter[]>(() => {
    const current = system.value;
    if (!current) {
        return [];
    }
    // One entry per character, even if a reload ever hands the same pilot over twice.
    const seen = new Set<number>();
    return (page.props.map_characters ?? []).filter((character) => {
        if (character.status?.solarsystem_id !== current.solarsystem_id || seen.has(character.id)) return false;
        seen.add(character.id);
        return true;
    });
});

const isActive = computed(() => {
    return system.value !== null && page.props.selected_map_solarsystem?.solarsystem_id === system.value.solarsystem_id;
});

const isHome = computed(() => {
    return system.value !== null && store.meta.value?.home_solarsystem_id === system.value.solarsystem_id;
});

const isRally = computed(() => {
    return system.value !== null && store.meta.value?.rally_solarsystem_id === system.value.solarsystem_id;
});

/** Patch 35: this system's static is being rage rolled. */
const isRolling = computed(() => {
    return system.value !== null && store.meta.value?.rage_roll?.solarsystem_id === system.value.solarsystem_id;
});

// Fully scanned, every signature identified, and the only hole is the way in (see isDeadEnd).
const minuteClock = useMinuteClock();
const isDeadEnd = computed(() => {
    const current = system.value;
    if (!current) return false;
    return isDeadEndSystem(current, store.connectionCounts.value.get(id) ?? 0, minuteClock.value, isHome.value);
});

const threatLevel = computed(() => {
    return page.props.map_user_settings?.show_threat_level ? (system.value?.threat_level ?? null) : null;
});

const fixedWidth = computed(() => store.isTreeLayout.value || store.isConstantWidthEnabled.value);

/** Patch 15: rage-lane systems are full readable cards again (patch 13 drew them small). */
const compact = computed(() => false);

/**
 * Patch 20: the way back to the system this one was found from (its sig on this side),
 * shown as a green pill on the card. Not for home or systems with no parent.
 */
const wayBack = computed<{ code: string | null } | null>(() => {
    const current = system.value;
    if (!current || !store.isTreeLayout.value) return null;
    const parentId = store.bandLayout.value?.parentOf.get(current.id);
    if (parentId === undefined) return null;
    for (const connection of store.connections.values()) {
        const ends = [connection.from_map_solarsystem_id, connection.to_map_solarsystem_id];
        if (!ends.includes(current.id) || !ends.includes(parentId) || connection.type === 'stargate') continue;
        const signature = (connection.signatures ?? []).find((candidate) => candidate.map_solarsystem_id === current.id);
        return { code: signature?.signature_id ? signature.signature_id.slice(0, 3) : null };
    }
    return null;
});

function copyWayBack(): void {
    const current = system.value;
    const parentId = current ? store.bandLayout.value?.parentOf.get(current.id) : undefined;
    const parent = parentId !== undefined ? store.systems.get(parentId) : undefined;
    if (!current || !parent) return;
    const name = wayBackBookmark(store, current, parent);
    if (!name) return;
    navigator.clipboard
        .writeText(name)
        .then(() => toast.success('Copied your way back', { description: visibleBookmarkName(name) }))
        .catch(() => undefined);
}

const canWrite = computed(() => page.props.permission === 'member' || page.props.permission === 'manager');

const linkHref = computed(() => {
    const meta = store.meta.value;
    const current = system.value;
    if (!meta || !current) {
        return null;
    }
    return show(meta.slug, {
        mergeQuery: { solarsystem_id: current.solarsystem_id },
    });
});

const card = useTemplateRef('card');
const { observeNode, unobserveNode } = useNodeMeasurement();

watch(
    () => card.value?.root ?? null,
    (element, previous) => {
        if (previous) {
            unobserveNode(previous, id);
        }
        if (element) {
            observeNode(element, id);
        }
    },
    { flush: 'post' },
);

onBeforeUnmount(() => {
    const element = card.value?.root;
    if (element) {
        unobserveNode(element, id);
    }
});
</script>

<template>
    <div
        v-if="system && wrapperStyle && linkHref"
        :data-node-id="id"
        :style="wrapperStyle"
        class="pointer-events-none absolute hover:z-20 data-[active=true]:z-10"
        :data-active="isActive"
    >
        <div class="pointer-events-auto origin-top-left" @pointerenter="handlePointerEnter" @pointerleave="handlePointerLeave">
            <div
                class="group relative origin-top-left"
                :style="{
                    scale: store.scale.value,
                }"
            >
                <div class="">
                    <Link
                        :href="linkHref"
                        preserve-state
                        preserve-scroll
                        :only="['map', 'selected_map_solarsystem', 'map_navigation', 'map_characters', 'eve_scout_connections', 'threat_analysis']"
                        prefetch
                        cache-for="2s"
                    >
                        <NodeCard
                            ref="card"
                            :system="system"
                            :pilots="pilots"
                            :is-selected="isSelected"
                            :is-hovered="isHovered"
                            :is-active="isActive"
                            :is-home="isHome"
                            :is-rally="isRally"
                            :is-rolling="isRolling"
                            :fixed-width="fixedWidth"
                            :threat-level="threatLevel"
                            :way-back="wayBack"
                            @copy-way-back="copyWayBack"
                            :is-dead-end="isDeadEnd"
                            :compact="compact"
                        />
                    </Link>
                    <template v-if="canWrite">
                        <SolarsystemDragHandle data-drag-handle v-if="!system.pinned && !store.isLayoutLocked.value" />
                        <SolarsystemConnectionHandle data-connect-handle :data-connection-source="system.solarsystem_id" />
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped></style>
