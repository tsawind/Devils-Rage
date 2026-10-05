<script setup lang="ts">
import DestinationContextMenu from '@/components/autopilot/DestinationContextMenu.vue';
import SystemComboboxList from '@/components/autopilot/SystemComboboxList.vue';
import ExtraWormholeIcon from '@/components/icons/ExtraWormholeIcon.vue';
import SolarsystemSovereignty from '@/components/map/SolarsystemSovereignty.vue';
import SolarsystemClass from '@/components/solarsystem/SolarsystemClass.vue';
import SolarsystemEffect from '@/components/solarsystem/SolarsystemEffect.vue';
import { Combobox, ComboboxAnchor } from '@/components/ui/combobox';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useCharacterPills } from '@/composables/useCharacterPills';
import { useIgnoreList } from '@/composables/useIgnoreList';
import { useNavigationSystems } from '@/composables/useNavigationSystems';
import { usePath } from '@/composables/usePath';
import { useRouteCalculator } from '@/composables/useRouteCalculator';
import { useSolarsystemAliases } from '@/composables/useSolarsystemAliases';
import { useStaticSolarsystem, useStaticSolarsystems } from '@/composables/useStaticSolarsystems';
import { MAX_SEARCH_RESULTS, takeRanked } from '@/lib/searchRank';
import type { TMap, TResolvedMapRouteSolarsystem, TResolvedSelectedMapSolarsystem, TResolvedSolarsystem } from '@/pages/maps';
import type { ConnectionType } from '@/routing/types';
import type { TCharacter, TCharacterStatus } from '@/types/models';
import type { TStaticSolarsystem } from '@/types/static-data';
import { vElementHover } from '@vueuse/components';
import { ArrowUpDown, Eraser, Home, MapPin, Navigation, Search, Users, X } from 'lucide-vue-next';
import { ComboboxInput as RekaComboboxInput } from 'reka-ui';
import { computed, ref, watch } from 'vue';

const { map, solarsystems, selected_map_solarsystem, ignored_systems, active_character, character_status, destinations, map_characters = null } = defineProps<{
    map: TMap;
    /** Patch 25: for the Alt location chip. */
    map_characters?: TCharacter[] | null;
    solarsystems: TStaticSolarsystem[];
    selected_map_solarsystem?: TResolvedSelectedMapSolarsystem | null;
    ignored_systems: number[];
    active_character?: TCharacter | null;
    character_status?: TCharacterStatus | null;
    destinations: TResolvedMapRouteSolarsystem[];
}>();

const mapConnections = computed(() => map.map_connections ?? []);
const mapSolarsystems = computed(() => map.map_solarsystems ?? []);
const { aliases } = useSolarsystemAliases(mapSolarsystems);
const { ignoreSolarsystem, clearIgnoreList } = useIgnoreList();
const { setPath } = usePath();

const search = ref('');

const { fromSystemId, toSystemId, setFromSystem, setToSystem, clearFromSystem, clearToSystem, clearAll, swapSystems } = useNavigationSystems();
const { resolveSolarsystem } = useStaticSolarsystems();

const fromSystem = computed(() => (fromSystemId.value ? resolveSolarsystem(fromSystemId.value) : null));
const toSystem = computed(() => (toSystemId.value ? resolveSolarsystem(toSystemId.value) : null));

const { route: routeSteps, jumps: routeJumps } = useRouteCalculator({
    fromId: fromSystemId,
    toId: toSystemId,
    ignoredSystems: computed(() => ignored_systems),
    mapConnections,
    mapSolarsystems,
});

type RouteEntry = {
    solarsystem: TStaticSolarsystem;
    connection_type: ConnectionType | null;
};

const enrichedRoute = computed<RouteEntry[]>(() =>
    routeSteps.value
        .map((step, index) => {
            const solarsystem = resolveSolarsystem(step.id);
            if (!solarsystem) {
                return null;
            }

            return {
                solarsystem,
                connection_type: routeSteps.value[index + 1]?.via ?? null,
            } as RouteEntry;
        })
        .filter((entry): entry is RouteEntry => Boolean(entry)),
);

