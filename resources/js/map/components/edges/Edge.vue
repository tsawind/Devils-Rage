<script setup lang="ts">
import { isDark } from '@/composables/useIsDark';
import EdgeBadges from '@/map/components/edges/EdgeBadges.vue';
import { badgeSize, type EdgeIndicator } from '@/map/components/edges/badgeWidth';
import { scalePoint } from '@/map/core/coords';
import { setPillSize, usePillSpots } from '@/map/store/pillLayout';
import { tryUseMapStore } from '@/map/store/mapStore';
import { useMinuteNow } from '@/composables/useMinuteNow';
import { holeAge } from '@/lib/holeAge';
import { guessHole } from '@/map/holeGuess';
import { describeEstimate, estimateMass, formatMass, isFrigateHole, pipeWidth } from '@/lib/massEstimate';
import { SHIP_SIZE_LETTERS } from '@/lib/shipSize';
import { edgePathAndCenter } from '@/map/core/geometry/paths';
import type { EdgeGeometry } from '@/map/core/types';
import type { TMapConnection } from '@/pages/maps';
import type { TShipSize } from '@/types/models';
import { computed, onBeforeUnmount, watch } from 'vue';

type Props = {
    geometry: EdgeGeometry;
    /**
     * The connection being drawn, or null for the pending "new connection" ghost:
     * with no connection every status field reads undefined, which reproduces the
     * old bare `<MapConnection :from :to />` (neutral dashed curve, no badges).
     */
    connection?: TMapConnection | null;
    isOnRoute?: boolean;
    rallyDirection?: 'forward' | 'reverse' | null;
    /** Combat chain color: a glowing band behind the line in that color. */
    chainColor?: string | null;
    /** A loop (patch 12): not how either end was found, drawn dashed amber. */
    isLoop?: boolean;
    /** Pipes drawn this much thinner (patch 13: compact combat lanes). */
    pipeScale?: number;
    /** Patch 17: draw only the halo under the pipe (the first pass in the edge layer). */
    haloOnly?: boolean;
    /** Patch 17/18: the two systems' classes (and the K162 side's), for a guessed size while the hole's type is unknown. */
    endClasses?: readonly [string | null, string | null] | null;
    k162Class?: string | null;
    /** Patch 18b: which end the static comes from; doubt = one of two holes of its type ("Static?"). */
    staticEnd?: { side: 'from' | 'to'; doubt: boolean } | null;
    scale: number;
};

const {
    geometry,
    connection = null,
    isOnRoute = false,
    rallyDirection = null,
    chainColor = null,
    isLoop = false,
    pipeScale = 1,
    endClasses = null,
    k162Class = null,
    staticEnd = null,
    haloOnly = false,
    scale,
} = defineProps<Props>();

const emit = defineEmits<{
    (e: 'connectionContextMenu', event: MouseEvent): void;
    (e: 'connectionClick', event: MouseEvent): void;
}>();

const isStargate = computed(() => connection?.type === 'stargate');

/** 'elbow' is the tree-style rounded elbow + slim stroke; 'curve' the original free-layout style. */
const isOrthogonal = computed(() => geometry.kind === 'elbow');

const massStatus = computed(() => connection?.mass_status);
const lifetime = computed(() => connection?.lifetime_status);

/** The SVG path and badge-cluster centre in screen pixels; the one place scale is applied. */
const path = computed(() => edgePathAndCenter(geometry, scale));

const scaledFrom = computed(() => scalePoint(geometry.from, scale));
const scaledTo = computed(() => scalePoint(geometry.to, scale));

/**
 * Stargates carry the column default of 'large' without it meaning anything,
 * so only wormholes get a size letter.
 */
function getShipSizeLabel(size?: TShipSize | null): string | null {
    if (!size || isStargate.value) return null;

    return SHIP_SIZE_LETTERS[size];
}

