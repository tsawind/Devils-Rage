<script setup lang="ts">
import { usePilotNames } from '@/composables/usePilotNames';
import BracketIcon from '@/components/fleet/BracketIcon.vue';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useMinuteNow } from '@/composables/useMinuteNow';
import usePermission from '@/composables/usePermission';
import { displayAlias } from '@/lib/alias';
import { FLEET_RULES, watchJumpMass, type TWatchClass } from '@/lib/fleetRules';
import { estimateConnectionMass, formatMass, massHints } from '@/lib/massEstimate';
import { wormholeMass } from '@/lib/wormholeMass';
import { markConnectionChecked, setConnectionWatched, setCounting, touchWatch } from '@/map/actions/holeChecked';
import { updateMapConnection } from '@/map/api';
import { ANCHOR_OFFSET } from '@/map/core/coords';
import { forwardSides } from '@/map/holeBookmark';
import { useMapStore } from '@/map/store/mapStore';
import type { TMapConnection } from '@/pages/maps';
import MapConnectionJumpController from '@/actions/App/Http/Controllers/MapConnectionJumpController';
import { jsonFetch } from '@/lib/jsonFetch';
import { router, usePage } from '@inertiajs/vue3';
import { useLocalStorage } from '@vueuse/core';
import { computed, ref, watch } from 'vue';

// Patch 39: the person behind a name (hover: main and character).
const { label: pilotName } = usePilotNames();

/**
 * Patch 40c: the watcher. Watching a hole (the 👁 on its pill) opens this popup by the system
 * the pipe runs into; it doesn't change the mass math. Its "Every jump logged" switch does: from
 * then on only the jumps logged count. Drag the popup by its top line (it stays with its system
 * as the map moves); double-click the top line to shrink it to a tiny chip, and again to open it.
 *
 * Patch 39: fresh + watching. A hole marked fresh (or watched) counts only our logged jumps.
 * Folded it's a tiny green Fresh chip ("ours only · 12 min", ⌃ watch, ✕); open it's the watch
 * banner, floating top-left of the system the pipe runs into: one button per ship class
 * (click +1, right-click −1) that logs the worst case for that class as a hostile or friendly
 * jump, "could be Reduced / Crit now" asked right there, "still on it?" after 30 min without a
 * click, and a warning when a tracked pilot turns up on the far side with no logged jump.
 */
const store = useMapStore();
const page = usePage();
const { canEdit } = usePermission();
const now = useMinuteNow();

/** Hostile (red) or friendly not on the mapper (blue): what a click adds. */
const side = ref<'hostile' | 'friendly'>('hostile');
/** Counts of hand-added jumps per pipe, class and side since the mark (read from the jump log). */
const counts = ref<Record<number, Record<string, number>>>({});
/** Each class button's newest row per pipe, for right-click −1. */
const newest = ref<Record<number, Record<string, number[]>>>({});

const STILL_ON_IT_MINUTES = 30;

/** Patch 40c: where each watcher sits, relative to its system (screen px), and which are shrunk (this browser). */
const offsets = useLocalStorage<Record<string, { dx: number; dy: number }>>('watcher-offsets', {});
const shrunk = useLocalStorage<Record<string, boolean>>('watcher-shrunk', {});
function offsetOf(id: number): { dx: number; dy: number } {
    return offsets.value[String(id)] ?? { dx: 0, dy: 0 };
}
function toggleShrunk(id: number): void {
    shrunk.value = { ...shrunk.value, [String(id)]: !shrunk.value[String(id)] };
}

/**
 * Patch 45: where a watcher sits: above its system, or below it when above would be off the
 * top of the map (a system in the top row), so its top line can always be grabbed.
 */
function spotOf(item: { left: number; top: number; connection: { id: number } }, height: number): Record<string, string> {
    const offset = offsetOf(item.connection.id);
    const above = item.top - 6 + offset.dy;
    if (above - height >= 0) return { left: `${item.left + offset.dx}px`, top: `${above}px`, transform: 'translateY(-100%)' };
    const below = item.top + 64 * store.scale.value + offset.dy;
    return { left: `${item.left + offset.dx}px`, top: `${Math.max(0, below)}px` };
}

