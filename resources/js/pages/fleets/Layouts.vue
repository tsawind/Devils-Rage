<script setup lang="ts">
import FleetLayoutController from '@/actions/App/Http/Controllers/FleetLayoutController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import SeoHead from '@/layouts/SeoHead.vue';
import { Copy, Plus, Rocket, Save, Trash2, Users, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Patch 67: fleet layouts. Wings and squads with who goes where (commanders too), kept for
 * yourself or shared with the corp. The fleet boss presses Build fleet: the mapper makes the
 * wings and squads, names them, and invites everyone to their place through EVE's fleet API.
 * Each pilot still says yes to the invite in their own client.
 */
type TPerson = { id: number; name: string };
type TSquad = { name: string; commander: TPerson | null; members: TPerson[] };
type TWing = { name: string; commander: TPerson | null; squads: TSquad[] };
type TLayoutBody = { wings: TWing[] };
type TLayout = { id: number; name: string; shared: boolean; mine: boolean; owner: string | null; layout: TLayoutBody; updated_at: string };
type TResult = { id: number; name: string; place: string; outcome: 'invited' | 'moved' | 'already' | 'boss' | 'failed'; detail: string | null };
type TBuild = { ok: boolean; message: string; boss?: string; results?: TResult[] };

const props = defineProps<{
    layouts: TLayout[];
    characters: { id: number; name: string; can_boss: boolean }[];
}>();

const list = ref<TLayout[]>(props.layouts.map((layout) => ({ ...layout, layout: structuredClone(layout.layout) })));
const blank = (): TLayoutBody => ({ wings: [{ name: 'Wing 1', commander: null, squads: [{ name: 'Squad 1', commander: null, members: [] }] }] });
const selectedId = ref<number | null>(list.value[0]?.id ?? null);
const draft = ref<{ id: number | null; name: string; shared: boolean; mine: boolean; layout: TLayoutBody }>(
    list.value[0] ? { id: list.value[0].id, name: list.value[0].name, shared: list.value[0].shared, mine: list.value[0].mine, layout: structuredClone(list.value[0].layout) } : { id: null, name: 'New fleet', shared: false, mine: true, layout: blank() },
);
const said = ref('');
const errorText = ref('');
const busy = ref(false);
const build = ref<TBuild | null>(null);
const pasteFor = ref<string | null>(null);
const pasteText = ref('');
const dragging = ref<{ w: number; s: number; m: number } | null>(null);

const total = computed(() =>
    draft.value.layout.wings.reduce((sum, wing) => sum + (wing.commander ? 1 : 0) + wing.squads.reduce((n, squad) => n + (squad.commander ? 1 : 0) + squad.members.length, 0), 0),
);
const bosses = computed(() => props.characters.filter((character) => character.can_boss));

function xsrf(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function send<T>(url: string, method: string, body?: unknown): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const errors = (data as { errors?: Record<string, string[]>; message?: string }).errors;
        throw new Error(errors ? Object.values(errors).flat()[0] : ((data as { message?: string }).message ?? `The mapper said ${response.status}.`));
    }
    return data as T;
}

function pick(layout: TLayout) {
    selectedId.value = layout.id;
    draft.value = { id: layout.id, name: layout.name, shared: layout.shared, mine: layout.mine, layout: structuredClone(layout.layout) };
    build.value = null;
    said.value = '';
    errorText.value = '';
}

function startNew() {
    selectedId.value = null;
    draft.value = { id: null, name: 'New fleet', shared: false, mine: true, layout: blank() };
    build.value = null;
}