const indicators = computed<EdgeIndicator[]>(() => {
    const items: EdgeIndicator[] = [];

    // Patch 21: the static sits inside the pill, in green ("Static?" in yellow when in doubt).
    if (staticEnd) {
        items.push({ type: 'static', label: staticEnd.doubt ? 'Static?' : 'Static', strong: staticEnd.doubt, fill: 'var(--color-green-700)', stroke: 'var(--color-green-800)' });
    }

    if (isStargate.value) {
        items.push({
            type: 'gate',
            fill: 'var(--color-sky-500)',
            stroke: 'var(--color-sky-600)',
        });
    }

    if (connection?.preserve_mass) {
        items.push({
            type: 'preserve',
            fill: 'var(--color-emerald-500)',
            stroke: 'var(--color-emerald-600)',
        });
    }

    // Patch 18: the pipe's size is a guess (patch 20: with the size when the possible holes agree).
    if (guessedMass.value) {
        const sized = !connection?.ship_size && guess.value?.size ? ` ${SHIP_SIZE_LETTERS[guess.value.size]}` : '';
        items.push({ type: 'text', label: `≈${sized}`, fill: 'var(--color-neutral-400)', stroke: 'var(--color-neutral-500)' });
    }

    const shipSizeLabel = getShipSizeLabel(connection?.ship_size);
    if (shipSizeLabel) {
        items.push({
            type: 'text',
            label: shipSizeLabel,
            fill: 'var(--color-neutral-500)',
            stroke: 'var(--color-neutral-600)',
            // Patch 21: a plain left / right arrow (from where it opened toward its K162 exit).
            arrow: arrowAngle.value === null ? null : Math.abs(arrowAngle.value) <= 90 ? 'right' : 'left',
        });
    }

    // Patch 21: the hole's type on the second line of the pill.
    const typeName = holeType.value?.name ?? ((connection?.signatures ?? []).some((signature) => signature.wormhole?.name?.startsWith('K162')) ? 'K162' : null);
    if (typeName && !isStargate.value) {
        items.push({ type: 'text', role: 'type', label: typeName, fill: 'var(--color-neutral-500)', stroke: 'var(--color-neutral-600)' });
    }

    if (massStatus.value && massStatus.value !== 'fresh') {
        items.push({
            type: 'weight',
            fill: massStatus.value === 'critical' ? 'var(--color-red-500)' : 'var(--color-amber-500)',
            stroke: massStatus.value === 'critical' ? 'var(--color-red-600)' : 'var(--color-amber-600)',
        });
    }

    // Patch 21: end of life inside the pill, in purple ("EOL!" when critical).
    if (lifetime.value === 'eol' || lifetime.value === 'critical') {
        const critical = lifetime.value === 'critical';
        items.push({ type: 'eol', label: critical ? 'EOL!' : 'EOL', strong: critical, fill: critical ? 'var(--color-fuchsia-600)' : 'var(--color-purple-700)', stroke: 'var(--color-purple-800)' });
    } else if (lifetime.value && lifetime.value !== 'healthy') {
        items.push({ type: 'clock', fill: 'var(--color-purple-500)', stroke: 'var(--color-purple-600)' });
    }
    // Patch 16: a faint clock once the hole is near the end of its type's lifetime and nobody checked.
    else if (age.value?.likelyEol) {
        items.push({ type: 'clock', fill: 'var(--color-purple-500)', stroke: 'var(--color-purple-600)', faint: true });
    }

    return items;
});

// ---- Direction (patch 13) -----------------------------------------------------
// A hole opens on one side and exits as a K162 on the other: the arrow next to
// the size letter points from where it opened toward its exit.

/** The map system the hole opened in, or null when neither side's type is known. */
const spawnSystemId = computed<number | null>(() => {
    if (!connection) return null;
    for (const signature of connection.signatures ?? []) {
        const name = signature.wormhole?.name;
        if (!name) continue;
        if (!name.startsWith('K162')) return signature.map_solarsystem_id;
        // The K162 side is the exit: it opened on the other side.
        return signature.map_solarsystem_id === connection.from_map_solarsystem_id ? connection.to_map_solarsystem_id : connection.from_map_solarsystem_id;
    }
    return null;
});

/** Screen angle (degrees) of the arrow, from the side it opened on toward the exit. */
const arrowAngle = computed<number | null>(() => {
    const spawn = spawnSystemId.value;
    if (spawn === null || !connection) return null;
    const forward = spawn === connection.from_map_solarsystem_id;
    const from = forward ? scaledFrom.value : scaledTo.value;
    const to = forward ? scaledTo.value : scaledFrom.value;
    return (Math.atan2(to.y - from.y, to.x - from.x) * 180) / Math.PI;
});

