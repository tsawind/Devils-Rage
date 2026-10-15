<script setup lang="ts">
import MapConnectionJumpController from '@/actions/App/Http/Controllers/MapConnectionJumpController';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import usePermission from '@/composables/usePermission';
import { displayAlias } from '@/lib/alias';
import { jsonFetch } from '@/lib/jsonFetch';
import { openAddMass, parseMillionKg } from '@/map/actions/addMass';
import { deleteMapConnectionJump } from '@/map/actions/deleteMapConnectionJump';
import { updateMapConnectionJump } from '@/map/actions/updateMapConnectionJump';
import type { TMapConnection, TMapSolarsystem } from '@/pages/maps';
import { computed, onMounted, ref, watch } from 'vue';

/**
 * Patch 37: the whole jump log of a pipe (click the mass bar). Each tracked jump has the
 * masses it could have had: HOT (biggest prop), SEMI (a smaller prop, e.g. a carrier's 500MN),
 * COLD and 🧵 threaded; a click changes it for everyone. Manual entries take a typed mass
 * (minus works). ✎ shows who changed a jump by hand.
 */
type TKind = 'hot' | 'semi' | 'cold' | 'thread';
type TLogJump = {
    id: number;
    character_id: number | null;
    character_name: string | null;
    ship_type_id: number | null;
    ship_type_name: string | null;
    mass: number;
    is_manual: boolean;
    is_hot: boolean;
    is_thread: boolean;
    edited_by_name: string | null;
    outbound: boolean;
    created_at: string;
    options: { kind: TKind; label: string; mass: number }[];
};

const { connection } = defineProps<{ connection: TMapConnection & { source: TMapSolarsystem; target: TMapSolarsystem } }>();
const { canEdit } = usePermission();

const jumps = ref<TLogJump[] | null>(null);
const showAll = ref(false);
const typed = ref<Record<number, string>>({});

async function load(): Promise<void> {
    const body = await jsonFetch<{ jumps: TLogJump[] }>(MapConnectionJumpController.index(connection.id).url);
    if (body) jumps.value = body.jumps;
}
onMounted(load);
watch(() => [connection.jumps_count, connection.jumps_mass_sum, connection.updated_at], () => void load());

const shown = computed(() => (showAll.value ? (jumps.value ?? []) : (jumps.value ?? []).slice(0, 8)));
const total = computed(() => (jumps.value ?? []).reduce((sum, jump) => sum + jump.mass, 0));

/** The button that matches what's logged (same mass, or the switch it was logged with). */
function isOn(jump: TLogJump, option: TLogJump['options'][number]): boolean {
    return Math.abs(option.mass - jump.mass) < 1000;
}

function pick(jump: TLogJump, option: TLogJump['options'][number]): void {
    if (!canEdit.value || isOn(jump, option)) return;
    jump.mass = option.mass;
    updateMapConnectionJump(jump, { mass: option.mass, kind: option.kind }, { onSuccess: () => void load() });
}

function saveTyped(jump: TLogJump): void {
    const kg = parseMillionKg(typed.value[jump.id] ?? '');
    if (kg === null || kg === jump.mass) return;
    jump.mass = kg;
    updateMapConnectionJump(jump, { mass: kg }, { onSuccess: () => void load() });
}

function remove(jump: TLogJump): void {
    jumps.value = (jumps.value ?? []).filter((row) => row.id !== jump.id);
    deleteMapConnectionJump(jump);
}

function addMass(): void {
    const name = (system: TMapSolarsystem) => displayAlias(system.alias) || system.solarsystem.name;
    openAddMass({ connectionId: connection.id, label: `${name(connection.source)} → ${name(connection.target)}` });
}

const m = (kg: number) => {
    const value = kg / 1_000_000;
    const abs = Math.abs(value);
    return `${value < 0 ? '−' : ''}${abs < 10 ? abs.toFixed(2) : abs < 1000 ? abs.toFixed(1) : Math.round(abs).toLocaleString('en-US')}`;
};
const ago = (iso: string) => {
    const minutes = Math.max(0, Math.round((Date.now() - Date.parse(iso)) / 60_000));
    return minutes < 60 ? `${minutes}m` : minutes < 1440 ? `${Math.round(minutes / 60)}h` : `${Math.round(minutes / 1440)}d`;
};
const chipClass = (kind: TKind, on: boolean) =>
    on
        ? { hot: 'border-red-500 bg-red-950/50 text-red-300', semi: 'border-orange-500 bg-orange-950/50 text-orange-300', cold: 'border-blue-500 bg-blue-950/50 text-blue-300', thread: 'border-purple-500 bg-purple-950/50 text-purple-300' }[kind]
        : 'border-border text-muted-foreground hover:text-foreground';
