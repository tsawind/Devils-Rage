<script setup lang="ts">
import SystemIntelController from '@/actions/App/Http/Controllers/SystemIntelController';
import { Dialog, DialogDescription, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import usePermission from '@/composables/usePermission';
import { jsonFetch } from '@/lib/jsonFetch';
import { driverHeaders } from '@/lib/windowDriver';
import { useEventListener } from '@vueuse/core';
import { computed, reactive, ref, watch } from 'vue';

/**
 * Patch 55: a system's intel, opened big from the Notes panel: who lives there (from the kills the
 * mapper keeps), structures and POS (each change logged), D-scans, fits, screenshots and notes,
 * and the log of everything, kept for good. A first version for testing in game.
 */
type TStructure = {
    id: number;
    kind: string;
    name: string | null;
    owner: string | null;
    location: string | null;
    state: string;
    vulnerable_window: string | null;
    timer_at: string | null;
    fit: string | null;
    note: string | null;
    seen_at: string;
    created_by_name: string | null;
    updated_by_name: string | null;
};
type TEntry = {
    id: number;
    kind: string;
    body: string | null;
    data: { objects?: number; on_grid?: number; types?: { type: string; count: number }[] } | null;
    intel_structure_id: number | null;
    author_name: string | null;
    created_at: string;
    image_url: string | null;
};
type TAssessment = {
    kills: number;
    since: string;
    hours: number[];
    peak: { from: number; to: number } | null;
    fleet: { median: number; max: number } | null;
    corporations: { name: string; kills: number; losses: number; days: number }[];
    ships: { name: string; count: number }[];
};
type TIntel = { structures: TStructure[]; entries: TEntry[]; assessment: TAssessment; kinds: string[]; states: string[] };

const { mapSolarsystemId, systemLabel } = defineProps<{ mapSolarsystemId: number; systemLabel: string }>();
const open = defineModel<boolean>('open', { default: false });
const { canEdit } = usePermission();

const intel = ref<TIntel | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    const answer = await jsonFetch<TIntel>(SystemIntelController.show(mapSolarsystemId).url);
    loading.value = false;
    if (answer) intel.value = answer;
    else error.value = 'Could not load the intel.';
}
watch(open, (isOpen) => isOpen && void load(), { immediate: true });
watch(() => mapSolarsystemId, () => open.value && void load());

const KIND_LABELS: Record<string, string> = {
    astrahus: 'Astrahus',
    fortizar: 'Fortizar',
    keepstar: 'Keepstar',
    raitaru: 'Raitaru',
    azbel: 'Azbel',
    sotiyo: 'Sotiyo',
    athanor: 'Athanor',
    tatara: 'Tatara',
    pos: 'POS tower',
    other: 'Other',
};
const STATE_LABELS: Record<string, string> = {
    online: 'Online',
    low_power: 'Low power',
    abandoned: 'Abandoned',
    anchoring: 'Anchoring',
    armor_timer: 'Armor timer',
    hull_timer: 'Hull timer',
    offline: 'Offline',
    unanchoring: 'Unanchoring',
    gone: 'Gone',
};
const STATE_CLASSES: Record<string, string> = {
    online: 'bg-green-900/70 text-green-200',
    low_power: 'bg-amber-900/60 text-amber-200',
    abandoned: 'bg-neutral-700 text-neutral-200',
    anchoring: 'bg-sky-900/60 text-sky-200',
    armor_timer: 'bg-amber-900/70 text-amber-100',
    hull_timer: 'bg-red-900/70 text-red-100',
    offline: 'bg-neutral-800 text-neutral-300',
    unanchoring: 'bg-sky-900/60 text-sky-200',
    gone: 'bg-neutral-800 text-neutral-400 line-through',
};

/** EVE time, short: "Oct 24 20:05". */
function eveTime(iso: string | null): string {
    if (!iso) return '';
    const date = new Date(iso);
    return `${date.toLocaleString('en-GB', { month: 'short', day: '2-digit', timeZone: 'UTC' })} ${date.toISOString().slice(11, 16)}`;
}
function ago(iso: string): string {
    const minutes = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60_000));
    if (minutes < 60) return `${minutes} min ago`;
    if (minutes < 48 * 60) return `${Math.round(minutes / 60)} h ago`;
    return `${Math.round(minutes / 1440)} d ago`;
}
function pad(hour: number): string {
    return String(hour).padStart(2, '0');
}