// ---- Mass pipe (patch 12) -----------------------------------------------------
// The line is drawn as a pipe: its outline is the hole's size when new, the
// lighter band what could be left at most, the solid core what is left at least.

/** The hole's type from either side's signature (never the K162 side). */
const holeType = computed(() => {
    for (const signature of connection?.signatures ?? []) {
        const wormhole = signature.wormhole;
        if (wormhole && !wormhole.name.startsWith('K162') && wormhole.total_mass > 0) return wormhole;
    }
    return null;
});

/**
 * Patch 18: no type known yet: a guessed pipe; a frigate size stays thin.
 * Patch 20: from the hole types that fit both ends (and which end is the K162):
 * one left = "probably N432" drawn as that type, several of one size = that size.
 */
const guess = computed(() => {
    if (isStargate.value || !connection || holeType.value || connection.ship_size === 'frigate' || !endClasses) return null;
    const [fromClass, toClass] = endClasses;
    const spawnClass = k162Class ? (k162Class === fromClass ? toClass : fromClass) : null;
    return guessHole({ k162Class, spawnClasses: spawnClass ? [spawnClass] : null, endClasses });
});
const guessedMass = computed(() => guess.value?.total ?? null);

const estimate = computed(() => {
    if (isStargate.value || !connection) return null;
    const total = holeType.value?.total_mass ?? guessedMass.value;
    if (!total) return null;
    return estimateMass({ totalMass: total, jumped: connection.jumps_mass_sum, status: massStatus.value });
});

const pipe = computed(() => {
    const current = estimate.value;
    if (!current) return null;
    const factor = Math.min(scale, 1.5) * pipeScale;
    // Patch 20: dark ink pipes in light mode.
    const color = massStatus.value === 'critical' ? '#ef4444' : massStatus.value === 'reduced' ? '#f59e0b' : isDark.value ? '#a3a3a3' : '#57534e';
    // Frigate holes always draw thin, whatever their mass (patch 13).
    const width = (mass: number) => (isFrigateHole(holeType.value?.maximum_jump_mass) ? (mass > 0 ? 2 : 0) : pipeWidth(mass) * factor);
    return {
        outline: width(current.capacity) + 2,
        hollow: width(current.capacity),
        max: width(current.max),
        min: width(current.min),
        color,
    };
});

// ---- Age (patch 16) -------------------------------------------------------------
const now = useMinuteNow();
const age = computed(() => {
    if (!connection || isStargate.value) return null;
    return holeAge({
        seen: [connection.created_at, ...(connection.signatures ?? []).map((signature) => signature.created_at)],
        now: now.value,
        maximumLifetime: holeType.value?.maximum_lifetime ?? null,
        lifetimeStatus: connection.lifetime_status,
        lifetimeUpdatedAt: connection.lifetime_status_updated_at,
    });
});

const pipeTitle = computed(() => {
    if (!connection) return undefined;
    const seen = age.value ? `${age.value.label}${age.value.likelyEol ? ' · likely EOL' : ''}` : null;
    const current = estimate.value;
    if (!current) return seen ?? undefined;
    const jumps = connection.jumps_count ?? 0;
    if (!holeType.value) {
        const probably = guess.value?.name ? `probably ${guess.value.name} (only hole that fits; frigate holes aside) · ` : '';
        return `Type not known: ${probably}guessed ${formatMass(guessedMass.value ?? 0)} kg hole · ${describeEstimate(current)} · ${jumps} ${jumps === 1 ? 'jump' : 'jumps'} logged${seen ? ` · ${seen}` : ''}`;
    }
    return `${holeType.value.name}: ${describeEstimate(current)} · ${formatMass(holeType.value.total_mass)} kg hole · ${jumps} ${jumps === 1 ? 'jump' : 'jumps'} logged (${formatMass(connection.jumps_mass_sum ?? 0)} kg)${seen ? ` · ${seen}` : ''}`;
});

