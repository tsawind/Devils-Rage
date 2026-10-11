<script setup lang="ts">
import JumpInController from '@/actions/App/Http/Controllers/JumpInController';
import { useNavigationSystems } from '@/composables/useNavigationSystems';
import { useRouteCopy } from '@/composables/useRouteCopy';
import useUser from '@/composables/useUser';
import { useWaypoint } from '@/composables/useWaypoint';
import { lightyears, type TPaletteCommand } from '@/lib/paletteCommand';
import { ComboboxGroup, ComboboxItem, ComboboxLabel } from 'reka-ui';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Patch 37: actions for the system you typed in the search: set it as destination in game
 * (your active character or all your online ones), route to it, copy the route, add it to
 * the map, and "Jump in": a Black Ops bridge or a carrier conduit jump from the chain's
 * k-space exits, with your own pilots' skills.
 */
type TTarget = { id: number; name: string; security: number; class: string | number | null };
type TExit = { from: string; from_id: number; alias: string | null; ly: number; direct: boolean; via: { id: number; name: string; gates: number } | null; highsec: boolean };
type TKind = { key: 'blops' | 'carrier'; label: string; range_ly: number; assumed: boolean; exits: TExit[] };
type TPilot = { name: string; ship: string | null; skills_read: boolean; jdc: number; blops_ly: number; carrier_ly: number; conduit_ships: number; command_ships: number | null; advice: string | null };
type TJumpIn =
    | { ok: true; target: { id: number; name: string; security: number }; landing: { id: number; name: string; gates: number }; kinds: TKind[]; pilots: TPilot[] }
    | { ok: false; reason: string };

const { target, command, mapSlug, onMap, canWrite } = defineProps<{
    target: TTarget;
    command: TPaletteCommand;
    mapSlug: string;
    onMap: boolean;
    canWrite: boolean;
}>();
const emit = defineEmits<{ close: []; add: [solarsystemId: number] }>();

const user = useUser();
const { setWaypoint, setWaypointAll, onlineCharacters } = useWaypoint();
const { setToSystem } = useNavigationSystems();
const { copyRoute, routeText } = useRouteCopy();

const item = 'col-span-full flex cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-none select-none data-[disabled]:opacity-50 data-[highlighted]:bg-accent';
const label = 'col-span-full px-2 py-1.5 font-sans font-semibold text-[11px] tracking-wider text-muted-foreground uppercase';

const kspace = computed(() => ['h', 'l', 'n'].includes(String(target.class ?? '').toLowerCase()));
const security = computed(() => (kspace.value ? target.security.toFixed(1) : `C${target.class}`));
const active = computed(() => user.value?.active_character ?? null);

// The route's jump count (Default route), worked out in the background.
const jumps = ref<string | null>(null);
// Jump in: asked from the server once the typing settles on a k-space system.
const jumpIn = ref<TJumpIn | null>(null);
let timer: number | null = null;
watch(
    () => target.id,
    (id) => {
        jumps.value = null;
        jumpIn.value = null;
        if (timer !== null) window.clearTimeout(timer);
        timer = window.setTimeout(() => {
            void routeText('default', id).then((text) => {
                if (id === target.id) jumps.value = /(\d+)j/.exec(text ?? '')?.[1] ?? null;
            });
            if (kspace.value) void loadJumpIn(id);
        }, 300);
    },
    { immediate: true },
);

async function loadJumpIn(id: number): Promise<void> {
    try {
        const response = await fetch(JumpInController.show(mapSlug, { query: { to: id } }).url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!response.ok || id !== target.id) return;
        jumpIn.value = (await response.json()) as TJumpIn;
    } catch {
        jumpIn.value = null;
    }
}

const kinds = computed(() => {
    if (!jumpIn.value?.ok) return [];
    const list = jumpIn.value.kinds;
    return command === 'carrier' ? [...list].reverse() : list;
});

function exitLine(exit: TExit, kind: TKind): string {
    const name = exit.alias && exit.alias !== exit.from ? `${exit.alias} ${exit.from}` : exit.from;
    if (exit.direct) return `${name}: ${lightyears(exit.ly)} ✓ jump straight in`;
    if (exit.via) return `${name}: ${exit.via.gates} ${exit.via.gates === 1 ? 'gate' : 'gates'} to ${exit.via.name}, then jump${exit.highsec ? ' (no jumping out of highsec)' : ''}`;
    return `${name}: ${lightyears(exit.ly)}, nothing within ${lightyears(kind.range_ly)} in 12 gates`;
}

async function copyPlan(exit: TExit, kind: TKind): Promise<void> {
    if (!jumpIn.value?.ok) return;
    const landing = jumpIn.value.landing;
    const into = landing.id === target.id ? target.name : `${landing.name} (${landing.gates} ${landing.gates === 1 ? 'gate' : 'gates'} from ${target.name}, no cyno in ${target.name})`;
    const text = `${kind.label} to ${into}: ${exitLine(exit, kind)}`;
    try {
        await navigator.clipboard.writeText(text);
        toast.success('Jump plan copied', { description: text });
    } catch {
        toast.message('Jump plan', { description: text });
    }
    emit('close');
}

function destinationMe(): void {
    if (!active.value) return;
    setWaypoint(active.value.id, target.id);
    emit('close');
}

