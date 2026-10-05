<script setup lang="ts">
import { useRouteCopy } from '@/composables/useRouteCopy';
import { computed } from 'vue';

/** Patch 25 / 26: where a character is (its map name, or the k-space system); click copies the route to it. */
const { solarsystemId, who } = defineProps<{ solarsystemId: number; who: string }>();

const { nameOf, copyRoute } = useRouteCopy();
const label = computed(() => nameOf(solarsystemId));
</script>

<template>
    <button
        type="button"
        class="max-w-28 truncate rounded px-1 font-semibold text-foreground hover:bg-muted hover:text-amber-300"
        :title="`Copy the route to ${who} (from Routing's From, or home): the way, its size, the mass it can take and its chokepoints`"
        @click.stop="copyRoute('default', solarsystemId, who)"
        @pointerdown.stop
    >
        {{ label }}
    </button>
</template>
