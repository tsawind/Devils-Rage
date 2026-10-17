<script setup lang="ts">
import { usePilotNames } from '@/composables/usePilotNames';
import { PopoverContent } from '@/components/ui/popover';
import { TMapConnection, TMapSolarsystem } from '@/pages/maps';
import { computed, ref } from 'vue';
import usePermission from '@/composables/usePermission';
import { useMinuteNow } from '@/composables/useMinuteNow';
import { uncheckedFor } from '@/lib/holeChecked';
import { lastLook, lifeLine } from '@/lib/holeSummary';
import { estimateConnectionMass, formatMass, massHints } from '@/lib/massEstimate';
import { SHIP_SIZE_LETTERS } from '@/lib/shipSize';
import { lifetimeRange, wormholeLifetimeHours } from '@/lib/wormholeLifetime';
import { markConnectionChecked, markConnectionFresh, setConnectionWatched, setWatchOpen } from '@/map/actions/holeChecked';
import { copyForwardBookmark, forwardSides } from '@/map/holeBookmark';
import { couldBeNames, guessHole, holeCandidates } from '@/map/holeGuess';
import { isK162Frigate } from '@/lib/k162';
import { tryUseMapStore } from '@/map/store/mapStore';
import ConnectionStatus from './connection/ConnectionStatus.vue';
import HoleSummary, { type THoleSummary } from './connection/HoleSummary.vue';
import JumpLog from './connection/JumpLog.vue';
import MassTracking from './connection/MassTracking.vue';
import SignatureSection from './connection/SignatureSection.vue';
import WormholeProperties from './connection/WormholeProperties.vue';

// Patch 39: the person behind a name (hover: main and character).
const { label: pilotName, detail: pilotDetail } = usePilotNames();

const { connection } = defineProps<{
    connection: TMapConnection & {
        source: TMapSolarsystem;
        target: TMapSolarsystem;
    };
}>();

const outSignature = computed(() => {
    if (!connection.signatures?.length) return null;
    return connection.signatures.find((sig) => !sig.wormhole?.name.startsWith('K162')) || null;
});

const inSignature = computed(() => {
    if (!connection.signatures?.length) return null;
    return connection.signatures.find((sig) => sig.wormhole?.name.startsWith('K162')) || null;
});

const wormhole = computed(() => {
    return outSignature.value?.wormhole || inSignature.value?.wormhole || null;
});

const showMassTracking = computed(() => {
    return connection.type === 'wormhole';
});

// ---- Patch 37: the summary at the top (type, who and when, life, mass left, last look) ----
const store = tryUseMapStore();
const { canEdit } = usePermission();
const now = useMinuteNow();

const MARKED: Record<string, string> = { fresh: 'marked Fresh', reduced: 'marked Reduced', critical: 'marked Crit' };

const summary = computed((): THoleSummary | null => {
    if (connection.type !== 'wormhole') return null;
    const type = outSignature.value?.wormhole ?? null;
    const classes = [connection.source?.solarsystem?.class ?? null, connection.target?.solarsystem?.class ?? null] as const;
    // Patch 37: with a K162 on one side, the hole spawned on the other side and leads into the K162's class.
    const k162 = inSignature.value;
    const k162Side = k162 ? (k162.map_solarsystem_id === connection.source?.id ? connection.source : connection.target) : null;
    const farSide = k162Side ? (k162Side === connection.source ? connection.target : connection.source) : null;
    const frigate = isK162Frigate(k162?.signature_type) || connection.ship_size === 'frigate';
    const candidates = type
        ? []
        : k162Side
          ? holeCandidates({ k162Class: k162Side.solarsystem?.class ?? null, spawnClasses: farSide?.solarsystem?.class ? [farSide.solarsystem.class] : [], frigate })
          : holeCandidates({ endClasses: classes, frigate });
    const guessTotal = type ? null : (guessHole({ endClasses: classes })?.total ?? null);
    const total = type?.total_mass ?? guessTotal;
    const since = connection.jumps_mass_since_status ?? connection.jumps_mass_sum ?? 0;
    const estimate = total ? estimateConnectionMass(total, connection) : null;
    const hints = massHints({ estimate, totalMass: total, status: connection.mass_status, jumpedSinceStatus: since });
    const signatures = connection.signatures ?? [];
    const first = [...signatures].sort((a, b) => Date.parse(a.created_at) - Date.parse(b.created_at))[0] ?? null;
    const typed = signatures.find((signature) => signature.type_set_at && !signature.wormhole?.name.startsWith('K162')) ?? signatures.find((signature) => signature.type_set_at) ?? null;
    const jumps = [...new Set(candidates.map((candidate) => candidate.maxJump))];
    const size = connection.ship_size ? SHIP_SIZE_LETTERS[connection.ship_size] : null;
    const lastJump = connection.jumps?.[0] ?? null;
    return {
        typeLine: type ? `${type.name}${type.leads_to ? ` → ${type.leads_to}` : ''}${size ? ` · ${size}` : ''}` : null,
        couldBe: couldBeNames(candidates),
        addedAt: first?.created_at ?? connection.created_at,
        addedBy: first?.created_by_name ?? null,
        typeSetAt: typed?.type_set_at ?? null,
        typeSetBy: typed?.type_set_by_name ?? null,
        life: lifeLine({
            lifetime: connection.lifetime_status,
            // Patch 37: the newest look (a life mark or ✓ Checked in game) moves the earliest collapse.
            markedAt: [connection.lifetime_status_updated_at, connection.checked_at].filter((value): value is string => Boolean(value)).sort().at(-1) ?? null,
            eolSince: connection.eol_since ?? null,
            criticalSince: connection.critical_since ?? null,
            imminentSince: connection.imminent_since ?? null,
            hours: type ? (wormholeLifetimeHours(type.name) ? [wormholeLifetimeHours(type.name)!, wormholeLifetimeHours(type.name)!] : null) : lifetimeRange(candidates.map((candidate) => candidate.name)),
            addedAt: first?.created_at ?? connection.created_at,
            now: now.value,
        }),
        perJump: type ? `${formatMass(type.maximum_jump_mass)} kg` : jumps.length === 1 ? `${formatMass(jumps[0])} kg (all of them)` : null,
        estimate,
        status: connection.mass_status,
        loggedSinceMark: connection.mass_status_updated_at ? since : 0,
        markedAt: connection.mass_status_updated_at ?? null,
        markedBy: connection.mass_status_set_by_name ?? null,
        hints,
        lastLook: lastLook([
            { at: connection.checked_at, by: connection.checked_by_name, note: connection.checked_note ?? 'checked' },
            { at: connection.mass_status_updated_at, by: connection.mass_status_set_by_name, note: MARKED[connection.mass_status] ?? 'marked' },
            { at: lastJump?.created_at, by: lastJump?.character_name, note: 'jumped' },
        ]),
        unchecked: uncheckedFor(connection, now.value),
        canCheck: canEdit.value,
        canCopy: Boolean(store && forwardSides(store, connection)?.pasted),
    };
});

