<script setup lang="ts">
import { focusedClipboard } from '@/lib/popOut';
import { usePilotNames } from '@/composables/usePilotNames';
import { fleetMembers } from '@/composables/useFleet';
import { massState, sessionWindow, useMassSessions, type TMassSession, type TObservationRow, type TRollingCrew } from '@/composables/useMassSessions';
import { rollTarget } from '@/composables/useRollHole';
import { displayAlias } from '@/lib/alias';
import { shipMasses } from '@/composables/useShipProfiles';
import useUser from '@/composables/useUser';
import MassPlanSteps from '@/components/fleet/MassPlanSteps.vue';
import { massPlan, massPlanText, type TMassGoal, type TMassPilot } from '@/lib/massPlan';
import { massText, rangeText } from '@/lib/rollPlan';
import { tryUseMapStore } from '@/map/store/mapStore';
import { computed, onBeforeUnmount, ref } from 'vue';
import { toast } from 'vue-sonner';

// Patch 39: the person behind a name (hover: main and character).
const { label: pilotName, detail: pilotDetail } = usePilotNames();

/**
 * Patch 37: one "⚖ Adding mass…" session: the goal, the crews, what's left (from what people
 * saw, when every jump went through the log), "Status now?" with "made by the last jump",
 * and the plan. A started plan is followed by the jump log: each crew jump logs the
 * plan's mass for that step (hot / cold / 🧵 without touching a switch).
 */
const { session, mapSlug } = defineProps<{ session: TMassSession; mapSlug: string }>();

const store = tryUseMapStore();
const api = useMassSessions(mapSlug);
const user = useUser();

const now = ref(Date.now());
const timer = window.setInterval(() => (now.value = Date.now()), 5_000);
onBeforeUnmount(() => window.clearInterval(timer));

const target = computed(() => (store ? rollTarget(store, session.map_connection_id) : null));
const homeSolarsystemId = computed(() => (session.home_map_solarsystem_id !== null ? (store?.systems.get(session.home_map_solarsystem_id)?.solarsystem_id ?? null) : null));
const farSolarsystemId = computed(() => {
    const connection = target.value?.connection;
    if (!connection || session.home_map_solarsystem_id === null) return null;
    const farId = connection.from_map_solarsystem_id === session.home_map_solarsystem_id ? connection.to_map_solarsystem_id : connection.from_map_solarsystem_id;
    return store?.systems.get(farId)?.solarsystem_id ?? null;
});
const homeName = computed(() => {
    const system = session.home_map_solarsystem_id !== null ? store?.systems.get(session.home_map_solarsystem_id) : null;
    return system ? displayAlias(system.alias) || system.solarsystem.name : null;
});

const GOALS: { key: TMassGoal; label: string }[] = [
    { key: 'watch', label: 'I got this' },
    { key: 'reduced', label: 'Make it reduced' },
    { key: 'crit', label: 'Make it crit' },
    { key: 'roll', label: 'Roll it' },
    { key: 'closer', label: 'Roll with a closer' },
];

/** What's left: from what people saw (only our jumps), else the plain estimate. */
const seen = computed(() => (target.value && session.only_our_jumps ? sessionWindow(session.map_connection_id, target.value.hole.total) : null));
const left = computed<[number, number] | null>(() => {
    if (!target.value) return null;
    if (seen.value?.consistent) return seen.value.left;
    return [target.value.hole.low, target.value.hole.high];
});
const observations = computed<TObservationRow[]>(() => massState.value.observations[session.map_connection_id] ?? []);

const recent = computed(() => massState.value.recent_jumps[session.map_connection_id] ?? []);
const lastJump = computed(() => {
    const jump = recent.value.at(-1);
    if (!jump) return null;
    const name = jump.character_id !== null ? (pilotNames.value.get(jump.character_id) ?? null) : null;
    return { name, mass: jump.mass };
});

const selectedCrews = computed(() => session.crew_ids.map((id) => massState.value.crews.find((crew) => crew.id === id)).filter((crew): crew is TRollingCrew => crew !== undefined));
const pilotNames = computed(() => new Map(massState.value.crews.flatMap((crew) => crew.members.map((member) => [member.character_id, member.name] as const))));

