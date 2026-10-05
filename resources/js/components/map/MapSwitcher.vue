<script setup lang="ts">
import MapController from '@/actions/App/Http/Controllers/MapController';
import CreateMapDialog from '@/components/map/CreateMapDialog.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { home } from '@/routes';
import type { AppPageProps } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check, ChevronDown, LayoutGrid, Loader2, Map as MapIcon, Plus } from 'lucide-vue-next';
import { ref } from 'vue';

/** Patch 26: the map's name opens a list of your maps, plus New map. */
const { map } = defineProps<{ map: { id: number; name: string } }>();

type TMapLink = { id: number; name: string; slug: string };
const page = usePage<AppPageProps<{ available_maps?: TMapLink[]; can_create_map?: boolean }>>();
const loading = ref(false);
const creating = ref(false);

function load(open: boolean): void {
    if (!open || page.props.available_maps || loading.value) return;
    loading.value = true;
    router.reload({ only: ['available_maps'], onFinish: () => (loading.value = false) });
}
</script>

<template>
    <DropdownMenu @update:open="load">
        <DropdownMenuTrigger as-child>
            <button type="button" class="flex items-center gap-2 rounded px-1 py-0.5 hover:bg-muted" title="Your maps">
                <MapIcon class="size-4 text-muted-foreground" />
                <span class="hidden font-mono text-xs font-medium sm:inline">{{ map.name }}</span>
                <ChevronDown class="size-3 text-muted-foreground" />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" class="max-h-[60vh] w-56 overflow-y-auto">
            <DropdownMenuLabel class="text-xs">Maps</DropdownMenuLabel>
            <div v-if="loading && !page.props.available_maps" class="flex items-center gap-2 px-2 py-1.5 text-xs text-muted-foreground">
                <Loader2 class="size-3.5 animate-spin" /> Loading…
            </div>
            <DropdownMenuItem v-for="item in page.props.available_maps ?? []" :key="item.id" as-child class="text-xs">
                <Link :href="MapController.show(item.slug)" class="flex w-full items-center gap-2">
                    <Check v-if="item.id === map.id" class="size-3.5 text-amber-400" />
                    <MapIcon v-else class="size-3.5 text-muted-foreground" />
                    <span class="truncate" :class="item.id === map.id ? 'font-semibold' : ''">{{ item.name }}</span>
                </Link>
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem v-if="page.props.can_create_map" class="text-xs" @select="creating = true">
                <Plus class="size-3.5" /> New map
            </DropdownMenuItem>
            <DropdownMenuItem as-child class="text-xs">
                <Link :href="home()" class="flex w-full items-center gap-2"><LayoutGrid class="size-3.5" /> All maps</Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
    <CreateMapDialog v-if="page.props.can_create_map" v-model:open="creating" />
</template>
