<script setup lang="ts">
import AltPill from '@/components/user/AltPill.vue';
import MainPill from '@/components/user/MainPill.vue';
import { useMapChrome } from '@/composables/useMapChrome';
import useUser from '@/composables/useUser';

/** Patch 23 / 25: with the site header folded away, your alt and main float as pills, top-right of the map. */
const { barsFolded } = useMapChrome();
const user = useUser();
</script>

<template>
    <div v-if="barsFolded && user" class="absolute top-2 right-2 z-40 flex items-start gap-2">
        <AltPill />
        <MainPill />
    </div>
</template>