/** Patch 37: the jump log under the mass bar (click the bar). */
const showJumpLog = ref(false);

/** Patch 39: Mark fresh asks first. */
const confirmFresh = ref(false);
function fresh(watch: boolean): void {
    confirmFresh.value = false;
    markConnectionFresh(connection.id, watch);
}

function copyBookmark(): void {
    if (store) copyForwardBookmark(store, connection);
}
</script>

<template>
    <!--
        The jump list grows without bound, so the popup is capped to the space the
        popover actually has and scrolls past it.
    -->
    <PopoverContent class="max-h-(--reka-popover-content-available-height) w-80 overflow-y-auto">
        <div class="space-y-3">
            <HoleSummary v-if="summary" :summary="summary" :now="now" @checked="markConnectionChecked(connection.id)" @copy="copyBookmark" @jumps="showJumpLog = !showJumpLog" />
            <!-- Patch 39: fresh / watching: only our logged jumps since the mark count -->
            <div v-if="summary && connection.mass_watched_at" class="flex items-center gap-2 rounded-md bg-emerald-950/50 px-2 py-1 text-xs text-emerald-200">
                <span :title="'Jumps logged before the mark stay in the log but no longer count'">
                    Fresh · ours only since {{ new Date(connection.mass_watched_at).toISOString().slice(11, 16) }} EVE · <span :title="pilotDetail(connection.mass_watched_by_name)">{{ pilotName(connection.mass_watched_by_name) }}</span>
                </span>
                <template v-if="summary.canCheck">
                    <button type="button" class="ml-auto underline" @click="setWatchOpen(connection.id, !connection.mass_watch_open)">
                        {{ connection.mass_watch_open ? 'Fold' : 'Watch' }}
                    </button>
                    <button type="button" class="underline" title="Back to the normal (safer) mass math" @click="setConnectionWatched(connection.id, false)">Stop</button>
                </template>
            </div>
            <div v-else-if="summary?.canCheck" class="text-xs">
                <button v-if="!confirmFresh" type="button" class="rounded border border-emerald-700 px-2 py-0.5 text-emerald-300 hover:bg-emerald-950" title="You saw it in game: not significantly disrupted" @click="confirmFresh = true">
                    Mark fresh…
                </button>
                <div v-else class="grid gap-1.5 rounded-md border border-emerald-700/60 bg-emerald-950/40 p-2 text-emerald-100">
                    <p>Show Info says <i>"not significantly disrupted"</i>? Its range goes back to its size ±10%. Jumps logged before stay in the log but stop counting.</p>
                    <div class="flex flex-wrap gap-1">
                        <button type="button" class="rounded bg-emerald-700 px-2 py-0.5 text-white hover:bg-emerald-600" @click="fresh(true)">Fresh, start watching</button>
                        <button type="button" class="rounded border border-emerald-700 px-2 py-0.5 hover:bg-emerald-900" @click="fresh(false)">Fresh only</button>
                        <button type="button" class="rounded px-2 py-0.5 text-muted-foreground hover:text-foreground" @click="confirmFresh = false">Cancel</button>
                    </div>
                </div>
            </div>
            <JumpLog v-if="showJumpLog && summary" :connection="connection" />
            <SignatureSection v-if="outSignature" :signature="outSignature" title="Out Sig" />
            <SignatureSection v-if="inSignature" :signature="inSignature" title="In Sig" />
            <div v-if="!outSignature && !inSignature" class="space-y-1">
                <div class="py-2 text-center text-xs text-muted-foreground">No signatures assigned</div>
            </div>
            <ConnectionStatus :connection="connection" />
            <WormholeProperties v-if="wormhole" :wormhole="wormhole" />
            <MassTracking v-if="showMassTracking" :connection="connection" :wormhole="wormhole" />
        </div>
    </PopoverContent>
</template>

<style scoped></style>