function destinationAll(): void {
    setWaypointAll(target.id);
    emit('close');
}

function routeTo(): void {
    setToSystem(target.id);
    toast.success(`Route To: ${target.name}`);
    emit('close');
}

function copy(): void {
    void copyRoute('default', target.id);
    emit('close');
}

const order = computed(() => (command === 'blops' || command === 'carrier' ? ['jump', 'actions'] : ['actions', 'jump']));
</script>

<template>
    <template v-for="section in order" :key="section">
        <ComboboxGroup v-if="section === 'actions'" class="col-span-full grid grid-cols-subgrid">
            <ComboboxLabel :class="label">
                {{ target.name }} <span class="font-mono normal-case">· {{ security }}</span>
            </ComboboxLabel>
            <ComboboxItem v-if="active" :value="`dest-me-${target.id}`" :class="item" @select.prevent="destinationMe">
                <span class="w-4 text-center text-muted-foreground">⌖</span>Set destination for {{ active.name }}
            </ComboboxItem>
            <ComboboxItem v-if="onlineCharacters.length > 1" :value="`dest-all-${target.id}`" :class="item" @select.prevent="destinationAll">
                <span class="w-4 text-center text-muted-foreground">⌖</span>Set destination for all my online characters ({{ onlineCharacters.length }})
            </ComboboxItem>
            <ComboboxItem :value="`route-${target.id}`" :class="item" @select.prevent="routeTo">
                <span class="w-4 text-center text-muted-foreground">⇢</span>Route to {{ target.name }}
                <span class="ml-auto font-mono text-[11px] text-muted-foreground">{{ jumps !== null ? `${jumps}j · ` : '' }}set as To</span>
            </ComboboxItem>
            <ComboboxItem :value="`copy-${target.id}`" :class="item" @select.prevent="copy">
                <span class="w-4 text-center text-muted-foreground">⧉</span>Copy route for fleet chat
            </ComboboxItem>
            <ComboboxItem v-if="!onMap && canWrite" :value="`add-${target.id}`" :class="item" @select.prevent="emit('add', target.id)">
                <span class="w-4 text-center text-muted-foreground">＋</span>Add to map
            </ComboboxItem>
        </ComboboxGroup>

        <ComboboxGroup v-else-if="kspace" class="col-span-full grid grid-cols-subgrid">
            <ComboboxLabel :class="label">Jump in · from the chain's k-space exits</ComboboxLabel>
            <div v-if="!jumpIn" class="col-span-full px-2 py-1 text-xs text-muted-foreground">Working out jump ranges…</div>
            <div v-else-if="!jumpIn.ok" class="col-span-full px-2 py-1 text-xs text-muted-foreground">{{ jumpIn.reason }}</div>
            <template v-else>
                <div v-if="jumpIn.landing.id !== target.id" class="col-span-full px-2 py-1 text-xs text-amber-400">
                    No cyno in {{ target.name }}: land in {{ jumpIn.landing.name }} ({{ jumpIn.landing.gates }} {{ jumpIn.landing.gates === 1 ? 'gate' : 'gates' }} away).
                </div>
                <template v-for="kind in kinds" :key="kind.key">
                    <div class="col-span-full px-2 pt-1 text-xs">
                        <span class="font-medium">{{ kind.label }}</span>
                        <span class="ml-1 font-mono text-muted-foreground">≤ {{ lightyears(kind.range_ly) }}{{ kind.assumed ? ' (assumes JDC V)' : '' }}</span>
                    </div>
                    <ComboboxItem
                        v-for="exit in kind.exits.slice(0, 3)"
                        :key="`${kind.key}-${exit.from_id}`"
                        :value="`jump-${kind.key}-${exit.from_id}`"
                        :class="item"
                        @select.prevent="copyPlan(exit, kind)"
                    >
                        <span class="truncate pl-5 font-mono text-xs" :class="exit.direct ? 'text-emerald-400' : exit.via ? 'text-foreground' : 'text-muted-foreground'">{{
                            exitLine(exit, kind)
                        }}</span>
                        <span class="ml-auto text-[11px] text-muted-foreground">copy</span>
                    </ComboboxItem>
                    <div v-if="!kind.exits.length" class="col-span-full pl-7 text-xs text-muted-foreground">No k-space exits on the map.</div>
                </template>
                <div class="col-span-full grid gap-0.5 px-2 py-1 text-[11px] text-muted-foreground">
                    <span v-for="pilot in jumpIn.pilots" :key="pilot.name">
                        <template v-if="pilot.skills_read">
                            {{ pilot.name }}: JDC {{ pilot.jdc }} · BLOPS {{ lightyears(pilot.blops_ly) }} · carrier {{ lightyears(pilot.carrier_ly) }} · conduit
                            {{ pilot.conduit_ships }} ships<template v-if="pilot.command_ships"> ({{ pilot.command_ships }} in a command carrier)</template>
                            <span v-if="pilot.advice" class="text-amber-400"> · {{ pilot.advice }}</span>
                        </template>
                        <template v-else>{{ pilot.name }}: skills not shared (Settings → EVE characters → Grant all permissions)</template>
                    </span>
                </div>
            </template>
        </ComboboxGroup>
    </template>
</template>
