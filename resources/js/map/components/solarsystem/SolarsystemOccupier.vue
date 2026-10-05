<script setup lang="ts">
import { shortOccupier } from '@/lib/shortName';
import { computed } from 'vue';

/**
 * Patch 27: the occupier alias on the card's second line, short ("Pagids L…"),
 * the full name on hover, coloured like the card's status border.
 */
const { occupier, status = null } = defineProps<{
    occupier: string;
    status?: string | null;
}>();

const short = computed(() => shortOccupier(occupier));
</script>

<template>
    <span
        :data-status="status"
        class="min-w-0 shrink-0 truncate font-display text-[11px] leading-[13px] font-semibold text-muted-foreground data-[status=active]:text-active data-[status=empty]:text-empty data-[status=friendly]:text-friendly data-[status=hostile]:text-hostile data-[status=unscanned]:text-unscanned"
        :title="`Occupier: ${occupier}`"
        >{{ short }}</span
    >
</template>