const routeSolarsystems = computed(() => enrichedRoute.value.map((entry) => entry.solarsystem));
const hasRoute = computed(() => enrichedRoute.value.length > 0);
const activeCharacterSystem = useStaticSolarsystem(() => (active_character ? (character_status?.solarsystem_id ?? null) : null));

const pinnedDestinations = computed(() => destinations.filter((dest) => dest.is_pinned).slice(0, 3));

// Patch 25: Home and your Alt pill's alt are always one click away (My location is the arrow chip).
const homeSystem = useStaticSolarsystem(() => map.home_solarsystem_id ?? null);
const { pillAltId } = useCharacterPills();
const altSystem = useStaticSolarsystem(() => (pillAltId.value ? (map_characters?.find((character) => character.id === pillAltId.value)?.status?.solarsystem_id ?? null) : null));
const fixedPicks = computed(() =>
    [
        homeSystem.value ? { key: 'home', system: homeSystem.value, icon: Home, title: 'Home' } : null,
        altSystem.value ? { key: 'alt', system: altSystem.value, icon: Users, title: 'Where your alt is' } : null,
    ].filter((pick): pick is NonNullable<typeof pick> => pick !== null),
);

const hasQuickPicks = computed(
    () => fixedPicks.value.length > 0 || selected_map_solarsystem?.solarsystem || activeCharacterSystem.value || pinnedDestinations.value.length > 0,
);

const filteredSolarsystems = computed(() => {
    const query = search.value.trim().toLowerCase();
    if (!query) {
        return [] as TStaticSolarsystem[];
    }

    return takeRanked(
        solarsystems,
        query,
        MAX_SEARCH_RESULTS,
        (solarsystem) => [solarsystem.name],
        (solarsystem) => solarsystem.name,
    );
});

watch(
    () => routeSolarsystems.value,
    (value) => {
        if (value.length > 0) {
            setPath(value);
            return;
        }

        setPath(null);
    },
    { immediate: true, deep: true },
);

function handleFromSystemSelect(system: TResolvedSolarsystem) {
    setFromSystem(system.id);
    search.value = '';
}

function handleToSystemSelect(system: TResolvedSolarsystem) {
    setToSystem(system.id);
    search.value = '';
}

function onRouteHover(hovered: boolean) {
    setPath(hovered ? routeSolarsystems.value : null);
}

function handleIgnoreSolarsystem(solarsystem_id: number) {
    ignoreSolarsystem(solarsystem_id, {
        only: ['map_navigation', 'ignored_systems'],
    });
}

function handleClearIgnoreList() {
    clearIgnoreList({
        only: ['map_navigation', 'ignored_systems'],
    });
}

function systemLabel(solarsystem?: TStaticSolarsystem): string {
    if (!solarsystem) {
        return '';
    }

    const alias = aliases.value.get(solarsystem.id);

    return alias ? `${alias} (${solarsystem.name})` : solarsystem.name;
}

function clearFrom() {
    clearFromSystem();
}

function clearTo() {
    clearToSystem();
}
</script>