</script>

<template>
    <div class="grid gap-1 text-xs">
        <div class="flex items-center gap-1.5 font-semibold">
            Jump log
            <span class="font-normal text-muted-foreground">· {{ jumps?.length ?? '…' }} · newest first</span>
            <span class="ml-auto font-normal text-muted-foreground">total <span class="font-mono text-foreground">{{ m(total) }}m</span></span>
        </div>
        <p v-if="jumps === null" class="text-muted-foreground">Reading…</p>
        <p v-else-if="!jumps.length" class="text-muted-foreground">No jumps logged yet.</p>
        <div
            v-for="jump in shown"
            :key="jump.id"
            class="grid grid-cols-[0.75rem_1fr_auto_auto_0.75rem] items-center gap-x-2 border-t border-border/40 py-1"
        >
            <span :class="jump.outbound ? 'text-sky-400' : 'text-purple-400'">{{ jump.outbound ? '→' : '←' }}</span>
            <div class="min-w-0">
                <div class="truncate">
                    <template v-if="jump.character_name">{{ jump.character_name }}</template>
                    <i v-else class="text-muted-foreground">manual</i>
                    <span v-if="jump.ship_type_name" class="text-muted-foreground"> · {{ jump.ship_type_name }}</span>
                    <span v-if="jump.edited_by_name" class="text-amber-400" :title="`Changed by hand by ${jump.edited_by_name}`"> ✎ {{ jump.edited_by_name }}</span>
                </div>
                <div v-if="jump.options.length && !jump.is_manual" class="mt-0.5 flex flex-wrap gap-0.5">
                    <button
                        v-for="option in jump.options"
                        :key="option.label"
                        type="button"
                        class="rounded border px-1.5 font-mono text-[10.5px]"
                        :class="chipClass(option.kind, isOn(jump, option))"
                        :disabled="!canEdit"
                        @click="pick(jump, option)"
                    >
                        {{ option.label }} {{ m(option.mass) }}
                    </button>
                </div>
                <div v-else-if="canEdit" class="mt-0.5 flex items-center gap-1">
                    <input
                        :value="typed[jump.id] ?? String(Math.round(jump.mass / 100_000) / 10)"
                        class="w-20 rounded border border-border bg-background px-1 font-mono text-[11px]"
                        aria-label="Mass in million kg"
                        @input="typed[jump.id] = ($event.target as HTMLInputElement).value"
                        @keydown.enter="saveTyped(jump)"
                        @blur="saveTyped(jump)"
                    />
                    <span class="text-[10.5px] text-muted-foreground">million kg</span>
                </div>
            </div>
            <span class="text-right font-mono" :class="jump.mass < 0 ? 'text-amber-400' : ''">{{ m(jump.mass) }}</span>
            <span class="text-right font-mono text-muted-foreground" :title="new Date(jump.created_at).toUTCString()">{{ ago(jump.created_at) }}</span>
            <button v-if="canEdit" type="button" class="text-muted-foreground hover:text-red-400" title="Remove this jump" @click="remove(jump)">✕</button>
            <span v-else />
        </div>
        <button v-if="jumps && jumps.length > 8 && !showAll" type="button" class="text-left text-muted-foreground hover:text-foreground" @click="showAll = true">
            … {{ jumps.length - 8 }} more
        </button>
        <div v-if="canEdit" class="pt-1">
            <Tooltip>
                <TooltipTrigger as-child>
                    <button type="button" class="rounded border border-border px-2 py-0.5 hover:bg-muted" @click="addMass">+ Add mass…</button>
                </TooltipTrigger>
                <TooltipContent>Mass in million kg. A minus takes it off (e.g. -132.4 for a jump logged twice).</TooltipContent>
            </Tooltip>
        </div>
    </div>
</template>
