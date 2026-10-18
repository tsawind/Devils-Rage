<script setup lang="ts">
import NavigationRoute from '@/components/autopilot/NavigationRoute.vue';
import RouteCopyButtons from '@/components/map/RouteCopyButtons.vue';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useNavigationSystems } from '@/composables/useNavigationSystems';
import { useStaticData } from '@/composables/useStaticData';
import { useStaticSolarsystems } from '@/composables/useStaticSolarsystems';
import { displayAlias } from '@/lib/alias';
import useUser from '@/composables/useUser';
import type { TMap, TResolvedMapNavigation, TResolvedSelectedMapSolarsystem } from '@/pages/maps';
import type { TCharacter } from '@/types/models';
import { Route } from 'lucide-vue-next';
import { computed } from 'vue';

/** Patch 23: a box next to Search that opens the route finder (the same one as Navigation → Route). */
const { map, map_navigation, map_characters, selected_map_solarsystem, ignored_systems, compact = false } = defineProps<{
    /** Patch 41: the slim bar's small box. */
    compact?: boolean;
    map: TMap;
    map_navigation: TResolvedMapNavigation | null;
    map_characters: TCharacter[] | null;
    selected_map_solarsystem: TResolvedSelectedMapSolarsystem | null;
    ignored_systems: number[];
}>();

const user = useUser();
const activeCharacter = computed(() => map_characters?.find((character) => character.id === user.value?.active_character?.id) ?? null);

const { staticData, loadStaticData } = useStaticData();
void loadStaticData();
const solarsystems = computed(() => staticData.value?.solarsystems ?? []);

// Patch 25: the box shows what's picked ("From Jita", "Jita → Amarr"); route copies start at From.
const { fromSystemId, toSystemId } = useNavigationSystems();
const { getSolarsystemById } = useStaticSolarsystems();
const nameOf = (id: number | null): string | null => {
    if (!id) return null;
    const alias = map.map_solarsystems?.find((system) => system.solarsystem_id === id)?.alias;
    return displayAlias(alias) || getSolarsystemById(id)?.name || null;
};
// Patch 41: with no From picked, the route starts where you are.
const fromName = computed(() => nameOf(fromSystemId.value) ?? nameOf(activeCharacter.value?.status?.solarsystem_id ?? null));
const toName = computed(() => nameOf(toSystemId.value));
const label = computed(() => {
    const from = fromName.value;
    const to = toName.value;
    if (from && to) return `${from} → ${to}`;
    if (from) return `From ${from}`;
    if (to) return `To ${to}`;
    return 'Routing';
});
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                class="flex w-full items-center gap-2 rounded border border-border/50 bg-background text-sm text-muted-foreground hover:border-border hover:text-foreground"
                :class="compact ? 'h-7 px-2' : 'px-3 py-1.5'"
            >
                <Route class="size-4 shrink-0" />
                <!-- Patch 41: where from → where to, the first letters of each (the whole route on hover) -->
                <span v-if="compact && fromName && toName" class="flex min-w-0 flex-1 items-center gap-1 text-left text-xs text-foreground" :title="label">
                    <span class="max-w-[46px] truncate">{{ fromName }}</span><span class="text-muted-foreground">→</span><span class="max-w-[46px] truncate">{{ toName }}</span>
                </span>
                <span v-else class="flex-1 truncate text-left" :class="[label !== 'Routing' ? 'text-foreground' : '', compact ? 'text-xs' : '']" :title="label">{{ label }}</span>
            </button>
        </PopoverTrigger>
        <PopoverContent align="start" class="max-h-[70vh] w-[380px] overflow-y-auto p-0">
            <NavigationRoute
                :map="map"
                :solarsystems="solarsystems"
                :selected_map_solarsystem="selected_map_solarsystem"
                :ignored_systems="ignored_systems"
                :active_character="activeCharacter"
                :character_status="activeCharacter?.status ?? null"
                :destinations="map_navigation?.destinations ?? []"
                :map_characters="map_characters"
            />
            <RouteCopyButtons variant="panel" />
        </PopoverContent>
    </Popover>
</template>