const hourBars = computed(() => {
    const hours = intel.value?.assessment.hours ?? [];
    const max = Math.max(1, ...hours);
    const peak = intel.value?.assessment.peak;
    const inPeak = (hour: number) => (peak ? (hour - peak.from + 24) % 24 < 3 : false);
    return hours.map((count, hour) => ({ hour, count, height: `${Math.max(2, Math.round((count / max) * 56))}px`, peak: inPeak(hour) }));
});
const resident = computed(() => intel.value?.assessment.corporations[0] ?? null);
const structureName = (id: number | null) => {
    const structure = intel.value?.structures.find((candidate) => candidate.id === id);
    return structure ? `${KIND_LABELS[structure.kind] ?? structure.kind}${structure.name ? ` "${structure.name}"` : ''}` : null;
};

// --- Structures ------------------------------------------------------------

const editing = ref<number | 'new' | null>(null);
const form = reactive({
    kind: 'astrahus',
    name: '',
    owner: '',
    location: '',
    state: 'online',
    vulnerable_window: '',
    timer_at: '',
    fit: '',
    note: '',
});
const saving = ref(false);

function startStructure(structure: TStructure | null): void {
    editing.value = structure ? structure.id : 'new';
    Object.assign(form, {
        kind: structure?.kind ?? 'astrahus',
        name: structure?.name ?? '',
        owner: structure?.owner ?? '',
        location: structure?.location ?? '',
        state: structure?.state ?? 'online',
        vulnerable_window: structure?.vulnerable_window ?? '',
        timer_at: structure?.timer_at ? structure.timer_at.slice(0, 16) : '',
        fit: structure?.fit ?? '',
        note: structure?.note ?? '',
    });
}

function structureBody(): Record<string, string | null> {
    return {
        kind: form.kind,
        name: form.name.trim() || null,
        owner: form.owner.trim() || null,
        location: form.location.trim() || null,
        state: form.state,
        vulnerable_window: form.vulnerable_window.trim() || null,
        // The input is EVE time (UTC).
        timer_at: form.timer_at ? `${form.timer_at}:00Z` : null,
        fit: form.fit.trim() || null,
        note: form.note.trim() || null,
    };
}

async function saveStructure(): Promise<void> {
    saving.value = true;
    const target = editing.value;
    const answer =
        target === 'new'
            ? await jsonFetch(SystemIntelController.storeStructure(mapSolarsystemId).url, 'POST', structureBody())
            : await jsonFetch(SystemIntelController.updateStructure({ mapSolarsystem: mapSolarsystemId, structure: target as number }).url, 'PUT', structureBody());
    saving.value = false;
    if (!answer) {
        error.value = 'Could not save the structure.';
        return;
    }
    editing.value = null;
    await load();
}

/** "Seen now": the same details, a fresh time stamp in the log. */
async function seenNow(structure: TStructure): Promise<void> {
    startStructure(structure);
    editing.value = structure.id;
    await saveStructure();
}

async function removeStructure(structure: TStructure): Promise<void> {
    await jsonFetch(SystemIntelController.destroyStructure({ mapSolarsystem: mapSolarsystemId, structure: structure.id }).url, 'DELETE');
    await load();
}

// --- D-scan, fit, note, screenshot --------------------------------------------

const adding = ref<'dscan' | 'fit' | 'note' | 'screenshot' | null>(null);
const entryText = ref('');
const entryStructure = ref<number | null>(null);
const caption = ref('');
const image = ref<File | null>(null);
const imagePreview = computed(() => (image.value ? URL.createObjectURL(image.value) : null));

function startEntry(kind: 'dscan' | 'fit' | 'note' | 'screenshot', structureId: number | null = null): void {
    adding.value = kind;
    entryText.value = '';
    caption.value = '';
    image.value = null;
    entryStructure.value = structureId;
}

