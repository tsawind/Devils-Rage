<script setup lang="ts">
import { displayAlias } from '@/lib/alias';
import { TMapSolarsystem } from '@/pages/maps';

defineProps<{
    map_solarsystem: TMapSolarsystem;
    /** Truncate to a single line for the fixed-width tree layout. */
    truncate?: boolean;
}>();
</script>

<template>
    <span class="pointer-events-none col-start-2 row-start-1" :class="{ 'block min-w-0 truncate': truncate }">
        <!-- Patch 17: names in Oxanium; the J-code beside a chain number in mono -->
        <span class="mr-1 inline-block font-display text-[13px] font-semibold" v-if="map_solarsystem.alias">{{ displayAlias(map_solarsystem.alias) }}</span>
        <span
            :data-has-alias="map_solarsystem.alias !== null"
            class="font-display text-[13px] font-semibold data-[has-alias=true]:font-mono data-[has-alias=true]:text-[11px] data-[has-alias=true]:font-normal data-[has-alias=true]:text-muted-foreground"
            >{{
            map_solarsystem.solarsystem?.name
        }}</span>
        <span v-if="map_solarsystem.occupier_alias" class="text-muted-foreground"> ({{ map_solarsystem.occupier_alias }})</span>
    </span>
</template>

<style scoped></style>
