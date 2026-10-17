<script setup lang="ts">
import { computed } from 'vue';

/**
 * Patch 39: look-alikes of EVE's overview brackets (drawn here; CCP's ImageSet can replace
 * them later): an open triangle pointing down, with one more bar for each bigger hull
 * (frigate none, destroyer 1, cruiser 2, battlecruiser 3, battleship 4), a box for
 * industrials, a double chevron for capitals, and a keyboard for a typed mass.
 */
const { kind, size = 16 } = defineProps<{ kind: string; size?: number }>();

/** Bars across the triangle, one per size step, from its top down. */
const bars = computed(() => {
    const count = ({ frigate: 0, destroyer: 1, cruiser: 2, battlecruiser: 3, battleship: 4 } as Record<string, number>)[kind] ?? 0;
    return Array.from({ length: count }, (_, index) => {
        const y = 5.6 + index * 1.5;
        const inset = ((y - 4) * 5.5) / 9 + 0.9;
        return `M${(2.5 + inset).toFixed(2)} ${y} H${(13.5 - inset).toFixed(2)}`;
    });
});
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" aria-hidden="true">
        <template v-if="kind === 'capital'">
            <path d="M2 3 L8 9 L14 3" />
            <path d="M2 7 L8 13 L14 7" />
        </template>
        <template v-else-if="kind === 'industrial'">
            <rect x="3" y="4" width="10" height="8" rx="0.5" />
            <path d="M3 8 H13" />
        </template>
        <template v-else-if="kind === 'typed'">
            <rect x="1.5" y="4.5" width="13" height="7" rx="1" />
            <path d="M4 7 H5 M7 7 H8 M10 7 H11 M5 9.5 H11" stroke-linecap="round" />
        </template>
        <template v-else>
            <path d="M2.5 4 L8 13 L13.5 4 Z" />
            <path v-for="bar in bars" :key="bar" :d="bar" stroke-width="1.1" />
        </template>
    </svg>
</template>