/** Drag a watcher by its top line; it keeps that spot next to its system. */
function startDrag(event: PointerEvent, id: number): void {
    if (event.button !== 0 || (event.target as HTMLElement).closest('button')) return;
    const start = { x: event.clientX, y: event.clientY };
    const from = offsetOf(id);
    const target = event.currentTarget as HTMLElement;
    target.setPointerCapture(event.pointerId);
    const move = (moved: PointerEvent) => {
        offsets.value = { ...offsets.value, [String(id)]: { dx: from.dx + moved.clientX - start.x, dy: from.dy + moved.clientY - start.y } };
    };
    const up = () => {
        target.removeEventListener('pointermove', move);
        target.removeEventListener('pointerup', up);
    };
    target.addEventListener('pointermove', move);
    target.addEventListener('pointerup', up);
}

type TWatched = {
    connection: TMapConnection;
    nearName: string;
    farName: string;
    farId: number;
    nearSolarsystemId: number;
    farSolarsystemId: number;
    left: number;
    top: number;
    minutes: number;
    estimate: ReturnType<typeof estimateConnectionMass>;
    total: number | null;
    hint: 'reduced' | 'critical' | null;
    stale: boolean;
};

const items = computed((): TWatched[] => {
    const scale = store.scale.value;
    const result: TWatched[] = [];
    for (const connection of store.connections.values()) {
        if (!connection.mass_watched_at || connection.type === 'stargate') continue;
        const sides = forwardSides(store, connection);
        if (!sides) continue;
        const position = store.renderPosition(sides.far.id);
        if (!position) continue;
        const type = (connection.signatures ?? []).find((signature) => signature.wormhole && !signature.wormhole.name.startsWith('K162'))?.wormhole ?? null;
        const total = type?.total_mass ?? (type ? (wormholeMass(type.name)?.total ?? null) : null);
        const estimate = total ? estimateConnectionMass(total, connection) : null;
        const hints = massHints({ estimate, totalMass: total, status: connection.mass_status, jumpedSinceStatus: connection.jumps_mass_since_status ?? 0 });
        const minutes = Math.max(0, Math.round((now.value - Date.parse(connection.mass_watched_at)) / 60_000));
        const touched = Date.parse(connection.mass_watch_touched_at ?? connection.mass_watched_at);
        result.push({
            connection,
            nearName: displayAlias(sides.near.alias) || sides.near.solarsystem.name,
            farName: displayAlias(sides.far.alias) || sides.far.solarsystem.name,
            farId: sides.far.id,
            nearSolarsystemId: sides.near.solarsystem_id,
            farSolarsystemId: sides.far.solarsystem_id,
            left: (position.x - ANCHOR_OFFSET.x) * scale,
            top: (position.y - ANCHOR_OFFSET.y) * scale,
            minutes,
            estimate,
            total,
            hint: hints.shouldBe ?? hints.mayBe,
            stale: Number.isFinite(touched) && now.value - touched > STILL_ON_IT_MINUTES * 60_000,
        });
    }
    return result;
});

// ---- Hand-added jumps since the mark --------------------------------------------------

type TLogRow = { id: number; side: string | null; ship_class: string | null; is_manual: boolean; character_id: number | null; created_at: string };

async function loadCounts(connection: TMapConnection): Promise<void> {
    const body = await jsonFetch<{ jumps: TLogRow[] }>(MapConnectionJumpController.index(connection.id).url);
    if (!body) return;
    const since = Date.parse(connection.mass_watched_at ?? '');
    const byClass: Record<string, number> = {};
    const rows: Record<string, number[]> = {};
    for (const row of body.jumps) {
        if (!row.is_manual || !row.side || !row.ship_class || Date.parse(row.created_at) < since) continue;
        const key = `${row.side}:${row.ship_class}`;
        byClass[key] = (byClass[key] ?? 0) + 1;
        (rows[key] ??= []).push(row.id);
    }
    counts.value = { ...counts.value, [connection.id]: byClass };
    newest.value = { ...newest.value, [connection.id]: rows };
    loggedCharacters.value = { ...loggedCharacters.value, [connection.id]: new Set(body.jumps.filter((row) => row.character_id && Date.parse(row.created_at) >= since).map((row) => row.character_id!)) };
}