// ---- Pill (patch 21) ---------------------------------------------------------------
// One pill per pipe, placed with all the others (clear of bends, boxes and other pills).
const store = tryUseMapStore();
const pillSpots = store ? usePillSpots(store) : null;
const pillId = computed(() => (store && connection && !haloOnly ? geometry.id : null));
watch(
    [pillId, () => badgeSize(indicators.value)],
    ([id, size], previous) => {
        if (!store) return;
        const before = previous?.[0] ?? null;
        if (before !== null && before !== id) setPillSize(store, before, null);
        if (id !== null) setPillSize(store, id, size);
    },
    { immediate: true },
);
onBeforeUnmount(() => {
    if (store && pillId.value !== null) setPillSize(store, pillId.value, null);
});
const pill = computed(() => {
    const spot = pillId.value !== null ? pillSpots?.value?.get(pillId.value) : null;
    return spot ? { center: { x: spot.x, y: spot.y }, dot: spot.dot } : { center: path.value.center, dot: false };
});

function getDashArray(): string | undefined {
    if (!massStatus.value) return '0';
    if (lifetime.value === 'eol' || lifetime.value === 'critical') return '2,6';
    return undefined;
}
</script>

<template>
    <!-- Patch 17: halo pass (drawn for every pipe before any pipe): a thin dark gap around it, so a
         dotted pipe crossing it reads as passing under, without cutting into neighbouring pipes -->
    <path
        v-if="haloOnly"
        v-show="pipe"
        :d="path.d"
        fill="none"
        :stroke-width="(pipe?.outline ?? 0) + 5"
        stroke-linejoin="round"
        class="pointer-events-none stroke-stone-100 dark:stroke-neutral-950"
    />
    <g v-else pointer-events="visiblePainted" class="group text-stone-600 dark:text-neutral-700">
        <!-- Combat chain: a soft band in the chain's color behind the connection, so the chain reads as one colored path. -->
        <template v-if="chainColor">
            <path :d="path.d" :stroke="chainColor" fill="none" :stroke-width="isOrthogonal ? 9 : 14" stroke-opacity="0.18" stroke-linejoin="round" stroke-linecap="round" />
            <path :d="path.d" :stroke="chainColor" fill="none" :stroke-width="isOrthogonal ? 4 : 7" stroke-opacity="0.55" stroke-linejoin="round" stroke-linecap="round" />
        </template>
        <!-- Mass pipe: outline = size when new, light band = could be up to, solid core = at least -->
        <template v-if="pipe">
            <path :d="path.d" stroke="currentColor" fill="none" :stroke-width="pipe.outline" stroke-opacity="0.5" stroke-linejoin="round" class="pointer-events-none" />
            <path :d="path.d" fill="none" :stroke-width="pipe.hollow" stroke-linejoin="round" class="pointer-events-none stroke-stone-100 dark:stroke-neutral-950" />
            <path v-if="pipe.max > 0" :d="path.d" :stroke="pipe.color" fill="none" :stroke-width="pipe.max" stroke-opacity="0.35" stroke-linejoin="round" class="pointer-events-none" />
            <path v-if="pipe.min > 0" :d="path.d" :stroke="pipe.color" fill="none" :stroke-width="pipe.min" stroke-linejoin="round" class="pointer-events-none" />
        </template>
        <!-- Stargates are permanent, so they draw a single solid line instead of the wormhole's mass/lifetime styling. -->
        <path
            v-if="isStargate"
            :d="path.d"
            stroke="currentColor"
            fill="none"
            :stroke-width="isOrthogonal ? 1.5 : 4"
            stroke-linejoin="round"
            stroke-linecap="round"
            :data-highlighted="isOnRoute"
            class="cursor-pointer text-sky-500 transition-colors duration-200 ease-in-out group-hover:text-sky-400"
        />
        <path
            v-if="!isStargate && (massStatus === 'fresh' || lifetime === 'eol' || lifetime === 'critical')"
            :d="path.d"
            stroke="currentColor"
            fill="none"
            :stroke-width="isOrthogonal ? 1.5 : 4"
            stroke-linejoin="round"
            stroke-linecap="round"
            :stroke-dasharray="getDashArray()"
            :data-lifetime="lifetime"
            :data-highlighted="isOnRoute"
            class="cursor-pointer text-stone-600 transition-colors duration-200 ease-in-out group-hover:text-stone-500 dark:text-neutral-700 dark:group-hover:text-neutral-600"
        />
        <path
            v-if="!isStargate && massStatus !== 'fresh'"
            :d="path.d"
            stroke="currentColor"
            fill="none"
            :stroke-width="isOrthogonal ? 1.5 : 4"
            stroke-linejoin="round"
            stroke-linecap="round"
            stroke-dasharray="2,6"
            stroke-dashoffset="4"
            :data-connection-status="massStatus"
            :data-highlighted="isOnRoute"
            class="cursor-pointer transition-colors duration-200 ease-in-out"
        />
        <!-- Loop: this connection isn't how either system was found -->
        <path
            v-if="isLoop"
            :d="path.d"
            stroke="#f59e0b"
            fill="none"
            :stroke-width="isOrthogonal ? 2 : 3"
            stroke-opacity="0.85"
            stroke-dasharray="6,5"
            stroke-linejoin="round"
            stroke-linecap="round"
            class="pointer-events-none"
        />
        <!-- Rally route animated overlay -->
        <template v-if="rallyDirection">
            <path
                :d="path.d"
                stroke="var(--color-pink-400)"
                fill="none"
                :stroke-width="isOrthogonal ? 4 : 6"
                stroke-linejoin="round"
                stroke-opacity="0.3"
            />
            <path
                :d="path.d"
                stroke="var(--color-pink-400)"
                fill="none"
                :stroke-width="isOrthogonal ? 1.5 : 3"
                stroke-linejoin="round"
                stroke-linecap="round"
                stroke-dasharray="8,12"
                :class="rallyDirection === 'reverse' ? 'rally-route-animated-reverse' : 'rally-route-animated'"
            />
        </template>
        <!-- Connection status indicators -->
        <EdgeBadges
            :indicators="indicators"
            :center="pill.center"
            :dot="pill.dot"
            :scale="scale"
            :clickable="Boolean(connection)"
            :title="pipeTitle"
            @open="(event) => emit('connectionClick', event)"
        />
        <path
            :d="path.d"
            stroke="transparent"
            fill="none"
            stroke-width="24"
            class="hover-path cursor-pointer transition-colors duration-200"
            @contextmenu="(event) => emit('connectionContextMenu', event)"
            @click="(event) => emit('connectionClick', event)"
            @pointerdown.stop
        >
            <title v-if="pipeTitle">{{ pipeTitle }}</title>
        </path>
        <!-- Original style draws solid endpoints; the orthogonal style meets the node edge instead. -->
        <template v-if="!isOrthogonal">
            <circle :cx="scaledFrom.x" :cy="scaledFrom.y" r="4" fill="currentColor" />
            <circle :cx="scaledTo.x" :cy="scaledTo.y" r="4" fill="currentColor" />
        </template>
    </g>
</template>

<style scoped>
[data-lifetime='critical'] {
    color: var(--color-red-500);
}

.group:hover [data-lifetime='critical'] {
    color: var(--color-red-400);
}

[data-connection-status='critical'] {
    color: var(--color-red-500);
}

.group:hover [data-connection-status='critical'] {
    color: var(--color-red-400);
}

[data-lifetime='eol'] {
    color: var(--color-purple-500);
}

.group:hover [data-lifetime='eol'] {
    color: var(--color-purple-400);
}

[data-connection-status='reduced'] {
    color: var(--color-orange-500);
}

.group:hover [data-connection-status='reduced'] {
    color: var(--color-orange-400);
}

[data-highlighted='true'] {
    color: var(--color-amber-500);
}

.group:hover [data-highlighted='true'] {
    color: var(--color-amber-400);
}

.rally-route-animated {
    animation: rally-march 0.8s linear infinite;
}

.rally-route-animated-reverse {
    animation: rally-march-reverse 0.8s linear infinite;
}

@keyframes rally-march {
    to {
        stroke-dashoffset: -20;
    }
}

@keyframes rally-march-reverse {
    to {
        stroke-dashoffset: 20;
    }
}
</style>