/** The crew, in crew order: ship (the fleet's when they're in it), masses, polarization. */
const pilots = computed<{ list: TMassPilot[]; noShip: string[] }>(() => {
    const list: TMassPilot[] = [];
    const noShip: string[] = [];
    const seenIds = new Set<number>();
    for (const crew of selectedCrews.value) {
        for (const member of crew.members) {
            if (seenIds.has(member.character_id)) continue;
            seenIds.add(member.character_id);
            const inFleet = fleetMembers.value.find((row) => row.character_id === member.character_id);
            const shipTypeId = inFleet?.ship_type_id ?? member.ship_type_id ?? null;
            if (!shipTypeId) {
                noShip.push(member.name);
                continue;
            }
            const masses = shipMasses(member.character_id, shipTypeId, inFleet?.jump_mass ?? member.jump_mass ?? 0, inFleet?.mass ?? member.hull_mass ?? 0);
            const ago = (direction: 'out' | 'home') => {
                const jump = [...recent.value]
                    .reverse()
                    .find((row) => row.character_id === member.character_id && (row.from_solarsystem_id === homeSolarsystemId.value) === (direction === 'out'));
                return jump ? Math.max(0, (now.value - Date.parse(jump.created_at)) / 1000) : null;
            };
            list.push({
                id: member.character_id,
                name: member.name,
                ship: inFleet?.ship ?? member.ship ?? '?',
                options: masses.options,
                expendable: member.expendable,
                lastOut: ago('out'),
                lastHome: ago('home'),
                canSwap: crew.can_swap,
                hull: inFleet?.mass ?? member.hull_mass ?? undefined,
                // In fleet and sitting on the far side: comes home in the plan.
                startsOut: Boolean(inFleet && farSolarsystemId.value !== null && inFleet.solarsystem_id === farSolarsystemId.value),
            });
        }
    }
    return { list, noShip };
});

const preview = computed(() => {
    if (!target.value || !left.value || session.goal === 'watch') return null;
    const hole = target.value.hole;
    // Patch 37: watched (👁 on the pipe, or "only our jumps"): left = size − the log, narrowed by what people saw.
    const watched = target.value.watched || session.only_our_jumps;
    return massPlan(
        session.goal,
        {
            nominal: hole.total,
            low: left.value[0],
            high: left.value[1],
            maxJump: hole.maxJump,
            status: hole.status ?? 'fresh',
            watched,
            logged: massState.value.logged[session.map_connection_id] ?? target.value.logged,
            observations: observations.value.map((row) => ({ status: row.status, logged: row.logged_mass, flip: row.flip_mass })),
        },
        pilots.value.list,
        session.hurry,
    );
});
const showPreview = ref(false);
const running = computed(() => session.plan?.steps ?? null);

const bar = computed(() => {
    if (!target.value || !left.value) return null;
    const scale = target.value.hole.total * 1.1;
    const pct = (value: number) => `${Math.max(0, Math.min(100, (value / scale) * 100))}%`;
    return {
        left: pct(left.value[0]),
        width: `${Math.max(0.6, ((left.value[1] - left.value[0]) / scale) * 100)}%`,
        crit: pct(target.value.hole.total * 0.1),
        reduced: pct(target.value.hole.total * 0.5),
        scale,
    };
});

/** Patch 52: the hole type's size, so the server can keep the range this session works out. */
const nominal = (): number | null => (target.value?.hole.total ? Math.round(target.value.hole.total) : null);
/** Patch 52: ending asks how (the old ✕ is "End, forget the range"). */
const ending = ref(false);
function finish(keep: 'top' | 'watch' | 'forget'): void {
    ending.value = false;
    void api.end(session.id, keep);
}

const madeByLast = ref(false);
const busy = ref(false);
async function mark(status: TObservationRow['status']): Promise<void> {
    busy.value = true;
    await api.observe(session.id, status, madeByLast.value, nominal());
    madeByLast.value = false;
    busy.value = false;
    showPreview.value = true;
}

function toggleCrew(id: number): void {
    const ids = session.crew_ids.includes(id) ? session.crew_ids.filter((other) => other !== id) : [...session.crew_ids, id];
    void api.patch(session.id, { crew_ids: ids });
}

async function startPlan(): Promise<void> {
    const steps = (preview.value?.steps ?? []).flatMap((step) =>
        step.kind === 'jump' ? [{ character_id: step.pilotId, direction: step.direction, mass: Math.round(step.option.mass), label: step.option.label, name: step.pilot }] : [],
    );
    if (!steps.length) return;
    if (await api.patch(session.id, { plan: { steps } })) showPreview.value = false;
}

async function copy(): Promise<void> {
    if (!preview.value || !target.value) return;
    const text = massPlanText(preview.value, target.value.label);
    try {
        await focusedClipboard().writeText(text);
        toast.success('Plan copied');
    } catch {
        toast.message('Plan', { description: text });
    }
}