function onPaste(event: ClipboardEvent): void {
    if (adding.value !== 'screenshot') return;
    const file = [...(event.clipboardData?.files ?? [])].find((candidate) => candidate.type.startsWith('image/'));
    if (file) image.value = file;
}
useEventListener(() => (open.value ? document : null), 'paste', onPaste);
function onPick(event: Event): void {
    image.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

async function saveEntry(): Promise<void> {
    saving.value = true;
    let ok = false;
    if (adding.value === 'screenshot') {
        if (!image.value) {
            saving.value = false;
            return;
        }
        const body = new FormData();
        body.append('image', image.value);
        if (caption.value.trim()) body.append('caption', caption.value.trim());
        if (entryStructure.value) body.append('intel_structure_id', String(entryStructure.value));
        const token = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
        try {
            const response = await fetch(SystemIntelController.storeScreenshot(mapSolarsystemId).url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': token ? decodeURIComponent(token[1]) : '', ...driverHeaders() },
                body,
            });
            ok = response.ok;
        } catch {
            ok = false;
        }
    } else if (adding.value && entryText.value.trim()) {
        ok = Boolean(
            await jsonFetch(SystemIntelController.storeEntry(mapSolarsystemId).url, 'POST', {
                kind: adding.value,
                body: entryText.value,
                intel_structure_id: entryStructure.value,
            }),
        );
    }
    saving.value = false;
    if (!ok) {
        error.value = 'Could not save it.';
        return;
    }
    adding.value = null;
    await load();
}

