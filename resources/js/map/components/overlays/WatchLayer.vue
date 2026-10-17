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
import { markConnectionChecked, setConnectionWatched, setWatchOpen, touchWatch } from '@/map/actions/holeChecked';
import { updateMapConnection } from '@/map/api';
import { ANCHOR_OFFSET } from '@/map/core/coords';
import { forwardSides } from '@/map/holeBookmark';
import { useMapStore } from '@/map/store/mapStore';
import type { TMapConnection } from '@/pages/maps';
import MapConnectionJumpController from '@/actions/App/Http/Controllers/MapConnectionJumpController';
import { jsonFetch } from '@/lib/jsonFetch';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

// Patch 39: the person behind a name (hover: main and character).
const { label: pilotName } = usePilotNames();

/**
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
            <!-- Folded: a tiny green Fresh chip -->
            <div
                v-if="!item.connection.mass_watch_open"
                class="pointer-events-auto absolute z-20 flex items-center gap-1 rounded-full border border-emerald-600/70 bg-emerald-950/90 py-px pr-1 pl-2 text-[11px] text-emerald-200 shadow"
                :style="{ left: `${item.left}px`, top: `${item.top - 40}px` }"
                @click.stop
            >
                <Tooltip>
                    <TooltipTrigger as-child>
                        <span>Fresh · ours only · {{ item.minutes }} min</span>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-64 text-xs">
                        {{ item.nearName }} → {{ item.farName }} was marked fresh {{ item.minutes }} min ago by
                        {{ pilotName(item.connection.mass_watched_by_name) }}: only jumps logged since count. ⌃ opens the watch banner; ✕ goes back to the normal
                        (safer) mass math.
                    </TooltipContent>
                </Tooltip>
                <button v-if="canEdit" type="button" class="rounded px-1 hover:bg-emerald-800" title="Open the watch banner" @click="setWatchOpen(item.connection.id, true)">⌃</button>
                <button v-if="canEdit" type="button" class="rounded px-1 hover:bg-emerald-800" title="Stop: back to the normal mass math" @click="setConnectionWatched(item.connection.id, false)">✕</button>
            </div>

            <!-- Open: the watch banner -->
            <div
                v-else
                class="pointer-events-auto absolute z-20 w-[262px] rounded-md border border-sky-600/60 bg-neutral-950/95 p-1.5 text-[11px] text-neutral-200 shadow-lg"
                :style="{ left: `${item.left}px`, top: `${item.top - 6}px`, transform: 'translateY(-100%)' }"
                @click.stop
                @contextmenu.prevent
            >
                <div class="flex items-center gap-1">
                    <span class="truncate font-semibold text-sky-200" :title="`Watching ${item.nearName} → ${item.farName}: every jump counts from the mark`">
                        👁 {{ item.nearName }} → {{ item.farName }}
                    </span>
                    <span class="shrink-0 text-neutral-400">· {{ item.minutes }} min</span>
                    <button v-if="canEdit" type="button" class="ml-auto rounded px-1 hover:bg-neutral-800" title="Fold to the small Fresh chip" @click="setWatchOpen(item.connection.id, false)">⌄</button>
                    <button v-if="canEdit" type="button" class="rounded px-1 hover:bg-neutral-800" title="Stop watching: back to the normal mass math" @click="setConnectionWatched(item.connection.id, false)">✕</button>
                </div>
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