async function save(asCopy = false) {
    errorText.value = '';
    busy.value = true;
    try {
        const body = { name: asCopy ? `${draft.value.name} (copy)`.slice(0, 60) : draft.value.name, shared: asCopy ? false : draft.value.shared, layout: draft.value.layout };
        const updating = draft.value.id !== null && draft.value.mine && !asCopy;
        const { id } = updating
            ? await send<{ id: number }>(FleetLayoutController.update(draft.value.id as number).url, 'PUT', body)
            : await send<{ id: number }>(FleetLayoutController.store().url, 'POST', body);
        const saved: TLayout = { id, name: body.name, shared: body.shared, mine: true, owner: null, layout: structuredClone(body.layout), updated_at: new Date().toISOString() };
        list.value = [...list.value.filter((layout) => layout.id !== id), saved].sort((a, b) => Number(b.shared) - Number(a.shared) || a.name.localeCompare(b.name));
        pick(saved);
        said.value = 'Saved.';
    } catch (error) {
        errorText.value = (error as Error).message;
    } finally {
        busy.value = false;
    }
}

async function remove() {
    if (draft.value.id === null || !draft.value.mine) return;
    busy.value = true;
    try {
        await send(FleetLayoutController.destroy(draft.value.id).url, 'DELETE');
        list.value = list.value.filter((layout) => layout.id !== draft.value.id);
        if (list.value[0]) pick(list.value[0]);
        else startNew();
    } catch (error) {
        errorText.value = (error as Error).message;
    } finally {
        busy.value = false;
    }
}

async function buildFleet() {
    if (draft.value.id === null) {
        errorText.value = 'Save the layout first.';
        return;
    }
    errorText.value = '';
    busy.value = true;
    build.value = null;
    try {
        build.value = await send<TBuild>(FleetLayoutController.build(draft.value.id).url, 'POST');
    } catch (error) {
        errorText.value = (error as Error).message;
    } finally {
        busy.value = false;
    }
}

// ---- Editing ----
const addWing = () => draft.value.layout.wings.push({ name: `Wing ${draft.value.layout.wings.length + 1}`, commander: null, squads: [{ name: 'Squad 1', commander: null, members: [] }] });
const addSquad = (wing: TWing) => wing.squads.push({ name: `Squad ${wing.squads.length + 1}`, commander: null, members: [] });

async function resolve(text: string): Promise<TPerson[]> {
    const names = text
        .split(/[\n,;\t]+/)
        .map((name) => name.trim())
        .filter(Boolean);
    if (!names.length) return [];
    const result = await send<{ characters: TPerson[]; not_found: string[] }>(FleetLayoutController.resolve().url, 'POST', { names });
    if (result.not_found.length) errorText.value = `Not found in EVE: ${result.not_found.join(', ')}`;
    return result.characters;
}

async function addPasted(w: number, s: number) {
    errorText.value = '';
    try {
        const people = await resolve(pasteText.value);
        const squad = draft.value.layout.wings[w].squads[s];
        const placed = new Set(allPeople().map((person) => person.id));
        people.filter((person) => !placed.has(person.id)).forEach((person) => squad.members.push(person));
        pasteText.value = '';
        pasteFor.value = null;
    } catch (error) {
        errorText.value = (error as Error).message;
    }
}

async function setCommander(target: { commander: TPerson | null }, text: string) {
    errorText.value = '';
    if (!text.trim()) {
        target.commander = null;
        return;
    }
    try {
        const [person] = await resolve(text);
        if (person) target.commander = person;
    } catch (error) {
        errorText.value = (error as Error).message;
    }
}

function allPeople(): TPerson[] {
    return draft.value.layout.wings.flatMap((wing) => [
        ...(wing.commander ? [wing.commander] : []),
        ...wing.squads.flatMap((squad) => [...(squad.commander ? [squad.commander] : []), ...squad.members]),
    ]);
}

function dropOn(w: number, s: number) {
    const from = dragging.value;
    dragging.value = null;
    if (!from || (from.w === w && from.s === s)) return;
    const [person] = draft.value.layout.wings[from.w].squads[from.s].members.splice(from.m, 1);
    if (person) draft.value.layout.wings[w].squads[s].members.push(person);
}

const outcomeClass: Record<TResult['outcome'], string> = {
    invited: 'text-green-400',
    moved: 'text-sky-400',
    already: 'text-muted-foreground',
    boss: 'text-muted-foreground',
    failed: 'text-red-400',
};
const outcomeLabel: Record<TResult['outcome'], string> = { invited: 'Invited', moved: 'Moved', already: 'In place', boss: 'Boss', failed: 'Failed' };
</script>

