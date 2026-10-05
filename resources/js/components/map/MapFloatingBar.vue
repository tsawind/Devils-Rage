<script setup lang="ts">
import { CharacterImage } from '@/components/images';
import SolarsystemClass from '@/components/solarsystem/SolarsystemClass.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import UserMenuContent from '@/components/user/UserMenuContent.vue';
import { useActiveMapCharacter } from '@/composables/useActiveMapCharacter';
import { useMapChrome } from '@/composables/useMapChrome';
import { useStaticSolarsystem, useStaticSolarsystems } from '@/composables/useStaticSolarsystems';
import useUser from '@/composables/useUser';
import { displayAlias } from '@/lib/alias';
import type { TMap } from '@/pages/maps';
import type { TCharacter } from '@/types/models';
import { ChevronsDown, Users } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted } from 'vue';

/**
 * Patch 23: what floats over the map. The targets for the bar's toolbar (icons only, while the
 * bars are folded) and for Search + Routing (folded, or popped out) are always here; the
 * Location, Alts and character pills show while the bars are folded.
 */
const { map, map_characters } = defineProps<{
    map: TMap;
    map_characters: TCharacter[] | null;
}>();

const { barsFolded, floatReady, setBarsFolded } = useMapChrome();
onMounted(() => (floatReady.value = true));
onBeforeUnmount(() => (floatReady.value = false));
const user = useUser();
const character = useActiveMapCharacter();
const { getSolarsystemById } = useStaticSolarsystems();

const aliasOf = (solarsystemId: number | null | undefined): string => {
    if (!solarsystemId) return '';
    return displayAlias(map.map_solarsystems?.find((system) => system.solarsystem_id === solarsystemId)?.alias ?? null);
};

const here = useStaticSolarsystem(() => character.value?.status?.solarsystem_id ?? null);
const hereAlias = computed(() => aliasOf(character.value?.status?.solarsystem_id));

/** Your other characters, with where they are when they're online and tracked on this map. */
const alts = computed(() =>
    (user.value?.characters ?? [])
        .filter((alt) => alt.id !== user.value?.active_character?.id)
        .map((alt) => {
            const solarsystemId = map_characters?.find((mapped) => mapped.id === alt.id)?.status?.solarsystem_id ?? null;
            return { id: alt.id, name: alt.name, system: getSolarsystemById(solarsystemId), alias: aliasOf(solarsystemId) };
        }),
);

const pill = 'pointer-events-auto flex items-center gap-2 rounded-full border border-border/60 bg-card/90 px-3 py-1 text-xs shadow-md backdrop-blur';
</script>

<template>
    <div class="pointer-events-none absolute inset-x-2 top-2 z-40 flex items-start gap-2">
        <!-- Left: the folded toolbar (icons only), then Search + Routing when they float -->
        <div class="flex flex-col items-start gap-2">
            <div v-show="barsFolded" :class="pill" class="gap-1 px-1.5">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            class="flex items-center rounded-full p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                            aria-label="Show the top bars"
                            @click="setBarsFolded(false)"
                        >
                            <ChevronsDown class="size-3.5" />
                        </button>
                    </TooltipTrigger>
                    <TooltipContent side="bottom"><p class="text-xs">Show the top bars</p></TooltipContent>
                </Tooltip>
                <div id="map-float-toolbar" class="flex items-center gap-1" />
            </div>
            <div id="map-float-tools" class="pointer-events-auto" />
        </div>

        <template v-if="barsFolded">
            <!-- Middle: where you are -->
            <div v-if="here" :class="pill" class="absolute top-0 left-1/2 -translate-x-1/2">
                <span class="tracking-wider text-muted-foreground uppercase">Location</span>
                <SolarsystemClass :solarsystem_class="here.class" />
                <span v-if="hereAlias" class="font-semibold">{{ hereAlias }}</span>
                <span class="text-muted-foreground">{{ here.name }}</span>
            </div>

            <!-- Right: your alts and where they are, then your character -->
            <div class="ml-auto flex items-center gap-2">
                <div v-if="alts.length" :class="pill">
                    <Users class="size-3.5 text-muted-foreground" />
                    <span v-for="alt in alts" :key="alt.id" class="flex items-center gap-1" :class="alt.system ? '' : 'opacity-50'">
                        <span class="font-medium">{{ alt.name }}</span>
                        <template v-if="alt.system">
                            <SolarsystemClass :solarsystem_class="alt.system.class" />
                            <span v-if="alt.alias" class="font-semibold">{{ alt.alias }}</span>
                            <span class="text-muted-foreground">{{ alt.system.name }}</span>
                        </template>
                        <span v-else class="text-muted-foreground">offline</span>
                    </span>
                </div>
                <DropdownMenu v-if="user">
                    <DropdownMenuTrigger as-child>
                        <button type="button" :class="pill" class="py-0.5 pl-1 hover:bg-muted">
                            <CharacterImage
                                v-if="user.active_character"
                                :character_id="user.active_character.id"
                                :character_name="user.active_character.name"
                                class="size-6 rounded-full"
                            />
                            <span class="max-w-28 truncate font-medium">{{ user.active_character?.name }}</span>
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <UserMenuContent :user="user" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </template>
    </div>
</template>
