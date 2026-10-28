<script setup lang="ts">
import { Button } from '@/components/ui/button';
import MapPanel from '@/components/ui/map-panel/MapPanel.vue';
import MapPanelContent from '@/components/ui/map-panel/MapPanelContent.vue';
import MapPanelHeader from '@/components/ui/map-panel/MapPanelHeader.vue';
import { Textarea } from '@/components/ui/textarea';
import usePermission from '@/composables/usePermission';
import type { TResolvedSelectedMapSolarsystem } from '@/pages/maps';
import MapSolarsystems from '@/routes/map-solarsystems';
import { router, useForm } from '@inertiajs/vue3';
import SystemIntelDialog from '@/components/intel/SystemIntelDialog.vue';
import { displayAlias } from '@/lib/alias';
import { Maximize2, Pencil } from 'lucide-vue-next';
import markdownit from 'markdown-it';
import attr from 'markdown-it-link-attributes';
import { computed, ref, watch } from 'vue';

const { map_solarsystem } = defineProps<{
    map_solarsystem: TResolvedSelectedMapSolarsystem;
}>();

const { canEdit: can_write } = usePermission();

const editing = ref(false);
// Patch 55: the system's intel, opened big (structures, scans, screenshots, who lives there).
const intelOpen = ref(false);
const systemLabel = computed(() => [displayAlias(map_solarsystem.alias), map_solarsystem.solarsystem?.name].filter(Boolean).join(' · '));

// Patch 58b: the system's structures in short, above the notes; click one for the intel.
const structures = computed(() => map_solarsystem.intel_structures ?? []);
const KIND_SHORT: Record<string, string> = {
    astrahus: 'Astrahus',
    fortizar: 'Fortizar',
    keepstar: 'Keepstar',
    raitaru: 'Raitaru',
    azbel: 'Azbel',
    sotiyo: 'Sotiyo',
    athanor: 'Athanor',
    tatara: 'Tatara',
    pos: 'POS',
    other: 'Structure',
};
const STATE_SHORT: Record<string, { label: string; klass: string }> = {
    online: { label: 'online', klass: 'text-green-400' },
    low_power: { label: 'low power', klass: 'text-amber-300' },
    abandoned: { label: 'abandoned', klass: 'text-neutral-400' },
    anchoring: { label: 'anchoring', klass: 'text-sky-300' },
    armor_timer: { label: 'armor timer', klass: 'text-amber-300' },
    hull_timer: { label: 'hull timer', klass: 'text-red-300' },
    offline: { label: 'offline', klass: 'text-neutral-400' },
    unanchoring: { label: 'unanchoring', klass: 'text-sky-300' },
};
/** The name without the system part EVE puts in front ("J145735 - Old-Daisy" → "Old-Daisy"). */
const shortName = (name: string | null) => (name ?? '').replace(/^[A-Z0-9-]+ - /, '');
function timerText(iso: string | null): string | null {
    if (!iso) return null;
    const minutes = Math.round((new Date(iso).getTime() - Date.now()) / 60_000);
    if (minutes <= 0) return 'out now';
    const hours = Math.floor(minutes / 60);
    return hours >= 24 ? `out in ${Math.floor(hours / 24)}d ${hours % 24}h` : `out in ${hours}h ${minutes % 60}m`;
}
// Changes made in the intel window show here once it closes.
watch(intelOpen, (isOpen) => {
    if (!isOpen) router.reload({ only: ['selected_map_solarsystem'] });
});

const form = useForm({
    notes: map_solarsystem.notes || '',
});

const md = markdownit({
    html: true,
    linkify: true,
    typographer: true,
    breaks: true,
});

md.use(attr, {
    attrs: {
        target: '_blank',
        rel: 'noopener',
    },
});

const description = computed(() => {
    if (map_solarsystem.notes) {
        return md.render(map_solarsystem.notes);
    }
    return '';
});

