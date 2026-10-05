<script setup lang="ts">
import { useRouteBookmark } from '@/composables/useRouteBookmark';
import { computed } from 'vue';

/** Patch 25: where a character is (its map name, or the k-space system); click copies the route to it. */
const { solarsystemId, who } = defineProps<{ solarsystemId: number; who: string }>();

const { nameOf, copyRouteTo } = useRouteBookmark();
const label = computed(() => nameOf(solarsystemId));
</script>

<template>
    <button
        type="button"
        class="max-w-28 truncate rounded px-1 font-semibold text-foreground hover:bg-muted hover:text-amber-300"
        :title="`Copy the route to ${who} (from home): the way, the mass it can take and the chokepoint`"
        @click.stop="copyRouteTo(solarsystemId, who)"
        @pointerdown.stop
    >
        {{ label }}
    </button>
</template>
