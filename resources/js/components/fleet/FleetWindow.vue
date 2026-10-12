<script setup lang="ts">
import FleetShips from '@/components/fleet/FleetShips.vue';
import JumpingHotToggle from '@/components/fleet/JumpingHotToggle.vue';
import MassSessions from '@/components/fleet/MassSessions.vue';
import RollPanel from '@/components/fleet/RollPanel.vue';
import RollingCrews from '@/components/fleet/RollingCrews.vue';
import ShipProfiles from '@/components/fleet/ShipProfiles.vue';
import MapPanel from '@/components/ui/map-panel/MapPanel.vue';
import MapPanelContent from '@/components/ui/map-panel/MapPanelContent.vue';
import MapPanelHeader from '@/components/ui/map-panel/MapPanelHeader.vue';
import { countedShips, fleetMembers, fleetScope, setMine, useFleet } from '@/composables/useFleet';
import { massOpenRequest, massState } from '@/composables/useMassSessions';
import { useShipProfiles } from '@/composables/useShipProfiles';
import useUser from '@/composables/useUser';
import { mill } from '@/lib/routeSummary';
import { computed, onMounted, ref, watch } from 'vue';

/**
 * Patch 37: the Fleet window (its own place in the layout): Ships (wings, squads, what
 * counts, ISBoxer order), Mass a hole (rolling plans) and Ship profiles (cold / hot masses).
 * Reads the fleet every 30 s while it's on screen.
 */
const { mapSlug } = defineProps<{ mapSlug: string }>();

const { state, start, refresh } = useFleet(mapSlug);
const profiles = useShipProfiles(mapSlug);
const user = useUser();
const tab = ref<'ships' | 'mass' | 'crews' | 'roll' | 'profiles'>('ships');
const root = ref<{ $el?: HTMLElement } | null>(null);
const standalone = (key: string) => key === 'mass' || key === 'crews' || key === 'roll';

// Patch 37: a session just started from a pipe's menu: show it.
watch(massOpenRequest, () => {
    tab.value = 'mass';
    root.value?.$el?.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });
});

onMounted(start);
watch(
    () => user.value?.characters.map((character) => character.id) ?? [],
    (ids) => setMine(ids),
    { immediate: true },
);
// The fits SeAT knows for the fleet's hulls (asked again when someone changes ship).
watch(
    () => fleetMembers.value.map((member) => `${member.character_id}:${member.ship_type_id}`).join(','),
    () => void profiles.refresh(fleetMembers.value.map((member) => ({ character_id: member.character_id, ship_type_id: member.ship_type_id }))),
);

const message = computed(() => ('message' in state.value ? state.value.message : null));
const wings = computed(() => (state.value.status === 'ok' ? state.value.wings : []));
const totals = computed(() => {
    const ships = countedShips.value;
    return { count: ships.length, cold: ships.reduce((sum, ship) => sum + ship.cold, 0), hot: ships.reduce((sum, ship) => sum + ship.hot, 0) };
});
const scopeLabel = computed(() => ({ fleet: 'Whole fleet', wing: 'My wing', squads: 'My squads', pick: 'Picked squads' })[fleetScope.value]);
const updated = computed(() => {
    if (state.value.status !== 'ok') return null;
    const seconds = Math.max(0, Math.round((Date.now() - Date.parse(state.value.updated_at)) / 1000));
    return seconds < 60 ? `${seconds} s ago` : `${Math.round(seconds / 60)} min ago`;
});
</script>

<template>
    <MapPanel ref="root">
        <MapPanelHeader card-id="fleet">
            <span class="inline-flex items-center gap-2">Fleet</span>
            <template #actions>
                <span v-if="state.status === 'ok'" class="font-mono text-[11px] text-amber-400" :title="scopeLabel">
                    {{ scopeLabel }} · {{ totals.count }} of {{ fleetMembers.length }} · {{ mill(totals.cold) }} cold / {{ mill(totals.hot) }} hot
                </span>
                <JumpingHotToggle size="sm" />
            </template>
        </MapPanelHeader>
        <MapPanelContent>
            <div class="flex gap-1 border-b border-border/50 px-3 py-1.5 text-xs">
                <button
                    v-for="option in [
                        { key: 'ships', label: 'Ships' },
                        { key: 'mass', label: massState.sessions.length ? `⚖ Sessions (${massState.sessions.length})` : '⚖ Sessions' },
                        { key: 'crews', label: 'Crews' },
                        { key: 'roll', label: 'Quick plan' },
                        { key: 'profiles', label: 'Ship profiles' },
                    ] as const"
                    :key="option.key"
                    type="button"
                    class="rounded px-2.5 py-0.5"
                    :class="tab === option.key ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground'"
                    @click="tab = option.key"
                >
                    {{ option.label }}
                </button>
                <span v-if="state.status === 'ok'" class="ml-auto self-center text-[11px] text-muted-foreground">read with {{ state.boss }} (boss) · {{ updated }}</span>
            </div>
            <div class="min-w-0 overflow-x-hidden px-3 py-2">
                <p v-if="(state.status === 'loading' || state.status === 'idle') && !standalone(tab)" class="text-xs text-muted-foreground">Reading the fleet…</p>
                <p v-else-if="state.status !== 'ok' && !standalone(tab)" class="text-xs text-muted-foreground">
                    {{ message }} <button type="button" class="ml-1 underline" @click="refresh()">Try again</button>
                </p>
                <FleetShips v-else-if="tab === 'ships'" :wings="wings" />
                <ShipProfiles v-else-if="tab === 'profiles'" :map-slug="mapSlug" />
                <RollPanel v-if="tab === 'roll'" />
                <MassSessions v-if="tab === 'mass'" :map-slug="mapSlug" />
                <RollingCrews v-if="tab === 'crews'" :map-slug="mapSlug" />
            </div>
        </MapPanelContent>
    </MapPanel>
</template>
