<script setup lang="ts">
import MassSessionCard from '@/components/fleet/MassSessionCard.vue';
import { fleetMembers } from '@/composables/useFleet';
import { loadMassSessions, massLoaded, massState } from '@/composables/useMassSessions';
import { useShipProfiles } from '@/composables/useShipProfiles';
import { tryUseMapStore } from '@/map/store/mapStore';
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';

/**
 * Patch 37: every "⚖ Adding mass…" session on the map, one card per hole. Read every 10 s
 * while on screen, and at once when a pipe's session changes (the map's broadcast).
 */
const { mapSlug } = defineProps<{ mapSlug: string }>();
const store = tryUseMapStore();
const profiles = useShipProfiles(mapSlug);

let timer: number | undefined;
onMounted(() => {
    void loadMassSessions(mapSlug);
    timer = window.setInterval(() => void loadMassSessions(mapSlug), 10_000);
});
onBeforeUnmount(() => window.clearInterval(timer));

const signature = computed(() =>
    store
        ? [...store.connections.values()]
              .map((connection) => (connection.mass_session ? `${connection.id}:${connection.mass_session.id}:${connection.mass_session.goal}:${connection.jumps_count}` : ''))
              .join(',')
        : '',
);
watch(signature, () => void loadMassSessions(mapSlug));

// The fits SeAT knows for the crews' ships (the fleet's own ships are asked by the Fleet window).
watch(
    () =>
        massState.value.crews
            .flatMap((crew) => crew.members)
            .filter((member) => member.ship_type_id && !fleetMembers.value.some((row) => row.character_id === member.character_id))
            .map((member) => `${member.character_id}:${member.ship_type_id}`)
            .join(','),
    (keys) => {
        if (!keys) return;
        const members = keys.split(',').map((key) => {
            const [character_id, ship_type_id] = key.split(':').map(Number);
            return { character_id, ship_type_id };
        });
        void profiles.refresh(members);
    },
    { immediate: true },
);
</script>

<template>
    <div class="grid min-w-0 gap-2 text-xs">
        <p v-if="!massLoaded" class="text-muted-foreground">Reading sessions…</p>
        <p v-else-if="!massState.sessions.length" class="text-muted-foreground">
            No one is massing a hole. Right-click a pipe → <b>Adding mass… (session)</b> to start one; everyone on the map sees it.
        </p>
        <MassSessionCard v-for="session in massState.sessions" :key="session.id" :session="session" :map-slug="mapSlug" />
    </div>
</template>