const when = (iso: string) => {
    const minutes = Math.round((now.value - Date.parse(iso)) / 60_000);
    return minutes < 1 ? 'just now' : minutes < 60 ? `${minutes} min ago` : `${Math.round(minutes / 60)} h ago`;
};
const statusClass = (status: string) => (status === 'critical' ? 'text-red-400' : status === 'reduced' ? 'text-amber-400' : 'text-emerald-400');
</script>

<template>
    <div class="grid min-w-0 gap-2 rounded-md border border-border bg-black/20 p-2 text-xs">
        <div class="flex flex-wrap items-center gap-2">
            <span class="font-semibold">⚖ {{ target?.label ?? `Pipe #${session.map_connection_id}` }}</span>
            <span v-if="target" class="font-mono text-muted-foreground">{{ massText(target.hole.total) }}</span>
            <span v-if="left" class="ml-auto font-mono text-amber-400">left {{ rangeText(left) }}</span>
            <button type="button" class="text-muted-foreground hover:text-foreground" title="End this session" @click="ending = !ending">✕</button>
        </div>
        <!-- Patch 52: how to end it: keep what the session worked out, or forget it -->
        <div v-if="ending" class="flex flex-wrap items-center gap-1.5 rounded-md border border-border bg-black/30 p-1.5">
            <button type="button" class="rounded border border-border px-2 py-0.5 hover:bg-muted" title="Keep the most that can be left; every jump logged from now comes off it" @click="finish('top')">
                ✓ Done massing
            </button>
            <button
                type="button"
                class="rounded border border-sky-600 px-2 py-0.5 text-sky-200 hover:bg-sky-950"
                title="Keep the whole range and watch the hole (every jump logged): it keeps narrowing"
                @click="finish('watch')"
            >
                👁 Done and watching
            </button>
            <button type="button" class="rounded px-2 py-0.5 text-muted-foreground hover:text-foreground" title="Back to the status bands" @click="finish('forget')">
                End, forget the range
            </button>
        </div>
        <p class="text-[11px] text-muted-foreground">
            <span :title="pilotDetail(session.started_by_name)">{{ session.started_by_name ? pilotName(session.started_by_name) : 'Someone' }}</span> started it {{ when(session.created_at) }}<template v-if="homeName"> · home side {{ homeName }}</template>
        </p>
        <p v-if="!target" class="text-muted-foreground">This pipe has no known (or guessed) size, or it's gone from the map.</p>

        <div class="flex flex-wrap gap-1">
            <button
                v-for="goal in GOALS"
                :key="goal.key"
                type="button"
                class="rounded border px-2 py-0.5"
                :class="session.goal === goal.key ? 'border-purple-500 bg-purple-950/40 text-purple-200' : 'border-border text-muted-foreground hover:text-foreground'"
                @click="api.patch(session.id, { goal: goal.key })"
            >
                {{ goal.label }}
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-1">
            <span class="text-muted-foreground">Crew</span>
            <button
                v-for="crew in massState.crews"
                :key="crew.id"
                type="button"
                class="rounded border px-2 py-0.5"
                :class="session.crew_ids.includes(crew.id) ? 'border-purple-500 text-purple-200' : 'border-border text-muted-foreground hover:text-foreground'"
                :title="crew.members.map((member) => member.name).join(', ')"
                @click="toggleCrew(crew.id)"
            >
                {{ crew.name }} <span class="text-muted-foreground">· {{ crew.user_id === user?.id ? 'mine' : crew.owner_name }} · {{ crew.members.length }}</span>
            </button>
            <span v-if="!massState.crews.length" class="text-muted-foreground">No crews yet: make one in the Crews tab.</span>
        </div>

        <div class="flex flex-wrap gap-3">
            <label class="flex items-center gap-1.5" title="Every jump through this hole went through the log: what people saw narrows the window">
                <input type="checkbox" :checked="session.only_our_jumps" @change="api.patch(session.id, { only_our_jumps: ($event.target as HTMLInputElement).checked, nominal: nominal() })" />
                Only our jumps
            </label>
            <label class="flex items-center gap-1.5" title="Expendable pilots may gamble (they may be left outside)">
                <input type="checkbox" :checked="session.hurry" @change="api.patch(session.id, { hurry: ($event.target as HTMLInputElement).checked })" />
                Hurry
            </label>
        </div>

        <template v-if="bar && target">
            <div class="relative h-3.5 overflow-hidden rounded bg-muted">
                <div class="absolute inset-y-0 bg-amber-500" :style="{ left: bar.left, width: bar.width }" />
                <div class="absolute -inset-y-0.5 w-0.5 bg-red-500" :style="{ left: bar.crit }" title="crit line" />
                <div class="absolute -inset-y-0.5 w-0.5 bg-amber-300/70" :style="{ left: bar.reduced }" title="reduced line" />
            </div>
            <div class="flex justify-between font-mono text-[10px] text-muted-foreground">
                <span>0</span><span>crit {{ massText(target.hole.total * 0.09) }}–{{ massText(target.hole.total * 0.11) }}</span
                ><span>reduced {{ massText(target.hole.total * 0.45) }}–{{ massText(target.hole.total * 0.55) }}</span><span>{{ massText(bar.scale) }}</span>
            </div>
        </template>

        <div v-if="observations.length" class="grid gap-0.5 font-mono text-[11px]">
            <div v-for="row in observations.slice(-5)" :key="row.id">
                <span :class="statusClass(row.status)">{{ row.status }}</span> at {{ massText(row.logged_mass) }} logged
                <template v-if="row.flip_mass">, made by {{ row.flip_by ? `${row.flip_by}'s` : 'a' }} {{ massText(row.flip_mass) }}</template>
                <span class="text-muted-foreground"> · {{ row.by_name ?? 'someone' }}, {{ when(row.created_at) }}</span>
            </div>
            <div v-if="seen?.consistent" class="text-muted-foreground">
                → size {{ rangeText(seen.total) }} ·
                {{ seen.reasons.at(-1) }}
            </div>
            <div v-else-if="seen && !seen.consistent" class="font-sans text-amber-400">
                What people saw and the log don't fit together (a jump not logged, or a wrong mark?): using the plain estimate.
            </div>
        </div>

        <div class="grid gap-1 rounded border border-dashed border-purple-500/70 p-1.5">
            <div class="flex flex-wrap items-center gap-1">
                <span class="font-semibold text-purple-300">◆ Status now?</span>
                <button type="button" :disabled="busy" class="rounded border border-border px-2 py-0.5 text-emerald-300" @click="mark('fresh')">Fresh</button>
                <button type="button" :disabled="busy" class="rounded border border-border px-2 py-0.5 text-amber-300" @click="mark('reduced')">Reduced</button>
                <button type="button" :disabled="busy" class="rounded border border-border px-2 py-0.5 text-red-300" @click="mark('critical')">Crit</button>
            </div>
            <label v-if="lastJump" class="flex items-center gap-1.5 text-muted-foreground">
                <input v-model="madeByLast" type="checkbox" />
                made by the last jump ({{ lastJump.name ?? 'someone' }} {{ massText(lastJump.mass) }})
            </label>
        </div>

        <p v-if="pilots.noShip.length" class="text-amber-400">No ship known for {{ pilots.noShip.join(', ') }} (not in fleet, no profile).</p>

        <div v-if="running && !showPreview" class="grid gap-0.5 rounded-md border border-border bg-black/30 p-2 font-mono text-[11.5px]">
            <div class="font-sans font-semibold">Running plan · the log follows it</div>
            <div v-for="(step, index) in running" :key="index" :class="step.done ? 'text-emerald-400' : ''">
                {{ step.done ? '✓' : `${index + 1}.` }} {{ step.name ?? step.character_id }} {{ step.direction === 'out' ? 'OUT' : 'HOME' }} {{ step.label }} {{ massText(step.mass) }}
            </div>
            <div class="flex flex-wrap gap-1.5 pt-1 font-sans">
                <button type="button" class="rounded border border-border px-2 py-0.5" @click="showPreview = true">Plan again</button>
                <button type="button" class="rounded border border-border px-2 py-0.5" @click="api.patch(session.id, { plan: null })">Stop plan</button>
            </div>
        </div>

        <div v-else-if="preview" class="grid gap-0.5 rounded-md border border-border bg-black/30 p-2 font-mono text-[11.5px] leading-relaxed">
            <MassPlanSteps :plan="preview" />
            <div class="flex flex-wrap gap-1.5 pt-1 font-sans">
                <button
                    v-if="preview.steps.some((step) => step.kind === 'jump')"
                    type="button"
                    class="rounded border border-purple-500 px-2 py-0.5 font-semibold text-purple-200"
                    @click="startPlan"
                >
                    ▶ Start this plan
                </button>
                <button type="button" class="rounded border border-border px-2 py-0.5" @click="copy">⧉ Copy for fleet chat</button>
                <button v-if="running" type="button" class="rounded border border-border px-2 py-0.5" @click="showPreview = false">Back to the running plan</button>
            </div>
            <p class="font-sans text-[11px] text-muted-foreground">
                Started, each crew jump through this hole logs its step's mass (no switch needed). Out never risks collapsing it behind you; 5 min per pilot per direction.
            </p>
        </div>
    </div>
</template>