watch(
    () => items.value.map((item) => `${item.connection.id}:${item.connection.jumps_count}:${item.connection.mass_watched_at}`).join('|'),
    () => {
        for (const item of items.value) void loadCounts(item.connection);
    },
    { immediate: true },
);

function count(connectionId: number, shipClass: TWatchClass): number {
    return counts.value[connectionId]?.[`${side.value}:${shipClass.key}`] ?? 0;
}

function add(item: TWatched, shipClass: TWatchClass): void {
    if (!canEdit.value) return;
    const mass = watchJumpMass(shipClass);
    router.post(
        MapConnectionJumpController.store().url,
        {
            map_connection_id: item.connection.id,
            direction: 'unknown',
            mass,
            side: side.value,
            ship_class: shipClass.key,
            fit: { higgs: shipClass.higgs, prop: shipClass.prop > 0, plate: shipClass.plate > 0 },
        },
        { preserveScroll: true, preserveState: true, only: ['map'], onSuccess: () => void loadCounts(item.connection) },
    );
}

function remove(item: TWatched, shipClass: TWatchClass): void {
    if (!canEdit.value) return;
    const id = newest.value[item.connection.id]?.[`${side.value}:${shipClass.key}`]?.[0];
    if (!id) return;
    router.delete(MapConnectionJumpController.destroy(id).url, { preserveScroll: true, preserveState: true, only: ['map'], onSuccess: () => void loadCounts(item.connection) });
}

function typed(item: TWatched): void {
    if (!canEdit.value) return;
    const value = window.prompt('Mass that went through, in million kg (e.g. 320 for 320 M)');
    const kg = Math.round(Number.parseFloat((value ?? '').replace(',', '.')) * 1_000_000);
    if (!Number.isFinite(kg) || kg === 0) return;
    router.post(
        MapConnectionJumpController.store().url,
        { map_connection_id: item.connection.id, direction: 'unknown', mass: kg, side: side.value, ship_class: 'typed' },
        { preserveScroll: true, preserveState: true, only: ['map'], onSuccess: () => void loadCounts(item.connection) },
    );
}

function setStatus(item: TWatched, status: 'reduced' | 'critical'): void {
    updateMapConnection(item.connection, { mass_status: status });
    touchWatch(item.connection.id);
}

function stillSame(item: TWatched): void {
    markConnectionChecked(item.connection.id);
    touchWatch(item.connection.id);
}

// ---- Someone got through unlogged -----------------------------------------------------

const loggedCharacters = ref<Record<number, Set<number>>>({});
/** Pilots seen on the near side while watching, per pipe. */
const seenNear = ref<Record<number, Set<number>>>({});
const unlogged = ref<Record<number, string>>({});

type TMapCharacter = { id: number; name: string; status?: { solarsystem_id?: number | null } | null };
watch(
    () => ((page.props as { map_characters?: TMapCharacter[] }).map_characters ?? []).map((character) => `${character.id}:${character.status?.solarsystem_id ?? ''}`).join(','),
    () => {
        const characters = (page.props as { map_characters?: TMapCharacter[] }).map_characters ?? [];
        for (const item of items.value) {
            const id = item.connection.id;
            const near = new Set(seenNear.value[id] ?? []);
            for (const character of characters) {
                const where = character.status?.solarsystem_id ?? null;
                if (where === item.nearSolarsystemId) near.add(character.id);
                else if (where === item.farSolarsystemId && near.has(character.id)) {
                    near.delete(character.id);
                    // Give the jump log a moment to catch up before calling it unlogged.
                    setTimeout(() => {
                        void loadCounts(item.connection).then(() => {
                            if (!loggedCharacters.value[id]?.has(character.id)) unlogged.value = { ...unlogged.value, [id]: character.name };
                        });
                    }, 30_000);
                }
            }
            seenNear.value = { ...seenNear.value, [id]: near };
        }
    },
);

