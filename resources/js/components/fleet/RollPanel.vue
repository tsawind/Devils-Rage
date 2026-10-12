<script setup lang="ts">
import { countedShips } from '@/composables/useFleet';
import { rollHoleId, rollTarget, rollTargets } from '@/composables/useRollHole';
import { planText, rangeText, rollPlan, type TRollGoal, type TRollPlan, type TRollShip } from '@/lib/rollPlan';
import { tryUseMapStore } from '@/map/store/mapStore';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Patch 37: "Mass a hole": pick a pipe (the one you clicked last), press MAKE IT REDUCED,
 * MAKE IT CRIT or ROLL IT, and get a jump-by-jump plan with the ships that count (see
 * rollPlan for the rules). Steps tick themselves off as the jump log sees each jump; at a
 * CHECK HOLE STATUS, look in game, mark the pipe, and press the button again.
 */
const store = tryUseMapStore();

const targets = computed(() => (store ? rollTargets(store) : []));
const target = computed(() => (store && rollHoleId.value !== null ? rollTarget(store, rollHoleId.value) : null));

const crewShips = computed<TRollShip[]>(() =>
    countedShips.value.map((ship) => ({
        id: ship.character_id,
        name: ship.name,
        ship: ship.ship ?? '?',
        owner: ship.owner,
        order: ship.position,
        cold: ship.cold,
        hot: ship.hot,
    })),
);

const plan = ref<{ connectionId: number; startedAt: number; plan: TRollPlan } | null>(null);
watch(rollHoleId, () => (plan.value = null));

function make(goal: TRollGoal): void {
    if (!target.value) return;
    plan.value = { connectionId: target.value.id, startedAt: Date.now(), plan: rollPlan(goal, target.value.hole, crewShips.value) };
}

/** How many of each trip's two jumps the jump log has seen since the plan was made. */
const progress = computed(() => {
    const current = plan.value;
    if (!current || !target.value) return [];
    const jumps = [...(target.value.connection.jumps ?? [])]
        .filter((jump) => Date.parse(jump.created_at) >= current.startedAt - 5_000 && jump.character_id !== null)
        .sort((a, b) => Date.parse(a.created_at) - Date.parse(b.created_at));
    const seen = new Map<number, number>();
    for (const jump of jumps) seen.set(jump.character_id as number, (seen.get(jump.character_id as number) ?? 0) + 1);
    const used = new Map<number, number>();
    return current.plan.steps.map((step) => {
        if (step.kind !== 'trip') return 0;
        const have = (seen.get(step.ship.id) ?? 0) - (used.get(step.ship.id) ?? 0);
        const done = Math.max(0, Math.min(2, have));
        used.set(step.ship.id, (used.get(step.ship.id) ?? 0) + done);
        return done;
    });
});

const lines = computed(() => {
    if (!target.value) return null;
    const { total } = target.value.hole;
    return { reduced: total * 0.5, crit: total * 0.1 };
});

const m = (kg: number) => `${Math.round(kg / 1_000_000).toLocaleString('en-US')}m`;

async function copy(): Promise<void> {
    if (!plan.value || !target.value) return;
    const text = planText(plan.value.plan, target.value.label);
    try {
        await navigator.clipboard.writeText(text);
        toast.success('Plan copied');
    } catch {
        toast.message('Plan', { description: text });
    }
}
</script>

<template>
    <div class="grid gap-2 text-xs">
        <label class="flex flex-wrap items-center gap-2">
            <span class="text-muted-foreground">Hole</span>
            <select v-model="rollHoleId" class="min-w-0 flex-1 rounded border border-border bg-background px-2 py-1 font-mono">
                <option :value="null">Click a pipe on the map, or pick one</option>
                <option v-for="option in targets" :key="option.id" :value="option.id">{{ option.label }}</option>
            </select>
        </label>
        <template v-if="target && lines">
            <p class="font-mono text-muted-foreground">
                {{ m(target.hole.total) }} hole · {{ target.connection.mass_status ?? 'fresh' }} · left {{ rangeText([target.hole.low, target.hole.high]) }} · reduced below
                {{ m(lines.reduced) }} · crit below {{ m(lines.crit) }}<template v-if="target.hole.maxJump"> · {{ m(target.hole.maxJump) }} per jump</template>
            </p>
            <div class="flex flex-wrap gap-1.5">
                <button type="button" class="rounded border border-amber-500 px-2 py-1 font-semibold text-amber-300" @click="make('reduced')">MAKE IT REDUCED</button>
                <button type="button" class="rounded border border-red-500 px-2 py-1 font-semibold text-red-300" @click="make('crit')">MAKE IT CRIT</button>
                <button type="button" class="rounded border border-purple-500 px-2 py-1 font-semibold text-purple-300" @click="make('roll')">ROLL IT</button>
                <span class="self-center text-muted-foreground">{{ crewShips.length }} ships count (Ships tab)</span>
            </div>
        </template>
        <p v-else class="text-muted-foreground">Pick a wormhole pipe with a known (or guessed) size.</p>

        <div v-if="plan && target && plan.connectionId === target.id" class="grid gap-1 rounded-md border border-border bg-black/30 p-2 font-mono text-[11.5px] leading-relaxed">
            <div class="font-sans font-semibold">
                {{ plan.plan.goal === 'crit' ? 'Make it crit' : plan.plan.goal === 'reduced' ? 'Make it reduced' : 'Roll it' }} · {{ plan.plan.crew }}
            </div>
            <template v-for="(step, index) in plan.plan.steps" :key="index">
                <div v-if="step.kind === 'trip'" :class="progress[index] === 2 ? 'text-emerald-400' : ''">
                    {{ progress[index] === 2 ? '✓' : progress[index] === 1 ? '½' : `${index + 1}.` }} {{ step.ship.name }} ({{ step.ship.ship }}) out
                    <span :class="step.out.mode === 'hot' ? 'text-red-300' : 'text-blue-300'">{{ step.out.mode.toUpperCase() }} {{ m(step.out.mass) }}</span> · home
                    <span :class="step.back.mode === 'hot' ? 'text-red-300' : 'text-blue-300'">{{ step.back.mode.toUpperCase() }} {{ m(step.back.mass) }}</span>
                    <span class="text-muted-foreground"> → {{ rangeText(step.left) }} left</span>
                    <span v-if="step.mayCollapse" class="text-purple-300"> · may collapse on the way home</span>
                </div>
                <div v-else-if="step.kind === 'check'" class="rounded border border-dashed border-purple-500 px-2 py-1 font-sans font-semibold text-purple-300">◆ {{ step.text }}</div>
                <div v-else-if="step.kind === 'done'" class="font-sans text-emerald-400">✓ {{ step.text }}</div>
                <div v-else class="font-sans text-amber-400">{{ step.text }}</div>
            </template>
            <div class="flex flex-wrap gap-1.5 pt-1 font-sans">
                <button type="button" class="rounded border border-border px-2 py-0.5" @click="copy">⧉ Copy plan for fleet chat</button>
                <button type="button" class="rounded border border-border px-2 py-0.5" @click="make(plan.plan.goal)">Plan again from now</button>
            </div>
            <p class="font-sans text-[11px] text-muted-foreground">
                Steps tick off as the jump log sees each jump. A jump out never risks collapsing it behind you; only a jump home may (when rolling).
            </p>
        </div>
    </div>
</template>
