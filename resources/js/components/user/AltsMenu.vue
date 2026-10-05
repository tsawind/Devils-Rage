<script setup lang="ts">
import { CharacterImage } from '@/components/images';
import SolarsystemClass from '@/components/solarsystem/SolarsystemClass.vue';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useStaticSolarsystems } from '@/composables/useStaticSolarsystems';
import { useTrackedAlts } from '@/composables/useTrackedAlts';
import useUser from '@/composables/useUser';
import { displayAlias } from '@/lib/alias';
import type { TMapSolarsystemBase } from '@/pages/maps';
import type { AppPageProps } from '@/types';
import type { TCharacter } from '@/types/models';
import { usePage } from '@inertiajs/vue3';
import { Users } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Patch 23: your other characters, next to your character button. On a map it shows where
 * each one is (when online and tracked there). Groundwork for a second tracked character.
 */
const user = useUser();
const page = usePage<AppPageProps<{ map_characters?: TCharacter[] | null; map?: { slug?: string; map_solarsystems?: TMapSolarsystemBase[] } }>>();

// Patch 24: on a map, tick alts to track them there too (several at once).
const { trackedIds, setTracked } = useTrackedAlts();
const mapSlug = computed(() => page.props.map?.slug ?? null);
const tracked = computed(() => trackedIds(mapSlug.value));
const { getSolarsystemById } = useStaticSolarsystems();

const alts = computed(() =>
    (user.value?.characters ?? [])
        .filter((alt) => alt.id !== user.value?.active_character?.id)
        .map((alt) => {
            const solarsystemId = page.props.map_characters?.find((mapped) => mapped.id === alt.id)?.status?.solarsystem_id ?? null;
            const alias = solarsystemId ? (page.props.map?.map_solarsystems?.find((system) => system.solarsystem_id === solarsystemId)?.alias ?? null) : null;
            return { id: alt.id, name: alt.name, system: getSolarsystemById(solarsystemId), alias: displayAlias(alias) };
        }),
);
</script>

<template>
    <DropdownMenu v-if="alts.length">
        <DropdownMenuTrigger as-child>
            <button type="button" class="flex items-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted/50 hover:text-foreground">
                <Users class="size-4" />
                <span class="hidden sm:inline">Alts</span>
                <span v-if="tracked.size" class="rounded-full bg-amber-500/20 px-1.5 text-[10px] text-amber-400">{{ tracked.size }}</span>
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-64">
            <DropdownMenuLabel class="text-xs">Alts{{ mapSlug ? ' · tick to track' : '' }}</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <template v-for="alt in alts" :key="alt.id">
                <DropdownMenuCheckboxItem
                    v-if="mapSlug"
                    :model-value="tracked.has(alt.id)"
                    class="text-xs"
                    :title="tracked.has(alt.id) ? 'Tracked on this map: its jumps are mapped. Click to stop.' : 'Track on this map: map its jumps like yours'"
                    @update:model-value="(value: boolean | 'indeterminate') => setTracked(mapSlug!, alt.id, value === true)"
                    @select.prevent
                >
                    <CharacterImage :character_id="alt.id" :character_name="alt.name" class="size-5 rounded" />
                    <span class="truncate font-medium">{{ alt.name }}</span>
                    <span v-if="alt.system" class="ml-auto flex shrink-0 items-center gap-1">
                        <SolarsystemClass :solarsystem_class="alt.system.class" />
                        <span v-if="alt.alias" class="font-semibold">{{ alt.alias }}</span>
                        <span class="text-muted-foreground">{{ alt.system.name }}</span>
                    </span>
                    <span v-else class="ml-auto shrink-0 text-muted-foreground">offline</span>
                </DropdownMenuCheckboxItem>
                <DropdownMenuItem v-else class="flex items-center gap-2 text-xs">
                    <CharacterImage :character_id="alt.id" :character_name="alt.name" class="size-5 rounded" />
                    <span class="truncate font-medium">{{ alt.name }}</span>
                </DropdownMenuItem>
            </template>
            <p v-if="mapSlug" class="px-2 pt-1.5 pb-1 text-[11px] text-muted-foreground">Ticked alts are tracked on this map while Tracking is on. Follow and Center stay on you.</p>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