function handleSubmit() {
    form.submit(MapSolarsystems.update(map_solarsystem.id), {
        onSuccess: () => {
            editing.value = false;
        },
        preserveScroll: true,
        preserveState: true,
        only: ['selected_map_solarsystem'],
    });
}

watch(
    () => map_solarsystem,
    (newValue) => {
        editing.value = false;
        form.notes = newValue.notes || '';
    },
);
</script>

<template>
    <MapPanel>
        <MapPanelHeader>
            Notes
            <template #actions>
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-5 gap-1 px-1.5 text-[11px] text-muted-foreground hover:text-foreground"
                    title="Intel: structures, D-scans, fits, screenshots and who lives here, in a big window"
                    type="button"
                    @click="intelOpen = true"
                >
                    <Maximize2 class="size-3" /> Intel
                </Button>
                <template v-if="editing && can_write">
                    <Button variant="ghost" size="sm" class="h-5 px-1.5 text-[11px]" @click="editing = false" type="button"> Cancel </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="h-5 px-1.5 text-[11px] text-amber-400 hover:text-amber-400"
                        @click="handleSubmit"
                        type="button"
                    >
                        Save
                    </Button>
                </template>
                <Button
                    v-else-if="can_write"
                    variant="ghost"
                    size="icon"
                    class="size-5 text-muted-foreground hover:text-foreground"
                    @click="editing = true"
                    type="button"
                >
                    <Pencil class="size-3" />
                </Button>
            </template>
        </MapPanelHeader>
        <MapPanelContent>
            <div v-if="structures.length" class="flex flex-col gap-0.5 border-b border-border/50 px-3 py-1.5 text-[11px]">
                <button
                    v-for="structure in structures"
                    :key="structure.id"
                    type="button"
                    class="flex min-w-0 items-baseline gap-1.5 rounded px-1 text-left hover:bg-muted/50"
                    :title="`${KIND_SHORT[structure.kind] ?? structure.kind} ${structure.name ?? ''}${structure.owner ? ` (${structure.owner})` : ''}: open the intel`"
                    @click="intelOpen = true"
                >
                    <span class="shrink-0 font-semibold text-foreground">{{ KIND_SHORT[structure.kind] ?? structure.kind }}</span>
                    <span class="min-w-0 truncate text-muted-foreground">{{ shortName(structure.name) }}<template v-if="structure.owner"> · {{ structure.owner }}</template></span>
                    <span class="ml-auto shrink-0" :class="STATE_SHORT[structure.state]?.klass ?? 'text-muted-foreground'">{{ STATE_SHORT[structure.state]?.label ?? structure.state }}</span>
                    <span v-if="timerText(structure.timer_at)" class="shrink-0 font-mono text-amber-300">{{ timerText(structure.timer_at) }}</span>
                </button>
            </div>
            <Textarea
                v-if="editing"
                v-model="form.notes"
                class="h-full w-full resize-none border-0 bg-transparent px-3 py-2 font-mono text-xs focus-visible:ring-0"
                placeholder="Add notes..."
            />
            <div
                v-else-if="map_solarsystem.notes"
                v-html="description"
                class="prose prose-sm max-w-none px-3 py-2 prose-invert prose-headings:my-2 prose-headings:text-foreground prose-p:my-2 prose-a:text-amber-500 prose-a:no-underline hover:prose-a:underline prose-code:rounded prose-code:bg-muted prose-code:px-1 prose-code:py-0.5 prose-code:text-amber-400 prose-code:before:content-none prose-code:after:content-none prose-pre:border prose-pre:border-border/50 prose-pre:bg-muted prose-ol:my-2 prose-ul:my-2 prose-li:my-0.5"
            />
            <div v-else class="flex h-full flex-col items-center justify-center gap-2 p-4">
                <p class="font-sans font-semibold text-[11px] tracking-wider text-muted-foreground/60 uppercase">No notes</p>
            </div>
        </MapPanelContent>
        <SystemIntelDialog v-model:open="intelOpen" :map-solarsystem-id="map_solarsystem.id" :system-label="systemLabel" />
    </MapPanel>
</template>