<template>
    <AppLayout>
        <SeoHead title="Fleets" description="Fleet layouts: wings, squads and who goes where, built and invited in one go." keywords="eve online fleet, fleet invite, wings, squads" />
        <div class="grid gap-6 p-6 lg:grid-cols-[260px_1fr]">
            <aside class="flex flex-col gap-2">
                <h1 class="flex items-center gap-2 font-display text-2xl font-bold tracking-tight"><Users class="h-5 w-5" /> Fleets</h1>
                <p class="text-xs text-muted-foreground">Save who goes where, then build the whole fleet in one go as fleet boss.</p>
                <Button size="sm" variant="outline" class="justify-start gap-2" @click="startNew"><Plus class="h-4 w-4" /> New layout</Button>
                <button
                    v-for="layout in list"
                    :key="layout.id"
                    type="button"
                    class="rounded border px-3 py-2 text-left text-sm"
                    :class="selectedId === layout.id ? 'border-primary bg-muted/50' : 'border-border hover:bg-muted/30'"
                    @click="pick(layout)"
                >
                    <div class="font-semibold">{{ layout.shared ? '★ ' : '' }}{{ layout.name }}</div>
                    <div class="text-xs text-muted-foreground">{{ layout.mine ? (layout.shared ? 'yours, shared with the corp' : 'yours') : `shared by ${layout.owner ?? 'someone'}` }}</div>
                </button>
            </aside>

            <section class="flex min-w-0 flex-col gap-4">
                <div class="flex flex-wrap items-center gap-3">
                    <Input v-model="draft.name" maxlength="60" class="max-w-xs font-semibold" :disabled="!draft.mine" />
                    <label class="flex items-center gap-2 text-sm" :class="{ 'opacity-50': !draft.mine }">
                        <input v-model="draft.shared" type="checkbox" :disabled="!draft.mine" /> Share with the corp
                    </label>
                    <span class="text-sm text-muted-foreground">{{ total }} characters</span>
                    <span class="grow" />
                    <Button v-if="draft.mine" size="sm" class="gap-2" :disabled="busy" @click="save()"><Save class="h-4 w-4" /> Save</Button>
                    <Button size="sm" variant="outline" class="gap-2" :disabled="busy" @click="save(true)"><Copy class="h-4 w-4" /> Save a copy</Button>
                    <Button v-if="draft.mine && draft.id !== null" size="sm" variant="outline" class="gap-2" :disabled="busy" @click="remove"><Trash2 class="h-4 w-4" /> Delete</Button>
                </div>
                <p v-if="!draft.mine" class="text-sm text-muted-foreground">Shared by someone else: you can build it, or save a copy to change it.</p>
                <p v-if="errorText" class="text-sm text-red-400">{{ errorText }}</p>
                <p v-else-if="said" class="text-sm text-green-400">{{ said }}</p>

                <div class="rounded bg-card p-4 ring-1 ring-border">
                    <div class="flex flex-wrap items-center gap-3">
                        <Button class="gap-2" :disabled="busy || draft.id === null" @click="buildFleet"><Rocket class="h-4 w-4" /> Build fleet and invite</Button>
                        <span class="text-sm text-muted-foreground">
                            Start a fleet in game first (you as boss), then press this. Boss can be:
                            <b>{{ bosses.map((c) => c.name).join(', ') || 'none yet: give a character all permissions in Settings → EVE characters' }}</b>
                        </span>
                    </div>
                    <div v-if="build" class="mt-3">
                        <p :class="build.ok ? 'text-green-400' : 'text-red-400'" class="text-sm">{{ build.message }}<template v-if="build.boss"> (boss {{ build.boss }})</template></p>
                        <table v-if="build.results?.length" class="mt-2 w-full text-sm">
                            <tbody>
                                <tr v-for="row in build.results" :key="row.id + row.place" class="border-t border-border/50">
                                    <td class="py-1 pr-3 font-semibold">{{ row.name }}</td>
                                    <td class="py-1 pr-3 text-muted-foreground">{{ row.place }}</td>
                                    <td class="py-1 pr-3" :class="outcomeClass[row.outcome]">{{ outcomeLabel[row.outcome] }}</td>
                                    <td class="py-1 text-muted-foreground">{{ row.detail }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex flex-col gap-4">
                    <div v-for="(wing, w) in draft.layout.wings" :key="w" class="rounded bg-card p-4 ring-1 ring-border">
                        <div class="flex flex-wrap items-center gap-3">
                            <Input v-model="wing.name" maxlength="10" class="w-32 font-semibold" :disabled="!draft.mine" />
                            <label class="flex items-center gap-2 text-sm text-muted-foreground">
                                Wing commander
                                <Input :model-value="wing.commander?.name ?? ''" placeholder="name" class="w-48" :disabled="!draft.mine" @change="(e: Event) => setCommander(wing, (e.target as HTMLInputElement).value)" />
                            </label>
                            <span class="grow" />
                            <Button v-if="draft.mine" size="sm" variant="outline" class="gap-1" @click="addSquad(wing)"><Plus class="h-3 w-3" /> Squad</Button>
                            <Button v-if="draft.mine && draft.layout.wings.length > 1" size="sm" variant="ghost" @click="draft.layout.wings.splice(w, 1)"><X class="h-4 w-4" /></Button>
                        </div>
                        <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            <div v-for="(squad, s) in wing.squads" :key="s" class="rounded border border-border/60 p-3" @dragover.prevent @drop="dropOn(w, s)">
                                <div class="flex items-center gap-2">
                                    <Input v-model="squad.name" maxlength="10" class="h-8 w-28" :disabled="!draft.mine" />
                                    <span class="grow" />
                                    <Button v-if="draft.mine && wing.squads.length > 1" size="sm" variant="ghost" @click="wing.squads.splice(s, 1)"><X class="h-3 w-3" /></Button>
                                </div>
                                <label class="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
                                    Commander
                                    <Input :model-value="squad.commander?.name ?? ''" placeholder="name" class="h-7" :disabled="!draft.mine" @change="(e: Event) => setCommander(squad, (e.target as HTMLInputElement).value)" />
                                </label>
                                <div class="mt-2 flex flex-wrap gap-1">
                                    <span
                                        v-for="(person, m) in squad.members"
                                        :key="person.id"
                                        class="flex cursor-grab items-center gap-1 rounded-full border border-border px-2 py-0.5 text-xs"
                                        :draggable="draft.mine"
                                        @dragstart="dragging = { w, s, m }"
                                    >
                                        {{ person.name }}
                                        <button v-if="draft.mine" type="button" class="text-muted-foreground hover:text-red-400" @click="squad.members.splice(m, 1)"><X class="h-3 w-3" /></button>
                                    </span>
                                    <span v-if="!squad.members.length" class="text-xs text-muted-foreground">No members: add names, or drag someone here</span>
                                </div>
                                <template v-if="draft.mine">
                                    <div v-if="pasteFor === `${w}-${s}`" class="mt-2 flex flex-col gap-2">
                                        <textarea v-model="pasteText" rows="4" class="rounded border border-border bg-background p-2 text-xs" placeholder="One name per line (or paste a list from EVE)" />
                                        <div class="flex gap-2">
                                            <Button size="sm" @click="addPasted(w, s)">Add</Button>
                                            <Button size="sm" variant="ghost" @click="pasteFor = null">Cancel</Button>
                                        </div>
                                    </div>
                                    <Button v-else size="sm" variant="outline" class="mt-2 h-7 gap-1 text-xs" @click="pasteFor = `${w}-${s}`"><Plus class="h-3 w-3" /> Add names</Button>
                                </template>
                            </div>
                        </div>
                    </div>
                    <Button v-if="draft.mine" variant="outline" class="w-fit gap-2" @click="addWing"><Plus class="h-4 w-4" /> Wing</Button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
