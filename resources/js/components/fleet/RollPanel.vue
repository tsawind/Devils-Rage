<script setup lang="ts">
import MassPlanSteps from '@/components/fleet/MassPlanSteps.vue';
import { fleetShips, inScope } from '@/composables/useFleet';
import { homeSideOf } from '@/composables/useMassSessions';
import { rollHoleId, rollTarget, rollTargets } from '@/composables/useRollHole';
import { massPlan, massPlanText, massText, type TMassGoal, type TMassPilot, type TMassPlan } from '@/lib/massPlan';
import { rangeText } from '@/lib/rollPlan';
import { tryUseMapStore } from '@/map/store/mapStore';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Patch 37: Quick plan: pick a pipe (the one you clicked last) and a goal, get the whole plan
 * (lib/massPlan): big ships first, the half-mass check, the finish, and ships worth bringing.
 * The fleet's ticked ships count, plus capitals in scope (the rolling carrier).
 */
const store = tryUseMapStore();

const targets = computed(() => (store ? rollTargets(store) : []));
const target = computed(() => (store && rollHoleId.value !== null ? rollTarget(store, rollHoleId.value) : null));

const crew = computed<TMassPilot[]>(() => {
    const connection = target.value?.connection;
    const homeId = store && connection ? homeSideOf(store, connection) : null;
    const homeSolarsystem = homeId !== null ? (store?.systems.get(homeId)?.solarsystem_id ?? null) : null;
    const now = Date.now();
    const ago = (characterId: number, out: boolean) => {
        const jump = (connection?.jumps ?? []).find((row) => row.character_id === characterId && (row.from_solarsystem_id === homeSolarsystem) === out);
        return jump ? Math.max(0, (now - Date.parse(jump.created_at)) / 1000) : null;
    };
    return fleetShips.value
        .filter((ship) => ship.counts || (ship.capital && inScope(ship)))
        .sort((a, b) => a.position - b.position)
        .map((ship) => ({
            id: ship.character_id,
            name: ship.name,
            ship: ship.ship ?? '?',
            options: ship.options,
            expendable: false,
            lastOut: ago(ship.character_id, true),
            lastHome: ago(ship.character_id, false),
            canSwap: false,
            hull: ship.mass,
        }));
});

const plan = ref<{ connectionId: number; plan: TMassPlan } | null>(null);
watch(rollHoleId, () => (plan.value = null));

function make(goal: TMassGoal): void {
    const current = target.value;
    if (!current) return;
    const hole = current.hole;
    plan.value = {
        connectionId: current.id,
        plan: massPlan(
            goal,
            { nominal: hole.total, low: hole.low, high: hole.high, maxJump: hole.maxJump, status: hole.status ?? 'fresh', watched: current.watched, logged: current.logged },
            crew.value,
        ),
    };
}

const GOALS: { goal: TMassGoal; label: string; tone: string }[] = [
    { goal: 'reduced', label: 'MAKE IT REDUCED', tone: 'border-amber-500 text-amber-300' },
    { goal: 'crit', label: 'MAKE IT CRIT', tone: 'border-red-500 text-red-300' },
    { goal: 'roll', label: 'ROLL IT', tone: 'border-purple-500 text-purple-300' },
    { goal: 'closer', label: 'ROLL WITH A CLOSER', tone: 'border-sky-500 text-sky-300' },
];

async function copy(): Promise<void> {
    if (!plan.value || !target.value) return;
    const text = massPlanText(plan.value.plan, target.value.label);
    try {
        await navigator.clipboard.writeText(text);
        toast.success('Plan copied');
    } catch {
        toast.message('Plan', { description: text });
    }
}
</script>

<template>
    <div class="grid min-w-0 gap-2 text-xs">
        <label class="flex flex-wrap items-center gap-2">
            <span class="text-muted-foreground">Hole</span>
            <select v-model="rollHoleId" class="w-full max-w-full min-w-0 flex-1 rounded border border-border bg-background px-2 py-1 font-mono">
                <option :value="null">Click a pipe on the map, or pick one</option>
                <option v-for="option in targets" :key="option.id" :value="option.id">{{ option.label }}</option>
            </select>
        </label>
        <template v-if="target">
            <p class="font-mono text-muted-foreground">
                {{ massText(target.hole.total) }} hole · {{ target.connection.mass_status ?? 'fresh' }}<span v-if="target.watched" class="text-sky-300"> · 👁 watched</span> · left
                {{ rangeText([target.hole.low, target.hole.high]) }}<template v-if="target.hole.maxJump"> · {{ massText(target.hole.maxJump) }} per jump</template>
            </p>
            <div class="flex flex-wrap gap-1.5">
                <button v-for="option in GOALS" :key="option.goal" type="button" class="rounded border px-2 py-1 font-semibold" :class="option.tone" @click="make(option.goal)">
                    {{ option.label }}
                </button>
                <span class="self-center text-muted-foreground">{{ crew.length }} ships (ticked + capitals in scope)</span>
            </div>
        </template>
        <p v-else class="text-muted-foreground">Pick a wormhole pipe with a known (or guessed) size.</p>

        <div v-if="plan && target && plan.connectionId === target.id" class="grid gap-1 rounded-md border border-border bg-black/30 p-2">
            <MassPlanSteps :plan="plan.plan" />
            <div class="flex flex-wrap gap-1.5 pt-1">
                <button type="button" class="rounded border border-border px-2 py-0.5" @click="copy">⧉ Copy plan for fleet chat</button>
                <button type="button" class="rounded border border-border px-2 py-0.5" @click="make(plan.plan.goal)">Plan again from now</button>
            </div>
            <p class="text-[11px] text-muted-foreground">
                Out never risks collapsing it behind anyone; only the last one home may close it (rolling). At each ◆ check, look at Show Info: if it shows something else, mark the pipe and plan again.
            </p>
        </div>
    </div>
</template>
