<script setup lang="ts">
import AltPill from '@/components/user/AltPill.vue';
import MainPill from '@/components/user/MainPill.vue';
import useUser from '@/composables/useUser';
import { soloTab } from '@/lib/windowDriver';

/**
 * Patch 23 / 25: your alt and main float as pills, top-right of the map.
 * Patch 42b: always there (the slim bar replaced the site header); not in an ALT WINDOW (that window is one character).
 */
const user = useUser();
</script>

<template>
    <div v-if="user && !soloTab" class="absolute top-2 right-2 z-40 flex items-start gap-2">
        <AltPill />
        <MainPill />
    </div>
</template>
