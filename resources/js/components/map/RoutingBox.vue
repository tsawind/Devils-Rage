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
const { map, map_navigation, map_characters, selected_map_solarsystem, ignored_systems } = defineProps<{
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
const label = computed(() => {
    const from = nameOf(fromSystemId.value);
    const to = nameOf(toSystemId.value);
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
                class="flex w-full items-center gap-2 rounded border border-border/50 bg-background px-3 py-1.5 text-sm text-muted-foreground hover:border-border hover:text-foreground"
            >
                <Route class="size-4 shrink-0" />
                <span class="flex-1 truncate text-left" :class="label !== 'Routing' ? 'text-foreground' : ''">{{ label }}</span>
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