<template>
    <!-- Patch 25: Clear: From and To empty again (route copies go from home again) -->
    <div class="flex items-center justify-between border-b border-border/30 px-3 py-1">
        <span class="font-sans font-semibold text-[11px] tracking-wider text-muted-foreground/60 uppercase">Route planner</span>
        <button
            type="button"
            class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-40"
            :disabled="!fromSystemId && !toSystemId"
            title="Empty From and To (the origin goes back to home)"
            @click="clearAll"
        >
            <Eraser class="size-3" /> Clear
        </button>
    </div>
    <!-- From system bar -->
    <div class="border-b border-border/30 px-3 py-2">
        <Combobox>
            <ComboboxAnchor class="w-full">
                <template v-if="fromSystem">
                    <div class="flex h-8 items-center gap-1.5 rounded-md bg-muted/30 px-2">
                        <span class="font-sans font-semibold text-[11px] tracking-wider text-muted-foreground/60 uppercase">From</span>
                        <SolarsystemClass :solarsystem_class="fromSystem.class" class="shrink-0 text-xs" />
                        <span class="min-w-0 flex-1 truncate text-sm">
                            <span v-if="aliases.get(fromSystem.id)" class="mr-1">{{ aliases.get(fromSystem.id) }}</span>
                            <span :class="{ 'text-muted-foreground': aliases.get(fromSystem.id) }">{{ fromSystem.name }}</span>
                            <span class="text-xs text-muted-foreground">· {{ fromSystem.region?.name }}</span>
                        </span>
                        <SolarsystemSovereignty :sovereignty="fromSystem.sovereignty" :solarsystem-id="fromSystem.id" class="shrink-0">
                            <template #fallback>
                                <SolarsystemEffect v-if="fromSystem.effect" :effect="fromSystem.effect.name" class="shrink-0" />
                                <span v-else />
                            </template>
                        </SolarsystemSovereignty>
                        <button class="shrink-0 text-muted-foreground/40 hover:text-foreground" @click="clearFrom">
                            <X class="size-3" />
                        </button>
                    </div>
                </template>
                <template v-else>
                    <div class="flex items-center gap-2 rounded-md bg-muted/30 px-2">
                        <Search class="size-3.5 shrink-0 text-muted-foreground/40" />
                        <RekaComboboxInput
                            v-model="search"
                            placeholder="From system..."
                            class="h-8 min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground/40"
                        />
                    </div>
                </template>
            </ComboboxAnchor>
            <SystemComboboxList :solarsystems="filteredSolarsystems" :aliases="aliases" @select="handleFromSystemSelect" />
        </Combobox>
        <div v-if="hasQuickPicks" class="mt-1.5 flex flex-wrap gap-1.5">
            <button
                v-for="pick in fixedPicks"
                :key="pick.key"
                :title="pick.title"
                class="inline-flex items-center gap-1.5 rounded-md border border-border/40 bg-muted/30 px-2 py-1 text-xs transition-colors hover:bg-muted/60"
                @click="handleFromSystemSelect(pick.system)"
            >
                <component :is="pick.icon" class="size-3 shrink-0 text-muted-foreground" />
                <SolarsystemClass :solarsystem_class="pick.system.class" class="shrink-0 text-[11px]" />
                <span>
                    <span v-if="aliases.get(pick.system.id)" class="mr-1">{{ aliases.get(pick.system.id) }}</span>
                    <span :class="{ 'text-muted-foreground': aliases.get(pick.system.id) }">{{ pick.system.name }}</span>
                </span>
            </button>
            <button
                v-if="selected_map_solarsystem?.solarsystem"
                class="inline-flex items-center gap-1.5 rounded-md border border-border/40 bg-muted/30 px-2 py-1 text-xs transition-colors hover:bg-muted/60"
                @click="handleFromSystemSelect(selected_map_solarsystem.solarsystem)"
            >
                <SolarsystemClass :solarsystem_class="selected_map_solarsystem.solarsystem.class" class="shrink-0 text-[11px]" />
                <span>
                    <span v-if="aliases.get(selected_map_solarsystem.solarsystem.id)" class="mr-1">{{
                        aliases.get(selected_map_solarsystem.solarsystem.id)
                    }}</span>
                    <span :class="{ 'text-muted-foreground': aliases.get(selected_map_solarsystem.solarsystem.id) }">{{
                        selected_map_solarsystem.solarsystem.name
                    }}</span>
                </span>
                <MapPin class="size-3 shrink-0 text-muted-foreground" />
            </button>
            <button
                v-if="activeCharacterSystem"
                class="inline-flex items-center gap-1.5 rounded-md border border-border/40 bg-muted/30 px-2 py-1 text-xs transition-colors hover:bg-muted/60"
                title="My location"
                @click="handleFromSystemSelect(activeCharacterSystem)"
            >
                <SolarsystemClass :solarsystem_class="activeCharacterSystem.class" class="shrink-0 text-[11px]" />
                <span>
                    <span v-if="aliases.get(activeCharacterSystem.id)" class="mr-1">{{ aliases.get(activeCharacterSystem.id) }}</span>
                    <span :class="{ 'text-muted-foreground': aliases.get(activeCharacterSystem.id) }">{{ activeCharacterSystem.name }}</span>
                </span>
                <Navigation class="size-3 shrink-0 text-muted-foreground" />
            </button>
            <button
                v-for="dest in pinnedDestinations"
                :key="dest.id"
                class="inline-flex items-center gap-1.5 rounded-md border border-border/40 bg-muted/30 px-2 py-1 text-xs transition-colors hover:bg-muted/60"
                @click="handleFromSystemSelect(dest.solarsystem)"
            >
                <SolarsystemClass :solarsystem_class="dest.solarsystem.class" class="shrink-0 text-[11px]" />
                <span>
                    <span v-if="aliases.get(dest.solarsystem.id)" class="mr-1">{{ aliases.get(dest.solarsystem.id) }}</span>
                    <span :class="{ 'text-muted-foreground': aliases.get(dest.solarsystem.id) }">{{ dest.solarsystem.name }}</span>
                </span>
            </button>
        </div>
    </div>

    <!-- Swap button -->
    <div class="flex justify-center border-b border-border/30 py-0.5">
        <button class="inline-flex items-center gap-1 text-muted-foreground/40 transition-colors hover:text-foreground" @click="swapSystems">
            <ArrowUpDown class="size-3" />
            <span class="font-sans font-semibold text-[11px] tracking-wider uppercase">Swap</span>
        </button>
    </div>

    <!-- To system bar -->
    <div class="border-b border-border/30 px-3 py-2">
        <Combobox>
            <ComboboxAnchor class="w-full">
                <template v-if="toSystem">
                    <div class="flex h-8 items-center gap-1.5 rounded-md bg-muted/30 px-2">
                        <span class="font-sans font-semibold text-[11px] tracking-wider text-muted-foreground/60 uppercase">To</span>
                        <SolarsystemClass :solarsystem_class="toSystem.class" class="shrink-0 text-xs" />
                        <span class="min-w-0 flex-1 truncate text-sm">
                            <span v-if="aliases.get(toSystem.id)" class="mr-1">{{ aliases.get(toSystem.id) }}</span>
                            <span :class="{ 'text-muted-foreground': aliases.get(toSystem.id) }">{{ toSystem.name }}</span>
                            <span class="text-xs text-muted-foreground">· {{ toSystem.region?.name }}</span>
                        </span>
                        <SolarsystemSovereignty :sovereignty="toSystem.sovereignty" :solarsystem-id="toSystem.id" class="shrink-0">
                            <template #fallback>
                                <SolarsystemEffect v-if="toSystem.effect" :effect="toSystem.effect.name" class="shrink-0" />
                                <span v-else />
                            </template>
                        </SolarsystemSovereignty>
                        <button class="shrink-0 text-muted-foreground/40 hover:text-foreground" @click="clearTo">
                            <X class="size-3" />
                        </button>
                    </div>
                </template>
                <template v-else>
                    <div class="flex items-center gap-2 rounded-md bg-muted/30 px-2">
                        <Search class="size-3.5 shrink-0 text-muted-foreground/40" />
                        <RekaComboboxInput
                            v-model="search"
                            placeholder="To system..."
                            class="h-8 min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground/40"
                        />
                    </div>
                </template>
            </ComboboxAnchor>
            <SystemComboboxList :solarsystems="filteredSolarsystems" :aliases="aliases" @select="handleToSystemSelect" />
        </Combobox>
        <div v-if="hasQuickPicks" class="mt-1.5 flex flex-wrap gap-1.5">
            <button
                v-for="pick in fixedPicks"
                :key="pick.key"
                :title="pick.title"
                class="inline-flex items-center gap-1.5 rounded-md border border-border/40 bg-muted/30 px-2 py-1 text-xs transition-colors hover:bg-muted/60"
                @click="handleToSystemSelect(pick.system)"
            >
                <component :is="pick.icon" class="size-3 shrink-0 text-muted-foreground" />
                <SolarsystemClass :solarsystem_class="pick.system.class" class="shrink-0 text-[11px]" />
                <span>
                    <span v-if="aliases.get(pick.system.id)" class="mr-1">{{ aliases.get(pick.system.id) }}</span>
                    <span :class="{ 'text-muted-foreground': aliases.get(pick.system.id) }">{{ pick.system.name }}</span>
                </span>
            </button>
            <button
                v-if="selected_map_solarsystem?.solarsystem"
                class="inline-flex items-center gap-1.5 rounded-md border border-border/40 bg-muted/30 px-2 py-1 text-xs transition-colors hover:bg-muted/60"
                @click="handleToSystemSelect(selected_map_solarsystem.solarsystem)"
            >
                <SolarsystemClass :solarsystem_class="selected_map_solarsystem.solarsystem.class" class="shrink-0 text-[11px]" />
                <span>
                    <span v-if="aliases.get(selected_map_solarsystem.solarsystem.id)" class="mr-1">{{
                        aliases.get(selected_map_solarsystem.solarsystem.id)
                    }}</span>
                    <span :class="{ 'text-muted-foreground': aliases.get(selected_map_solarsystem.solarsystem.id) }">{{
                        selected_map_solarsystem.solarsystem.name
                    }}</span>
                </span>
                <MapPin class="size-3 shrink-0 text-muted-foreground" />
            </button>
            <button
                v-if="activeCharacterSystem"
                class="inline-flex items-center gap-1.5 rounded-md border border-border/40 bg-muted/30 px-2 py-1 text-xs transition-colors hover:bg-muted/60"
                title="My location"
                @click="handleToSystemSelect(activeCharacterSystem)"
            >
                <SolarsystemClass :solarsystem_class="activeCharacterSystem.class" class="shrink-0 text-[11px]" />
                <span>
                    <span v-if="aliases.get(activeCharacterSystem.id)" class="mr-1">{{ aliases.get(activeCharacterSystem.id) }}</span>
                    <span :class="{ 'text-muted-foreground': aliases.get(activeCharacterSystem.id) }">{{ activeCharacterSystem.name }}</span>
                </span>
                <Navigation class="size-3 shrink-0 text-muted-foreground" />
            </button>
            <button
                v-for="dest in pinnedDestinations"
                :key="dest.id"
                class="inline-flex items-center gap-1.5 rounded-md border border-border/40 bg-muted/30 px-2 py-1 text-xs transition-colors hover:bg-muted/60"
                @click="handleToSystemSelect(dest.solarsystem)"
            >
                <SolarsystemClass :solarsystem_class="dest.solarsystem.class" class="shrink-0 text-[11px]" />
                <span>
                    <span v-if="aliases.get(dest.solarsystem.id)" class="mr-1">{{ aliases.get(dest.solarsystem.id) }}</span>
                    <span :class="{ 'text-muted-foreground': aliases.get(dest.solarsystem.id) }">{{ dest.solarsystem.name }}</span>
                </span>
            </button>
        </div>
    </div>

    <!-- Route info bar -->
    <div v-if="fromSystem && toSystem" class="flex items-center justify-between border-b border-border/30 px-3 py-1.5">
        <span v-if="hasRoute" class="font-sans font-semibold text-[11px] tracking-wider text-muted-foreground uppercase">{{ routeJumps }} jumps</span>
        <button
            v-if="ignored_systems.length > 0"
            class="font-mono text-[11px] text-muted-foreground hover:text-foreground"
            @click="handleClearIgnoreList"
        >
            Clear {{ ignored_systems.length }} ignored
        </button>
    </div>

    <!-- Route results -->
    <div v-if="hasRoute" v-element-hover="onRouteHover" class="grid grid-cols-[1.5rem_1.5rem_auto_1.25rem_1rem_1.25rem] gap-x-2">
        <DestinationContextMenu v-for="(entry, index) in enrichedRoute" :key="entry.solarsystem.id" :solarsystem_id="entry.solarsystem.id">
            <div class="col-span-full grid grid-cols-subgrid items-center border-b border-border/30 px-3 py-1 hover:bg-muted/30">
                <span class="text-center font-mono text-[11px] text-muted-foreground/60">{{ index + 1 }}</span>

                <SolarsystemClass :solarsystem_class="entry.solarsystem.class" class="justify-self-center" />

                <span class="min-w-0 truncate text-xs">
                    <span v-if="aliases.get(entry.solarsystem.id)" class="mr-1">{{ aliases.get(entry.solarsystem.id) }}</span>
                    <span :class="{ 'text-muted-foreground': aliases.get(entry.solarsystem.id) }">{{ entry.solarsystem.name }}</span>
                    <span class="text-[11px] text-muted-foreground">· {{ entry.solarsystem.region?.name }}</span>
                </span>

                <SolarsystemSovereignty
                    :sovereignty="entry.solarsystem.sovereignty"
                    :solarsystem-id="entry.solarsystem.id"
                    class="size-4 justify-self-center"
                >
                    <template #fallback>
                        <SolarsystemEffect v-if="entry.solarsystem.effect" :effect="entry.solarsystem.effect.name" />
                    </template>
                </SolarsystemSovereignty>

                <div class="flex items-center justify-center">
                    <Tooltip
                        v-if="
                            index < enrichedRoute.length - 1 &&
                            (enrichedRoute[index]?.connection_type === 'wormhole' || enrichedRoute[index]?.connection_type === 'evescout')
                        "
                    >
                        <TooltipTrigger as-child>
                            <ExtraWormholeIcon
                                class="size-3.5"
                                :class="enrichedRoute[index]?.connection_type === 'evescout' ? 'text-blue-400' : 'text-amber-500'"
                            />
                        </TooltipTrigger>
                        <TooltipContent>
                            {{
                                enrichedRoute[index]?.connection_type === 'evescout'
                                    ? `EVE Scout to ${systemLabel(enrichedRoute[index + 1]?.solarsystem)}`
                                    : `Take wormhole to ${systemLabel(enrichedRoute[index + 1]?.solarsystem)}`
                            }}
                        </TooltipContent>
                    </Tooltip>
                </div>

                <div class="flex items-center justify-center">
                    <button
                        v-if="index !== 0 && index !== enrichedRoute.length - 1"
                        class="text-muted-foreground/40 hover:text-destructive"
                        @click.stop="handleIgnoreSolarsystem(entry.solarsystem.id)"
                    >
                        <X class="size-3" />
                    </button>
                </div>
            </div>
        </DestinationContextMenu>
    </div>

    <!-- Empty states -->
    <div v-else-if="fromSystem && toSystem" class="flex h-full flex-col items-center justify-center p-4">
        <p class="font-sans font-semibold text-[11px] tracking-wider text-muted-foreground/60 uppercase">No route found</p>
    </div>
    <div v-else class="flex h-full flex-col items-center justify-center p-4">
        <p class="font-sans font-semibold text-[11px] tracking-wider text-muted-foreground/60 uppercase">Select origin and destination</p>
    </div>
</template>

<style scoped></style>