const PLACEHOLDERS: Record<string, string> = {
    dscan: 'Paste the D-scan window here (select all, Ctrl+C in EVE)',
    fit: 'Paste a ship scan or the fitting (one module per line)',
    note: 'What you saw: who, how many, what they fly, anything that matters later',
};
const KIND_BADGES: Record<string, string> = {
    structure: 'bg-red-900/60 text-red-100',
    dscan: 'bg-sky-900/60 text-sky-100',
    fit: 'bg-violet-900/60 text-violet-100',
    screenshot: 'bg-amber-900/60 text-amber-100',
    note: 'bg-neutral-700 text-neutral-100',
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogScrollContent local class="w-[min(1180px,96vw)] max-w-[min(1180px,96vw)] gap-3 p-4 sm:max-w-[min(1180px,96vw)]">
            <div class="flex flex-wrap items-baseline gap-2 pr-8">
                <DialogTitle class="text-base">Intel · {{ systemLabel }}</DialogTitle>
                <DialogDescription class="text-xs">Structures, scans and screenshots, kept for good. A first version: tell Tsa what's missing.</DialogDescription>
            </div>

            <p v-if="error" class="rounded bg-red-950/60 px-2 py-1 text-xs text-red-200">{{ error }}</p>
            <p v-if="loading && !intel" class="text-xs text-muted-foreground">Loading…</p>

            <div v-if="intel" class="grid gap-4 lg:grid-cols-2">
                <!-- Left: who lives here, structures -->
                <div class="flex min-w-0 flex-col gap-4">
                    <section class="flex flex-col gap-2">
                        <h3 class="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                            Who lives here <span class="font-normal normal-case">· {{ intel.assessment.kills }} kills since {{ intel.assessment.since }}</span>
                        </h3>
                        <div class="grid gap-2 sm:grid-cols-3">
                            <div class="flex flex-col gap-0.5 rounded-md border border-border bg-muted/30 p-2">
                                <span class="text-[11px] text-muted-foreground">Seen most</span>
                                <span class="truncate font-semibold">{{ resident?.name ?? 'nobody yet' }}</span>
                                <span v-if="resident" class="text-[11px] text-muted-foreground"
                                    >{{ resident.days }} days · {{ resident.kills }} kills · {{ resident.losses }} losses</span
                                >
                            </div>
                            <div class="flex flex-col gap-0.5 rounded-md border border-border bg-muted/30 p-2">
                                <span class="text-[11px] text-muted-foreground">Busiest (EVE time)</span>
                                <span class="font-semibold">{{ intel.assessment.peak ? `${pad(intel.assessment.peak.from)}:00 – ${pad(intel.assessment.peak.to)}:00` : '—' }}</span>
                            </div>
                            <div class="flex flex-col gap-0.5 rounded-md border border-border bg-muted/30 p-2">
                                <span class="text-[11px] text-muted-foreground">Fleet size</span>
                                <span class="font-semibold">{{ intel.assessment.fleet ? `about ${intel.assessment.fleet.median}` : '—' }}</span>
                                <span v-if="intel.assessment.fleet" class="text-[11px] text-muted-foreground">largest {{ intel.assessment.fleet.max }}</span>
                            </div>
                        </div>
                        <div v-if="intel.assessment.kills" class="rounded-md border border-border bg-muted/30 p-2">
                            <div class="flex h-14 items-end gap-0.5">
                                <div
                                    v-for="bar in hourBars"
                                    :key="bar.hour"
                                    class="flex-1 rounded-sm"
                                    :class="bar.peak ? 'bg-red-500' : 'bg-neutral-600'"
                                    :style="{ height: bar.height }"
                                    :title="`${pad(bar.hour)}:00 EVE · ${bar.count} kill${bar.count === 1 ? '' : 's'}`"
                                />
                            </div>
                            <div class="mt-1 flex justify-between font-mono text-[10px] text-muted-foreground"><span>00</span><span>06</span><span>12</span><span>18</span><span>23</span></div>
                        </div>
                        <div v-if="intel.assessment.corporations.length > 1 || intel.assessment.ships.length" class="flex flex-col gap-1 text-xs">
                            <span v-if="intel.assessment.corporations.length > 1" class="text-muted-foreground"
                                >Also here: {{ intel.assessment.corporations.slice(1).map((corp) => `${corp.name} (${corp.days} d)`).join(', ') }}</span
                            >
                            <span v-if="intel.assessment.ships.length" class="text-muted-foreground"
                                >They fly: {{ intel.assessment.ships.map((ship) => `${ship.name} ×${ship.count}`).join(', ') }}</span
                            >
                        </div>
                    </section>

                    <section class="flex flex-col gap-2">
                        <div class="flex items-center gap-2">
                            <h3 class="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">Structures</h3>
                            <span class="rounded-full bg-muted px-2 text-[11px]">{{ intel.structures.length }}</span>
                            <div class="flex-1" />
                            <button v-if="canEdit && editing === null" type="button" class="rounded bg-red-800 px-2 py-1 text-xs font-semibold text-red-50 hover:bg-red-700" @click="startStructure(null)">
                                + Structure / POS
                            </button>
                        </div>

                        <form v-if="editing !== null" class="flex flex-col gap-2 rounded-md border border-red-900/70 bg-muted/30 p-3 text-xs" @submit.prevent="saveStructure">
                            <div class="flex flex-wrap gap-1">
                                <button
                                    v-for="kind in intel.kinds"
                                    :key="kind"
                                    type="button"
                                    class="rounded border px-2 py-1"
                                    :class="form.kind === kind ? 'border-red-600 bg-red-950 text-red-100' : 'border-border text-muted-foreground'"
                                    @click="form.kind = kind"
                                >
                                    {{ KIND_LABELS[kind] ?? kind }}
                                </button>
                            </div>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <label class="flex flex-col gap-1 text-muted-foreground">Name<input v-model="form.name" class="rounded border border-border bg-background px-2 py-1 text-foreground" /></label>
                                <label class="flex flex-col gap-1 text-muted-foreground">Owner (corp / alliance)<input v-model="form.owner" class="rounded border border-border bg-background px-2 py-1 text-foreground" /></label>
                                <label class="flex flex-col gap-1 text-muted-foreground">Where (planet, moon)<input v-model="form.location" class="rounded border border-border bg-background px-2 py-1 text-foreground" /></label>
                                <label class="flex flex-col gap-1 text-muted-foreground">Vulnerable window<input v-model="form.vulnerable_window" placeholder="Sun 19:00 – 22:00" class="rounded border border-border bg-background px-2 py-1 text-foreground" /></label>
                            </div>
                            <div class="flex flex-wrap gap-1">
                                <button
                                    v-for="state in intel.states"
                                    :key="state"
                                    type="button"
                                    class="rounded border px-2 py-1"
                                    :class="form.state === state ? 'border-amber-500 bg-amber-950 text-amber-100' : 'border-border text-muted-foreground'"
                                    @click="form.state = state"
                                >
                                    {{ STATE_LABELS[state] ?? state }}
                                </button>
                            </div>
                            <label v-if="form.state === 'armor_timer' || form.state === 'hull_timer' || form.state === 'anchoring' || form.state === 'unanchoring'" class="flex flex-col gap-1 text-muted-foreground"
                                >Timer ends (EVE time)<input v-model="form.timer_at" type="datetime-local" class="rounded border border-border bg-background px-2 py-1 text-foreground"
                            /></label>
                            <label class="flex flex-col gap-1 text-muted-foreground"
                                >Fit (paste a scan or the fitting)<textarea v-model="form.fit" rows="4" class="rounded border border-border bg-background px-2 py-1 font-mono text-foreground"
                            /></label>
                            <label class="flex flex-col gap-1 text-muted-foreground">Note<input v-model="form.note" class="rounded border border-border bg-background px-2 py-1 text-foreground" /></label>
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] text-muted-foreground">Saved now, in the log; older versions stay in the log.</span>
                                <div class="flex-1" />
                                <button type="button" class="rounded border border-border px-3 py-1" @click="editing = null">Cancel</button>
                                <button type="submit" :disabled="saving" class="rounded bg-red-700 px-3 py-1 font-semibold text-white disabled:opacity-50">Save</button>
                            </div>
                        </form>

                        <p v-if="!intel.structures.length && editing === null" class="text-xs text-muted-foreground">None noted yet.</p>
                        <div v-for="structure in intel.structures" :key="structure.id" class="flex flex-col gap-1 rounded-md border border-border bg-muted/30 p-2 text-xs">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold">{{ KIND_LABELS[structure.kind] ?? structure.kind }}</span>
                                <span v-if="structure.name">{{ structure.name }}</span>
                                <span class="rounded px-1.5 text-[11px] font-semibold" :class="STATE_CLASSES[structure.state]">{{ STATE_LABELS[structure.state] ?? structure.state }}</span>
                                <div class="flex-1" />
                                <span class="text-[11px] text-muted-foreground">seen {{ ago(structure.seen_at) }}<template v-if="structure.updated_by_name"> · {{ structure.updated_by_name }}</template></span>
                            </div>
                            <div class="flex flex-wrap gap-x-3 text-muted-foreground">
                                <span v-if="structure.owner">Owner <b class="text-foreground">{{ structure.owner }}</b></span>
                                <span v-if="structure.location">{{ structure.location }}</span>
                                <span v-if="structure.vulnerable_window">Vulnerable <b class="text-amber-300">{{ structure.vulnerable_window }}</b></span>
                                <span v-if="structure.timer_at">Timer ends <b class="text-amber-300">{{ eveTime(structure.timer_at) }}</b></span>
                                <span v-if="structure.fit">Fit: {{ structure.fit.split('\n').filter(Boolean).length }} modules</span>
                            </div>
                            <p v-if="structure.note" class="text-muted-foreground">{{ structure.note }}</p>
                            <div v-if="canEdit" class="flex flex-wrap gap-1">
                                <button type="button" class="rounded border border-border px-2 py-0.5 hover:bg-muted" @click="seenNow(structure)">Seen now</button>
                                <button type="button" class="rounded border border-border px-2 py-0.5 hover:bg-muted" @click="startStructure(structure)">Edit</button>
                                <button type="button" class="rounded border border-border px-2 py-0.5 hover:bg-muted" @click="startEntry('fit', structure.id)">Fit</button>
                                <button type="button" class="rounded border border-border px-2 py-0.5 hover:bg-muted" @click="startEntry('screenshot', structure.id)">Screenshot</button>
                                <button type="button" class="rounded border border-border px-2 py-0.5 text-red-300 hover:bg-red-950" @click="removeStructure(structure)">Remove</button>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- Right: add scans / screenshots / notes, and the log -->
                <div class="flex min-w-0 flex-col gap-4">
                    <section v-if="canEdit" class="flex flex-col gap-2">
                        <div class="flex flex-wrap items-center gap-1">
                            <h3 class="mr-1 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">Add</h3>
                            <button type="button" class="rounded border border-border px-2 py-1 text-xs hover:bg-muted" @click="startEntry('dscan')">D-scan</button>
                            <button type="button" class="rounded border border-border px-2 py-1 text-xs hover:bg-muted" @click="startEntry('fit')">Fit</button>
                            <button type="button" class="rounded border border-border px-2 py-1 text-xs hover:bg-muted" @click="startEntry('screenshot')">Screenshot</button>
                            <button type="button" class="rounded border border-border px-2 py-1 text-xs hover:bg-muted" @click="startEntry('note')">Note</button>
                        </div>
                        <form v-if="adding" class="flex flex-col gap-2 rounded-md border border-border bg-muted/30 p-3 text-xs" @submit.prevent="saveEntry">
                            <template v-if="adding === 'screenshot'">
                                <div class="flex flex-wrap items-center gap-2">
                                    <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="text-xs" @change="onPick" />
                                    <span class="text-muted-foreground">or paste an image here (Ctrl+V)</span>
                                </div>
                                <img v-if="imagePreview" :src="imagePreview" alt="Screenshot to save" class="max-h-48 rounded border border-border object-contain" />
                                <input v-model="caption" placeholder="What it shows (e.g. Fortizar location at planet IV)" class="rounded border border-border bg-background px-2 py-1" />
                            </template>
                            <textarea v-else v-model="entryText" rows="6" :placeholder="PLACEHOLDERS[adding]" class="rounded border border-border bg-background px-2 py-1 font-mono" />
                            <label v-if="intel.structures.length && adding !== 'dscan'" class="flex items-center gap-2 text-muted-foreground"
                                >For
                                <select v-model="entryStructure" class="rounded border border-border bg-background px-1 py-0.5 text-foreground">
                                    <option :value="null">the system</option>
                                    <option v-for="structure in intel.structures" :key="structure.id" :value="structure.id">{{ structureName(structure.id) }}</option>
                                </select>
                            </label>
                            <div class="flex justify-end gap-2">
                                <button type="button" class="rounded border border-border px-3 py-1" @click="adding = null">Cancel</button>
                                <button type="submit" :disabled="saving" class="rounded bg-red-700 px-3 py-1 font-semibold text-white disabled:opacity-50">Save</button>
                            </div>
                        </form>
                    </section>

                    <section class="flex flex-col gap-2">
                        <h3 class="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">Log <span class="font-normal normal-case">· kept for good</span></h3>
                        <p v-if="!intel.entries.length" class="text-xs text-muted-foreground">Nothing yet.</p>
                        <div v-for="entry in intel.entries" :key="entry.id" class="flex flex-col gap-1 border-b border-border/50 pb-2 text-xs">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-muted-foreground">{{ eveTime(entry.created_at) }}</span>
                                <span class="rounded px-1.5 text-[10px] font-semibold uppercase" :class="KIND_BADGES[entry.kind]">{{ entry.kind === 'dscan' ? 'D-scan' : entry.kind }}</span>
                                <span v-if="entry.author_name" class="text-muted-foreground">{{ entry.author_name }}</span>
                                <span v-if="structureName(entry.intel_structure_id) && entry.kind !== 'structure'" class="text-muted-foreground">· {{ structureName(entry.intel_structure_id) }}</span>
                            </div>
                            <template v-if="entry.kind === 'dscan' && entry.data">
                                <span
                                    >{{ entry.data.objects }} objects<template v-if="entry.data.on_grid"> · {{ entry.data.on_grid }} on grid</template>:
                                    {{ (entry.data.types ?? []).slice(0, 12).map((type) => `${type.count}× ${type.type}`).join(', ') }}</span
                                >
                                <details>
                                    <summary class="cursor-pointer text-muted-foreground">The whole scan</summary>
                                    <pre class="max-h-60 overflow-auto font-mono whitespace-pre-wrap">{{ entry.body }}</pre>
                                </details>
                            </template>
                            <pre v-else-if="entry.kind === 'fit'" class="font-mono whitespace-pre-wrap">{{ entry.body }}</pre>
                            <a v-else-if="entry.kind === 'screenshot' && entry.image_url" :href="entry.image_url" target="_blank" rel="noopener" class="flex flex-col gap-1">
                                <img :src="entry.image_url" :alt="entry.body || 'Screenshot'" class="max-h-56 self-start rounded border border-border object-contain" loading="lazy" />
                                <span v-if="entry.body" class="text-muted-foreground">{{ entry.body }}</span>
                            </a>
                            <span v-else class="whitespace-pre-wrap">{{ entry.body }}</span>
                        </div>
                    </section>
                </div>
            </div>
        </DialogScrollContent>
    </Dialog>
</template>
