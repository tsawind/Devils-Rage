<script setup lang="ts">
import { CharacterImage } from '@/components/images';
import SolarsystemClass from '@/components/solarsystem/SolarsystemClass.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useStaticSolarsystems } from '@/composables/useStaticSolarsystems';
import useUser from '@/composables/useUser';
import { displayAlias } from '@/lib/alias';
import type { TMapSolarsystemBase } from '@/pages/maps';
import type { TCharacter } from '@/types/models';
import { usePage } from '@inertiajs/vue3';
import { Users } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Patch 23: your other characters, next to your character button. On a map it shows where
 * each one is (when online and tracked there). Groundwork for a second tracked character.
 */
const user = useUser();
const page = usePage<{ map_characters?: TCharacter[] | null; map?: { map_solarsystems?: TMapSolarsystemBase[] } }>();
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
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-64">
            <DropdownMenuLabel class="text-xs">Alts</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem v-for="alt in alts" :key="alt.id" class="flex items-center gap-2 text-xs">
                <CharacterImage :character_id="alt.id" :character_name="alt.name" class="size-5 rounded" />
                <span class="truncate font-medium">{{ alt.name }}</span>
                <span v-if="alt.system" class="ml-auto flex shrink-0 items-center gap-1">
                    <SolarsystemClass :solarsystem_class="alt.system.class" />
                    <span v-if="alt.alias" class="font-semibold">{{ alt.alias }}</span>
                    <span class="text-muted-foreground">{{ alt.system.name }}</span>
                </span>
                <span v-else class="ml-auto shrink-0 text-muted-foreground">offline</span>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
