<script setup lang="ts">
import StructureSearchController from '@/actions/App/Http/Controllers/StructureSearchController';
import { useWaypoint } from '@/composables/useWaypoint';
import { ComboboxGroup, ComboboxItem, ComboboxLabel } from 'reka-ui';
import { ref, watch } from 'vue';

/**
 * 20 Nov: "dock <name>" in the search: player structures by name (ones the mapper knows, plus
 * EVE's search with a pilot who granted Find structures). Picking one sets it as the
 * destination on your online characters.
 */
type TStructure = { id: number; name: string; solarsystem: string | null };

const { query } = defineProps<{ query: string }>();
const emit = defineEmits<{ close: [] }>();

const item =
    'col-span-full grid cursor-default grid-cols-subgrid items-center rounded-sm px-2 py-1.5 outline-none select-none data-[disabled]:opacity-50 data-[highlighted]:bg-accent';
const label = 'col-span-full px-2 py-1.5 font-sans font-semibold text-[11px] tracking-wider text-muted-foreground uppercase';

const structures = ref<TStructure[]>([]);
const searchedEve = ref(true);
const loading = ref(false);
const { setWaypointAll } = useWaypoint();
let timer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => query.trim(),
    (text) => {
        clearTimeout(timer);
        if (text.length < 3) {
            structures.value = [];
            return;
        }
        timer = setTimeout(() => void load(text), 350);
    },
    { immediate: true },
);

async function load(text: string): Promise<void> {
    loading.value = true;
    try {
        const response = await fetch(StructureSearchController.index({ query: { q: text } }).url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!response.ok || text !== query.trim()) return;
        const body = (await response.json()) as { structures: TStructure[]; searched_eve: boolean };
        structures.value = body.structures;
        searchedEve.value = body.searched_eve;
    } finally {
        loading.value = false;
    }
}

function pick(structure: TStructure): void {
    setWaypointAll(structure.id);
    emit('close');
}
</script>

<template>
    <ComboboxGroup class="col-span-full grid grid-cols-subgrid">
        <ComboboxLabel :class="label">Structures</ComboboxLabel>
        <ComboboxItem v-for="structure in structures" :key="`structure-${structure.id}`" :value="`structure-${structure.id}`" :class="item" @select.prevent="pick(structure)">
            <span class="col-span-4 truncate pl-5 text-xs">
                {{ structure.name }}<span v-if="structure.solarsystem" class="text-muted-foreground"> · {{ structure.solarsystem }}</span>
            </span>
            <span class="text-[11px] text-muted-foreground">set destination</span>
        </ComboboxItem>
        <div v-if="!structures.length" class="col-span-full pl-7 text-xs text-muted-foreground">
            <template v-if="query.trim().length < 3">Type at least 3 letters of the structure's name.</template>
            <template v-else-if="loading">Looking…</template>
            <template v-else-if="!searchedEve">No known structure by that name. Grant "Find structures" (Settings → EVE characters) to search EVE too.</template>
            <template v-else>No structure by that name that your pilots can dock at.</template>
        </div>
    </ComboboxGroup>
</template>
