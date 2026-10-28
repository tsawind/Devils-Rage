<script lang="ts">
import { ref } from 'vue';

/** Patch 58: the system whose intel the right-click menu opened (a map system id and its label). */
export const intelTarget = ref<{ id: number; label: string } | null>(null);
</script>

<script setup lang="ts">
import SystemIntelDialog from '@/components/intel/SystemIntelDialog.vue';
import { computed } from 'vue';

/** Patch 58: one intel window for the right-click menu's "Intel", over the map. */
const open = computed({
    get: () => intelTarget.value !== null,
    set: (value: boolean) => {
        if (!value) intelTarget.value = null;
    },
});
</script>

<template>
    <SystemIntelDialog v-if="intelTarget" v-model:open="open" :map-solarsystem-id="intelTarget.id" :system-label="intelTarget.label" />
</template>