function dismissUnlogged(id: number): void {
    const next = { ...unlogged.value };
    delete next[id];
    unlogged.value = next;
}

const mass = (kg: number) => formatMass(kg);
</script>

<template>
    <div class="pointer-events-none absolute inset-0">
        <template v-for="item in items" :key="item.connection.id">
            <!-- Patch 40c: shrunk (double-click): a tiny chip -->
            <div
                v-if="shrunk[String(item.connection.id)]"
                class="pointer-events-auto absolute z-20 flex cursor-move items-center gap-1 rounded-full border border-sky-600/70 bg-neutral-950/95 px-2 py-px text-[11px] text-sky-200 shadow select-none"
                :style="spotOf(item, 24)"
                :title="`Watching ${item.nearName} → ${item.farName} · ${pilotName(item.connection.mass_watched_by_name)}. Double-click to open; drag to move.`"
                @click.stop
                @pointerdown.stop="(event) => startDrag(event, item.connection.id)"
                @dblclick.stop="toggleShrunk(item.connection.id)"
            >
                👁 {{ item.farName }}<span v-if="item.connection.mass_counting_since" class="text-emerald-300">· ✓ log</span>
            </div>

            <!-- The watcher -->
            <div
                v-else
                class="pointer-events-auto absolute z-20 w-[262px] rounded-md border border-sky-600/60 bg-neutral-950/95 p-1.5 text-[11px] text-neutral-200 shadow-lg"
                :style="spotOf(item, 230)"
                @click.stop
                @contextmenu.prevent
            >
                <div
                    class="flex cursor-move items-center gap-1 select-none"
                    title="Drag to move · double-click to shrink"
                    @pointerdown.stop="(event) => startDrag(event, item.connection.id)"
                    @dblclick.stop="toggleShrunk(item.connection.id)"
                >
                    <span class="truncate font-semibold text-sky-200">👁 {{ item.nearName }} → {{ item.farName }}</span>
                    <span class="shrink-0 text-neutral-400">· {{ item.minutes }} min</span>
                    <button type="button" class="ml-auto rounded px-1 hover:bg-neutral-800" title="Shrink (or double-click the top line)" @click="toggleShrunk(item.connection.id)">⌄</button>
                    <button v-if="canEdit" type="button" class="rounded px-1 hover:bg-neutral-800" title="Stop watching" @click="setConnectionWatched(item.connection.id, false)">✕</button>
                </div>
                <div class="truncate text-neutral-500">watching: {{ pilotName(item.connection.mass_watched_by_name) }}</div>
                <!-- Patch 40c: every jump logged: only the jumps logged from now count -->
                <label
                    class="mt-1 flex cursor-pointer items-center gap-1.5 rounded border px-1.5 py-0.5"
                    :class="item.connection.mass_counting_since ? 'border-emerald-600/70 bg-emerald-950/50 text-emerald-200' : 'border-neutral-700 text-neutral-300'"
                    title="On: we see every jump and log it, so only the jumps logged from now count (tighter mass math). Off: the normal, safer math."
                >
                    <input
                        type="checkbox"
                        class="size-3.5 accent-emerald-600"
                        :checked="Boolean(item.connection.mass_counting_since)"
                        :disabled="!canEdit"
                        @change="(event) => setCounting(item.connection.id, (event.target as HTMLInputElement).checked)"
                    />
                    Every jump logged<span v-if="item.connection.mass_counting_since" class="text-emerald-400/80"> · since {{ new Date(item.connection.mass_counting_since).toISOString().slice(11, 16) }}</span>
                </label>
                <div v-if="item.estimate && item.total" class="mt-0.5 text-neutral-400" title="What may be left: its size ±10% minus everything logged since the mark">
                    left ≈ <span class="font-mono text-neutral-100">{{ mass(item.estimate.min) }}–{{ mass(item.estimate.max) }}</span> of {{ mass(item.total) }}
                </div>

                <div class="mt-1 flex items-center gap-1">
                    <button
                        type="button"
                        class="rounded border px-1.5"
                        :class="side === 'hostile' ? 'border-red-500 bg-red-950/60 text-red-300' : 'border-neutral-700 text-neutral-400'"
                        title="Clicks add hostile jumps (red in the jump log)"
                        @click="side = 'hostile'"
                    >
                        Hostile
                    </button>
                    <button
                        type="button"
                        class="rounded border px-1.5"
                        :class="side === 'friendly' ? 'border-sky-500 bg-sky-950/60 text-sky-300' : 'border-neutral-700 text-neutral-400'"
                        title="Clicks add friendly jumps by pilots not on the mapper (blue in the jump log)"
                        @click="side = 'friendly'"
                    >
                        Friendly
                    </button>
                </div>

                <div class="mt-1 flex flex-wrap gap-0.5">
                    <Tooltip v-for="shipClass in FLEET_RULES.watchClasses" :key="shipClass.key">
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                class="relative flex h-7 w-8 items-center justify-center rounded border border-neutral-700 hover:bg-neutral-800"
                                :class="side === 'hostile' ? 'text-red-300' : 'text-sky-300'"
                                :disabled="!canEdit"
                                @click="add(item, shipClass)"
                                @contextmenu.prevent="remove(item, shipClass)"
                            >
                                <BracketIcon :kind="shipClass.key" />
                                <span v-if="count(item.connection.id, shipClass)" class="absolute -top-1.5 -right-1 rounded-full bg-neutral-200 px-1 text-[9px] leading-3 font-bold text-neutral-900">
                                    {{ count(item.connection.id, shipClass) }}
                                </span>
                            </button>
                        </TooltipTrigger>
                        <TooltipContent class="text-xs">
                            {{ shipClass.label }}: click +1, right-click −1 · logs {{ mass(watchJumpMass(shipClass)) }} (worst case{{ shipClass.higgs ? ': Higgs, 100MN hot' : shipClass.prop ? ': prop hot, plate' : '' }})
                        </TooltipContent>
                    </Tooltip>
                    <button
                        type="button"
                        class="flex h-7 w-8 items-center justify-center rounded border border-neutral-700 text-neutral-300 hover:bg-neutral-800"
                        title="Typed: a mass you know (million kg)"
                        :disabled="!canEdit"
                        @click="typed(item)"
                    >
                        <BracketIcon kind="typed" />
                    </button>
                </div>

                <div v-if="item.hint && canEdit" class="mt-1 rounded border border-amber-500/60 bg-amber-950/60 px-1.5 py-1 text-amber-200">
                    Could be {{ item.hint === 'critical' ? 'Crit' : 'Reduced' }} now: look at Show Info.
                    <div class="mt-0.5 flex gap-1">
                        <button type="button" class="rounded border border-neutral-600 px-1.5 hover:bg-neutral-800" @click="stillSame(item)">✓ Still {{ item.connection.mass_status === 'reduced' ? 'reduced' : 'fresh' }}</button>
                        <button type="button" class="rounded border border-amber-500 px-1.5 hover:bg-amber-900" @click="setStatus(item, item.hint)">{{ item.hint === 'critical' ? 'Crit' : 'Reduced' }}</button>
                    </div>
                </div>
                <div v-if="item.stale && canEdit" class="mt-1 flex items-center gap-1 rounded border border-amber-500/60 bg-amber-950/60 px-1.5 py-1 text-amber-200">
                    Still on it? No click for {{ STILL_ON_IT_MINUTES }}+ min.
                    <button type="button" class="ml-auto rounded border border-amber-500 px-1.5 hover:bg-amber-900" @click="touchWatch(item.connection.id)">Yes</button>
                </div>
                <div v-if="unlogged[item.connection.id]" class="mt-1 flex items-center gap-1 rounded border border-amber-500/60 bg-amber-950/60 px-1.5 py-1 text-amber-200">
                    {{ unlogged[item.connection.id] }} got through unlogged?
                    <button type="button" class="ml-auto rounded px-1 hover:bg-amber-900" title="Dismiss" @click="dismissUnlogged(item.connection.id)">✕</button>
                </div>
            </div>
        </template>
    </div>
</template>
