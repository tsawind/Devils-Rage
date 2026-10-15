<script setup lang="ts">
import { clock, massText, type TMassPlan } from '@/lib/massPlan';

/** Patch 37: a mass plan, step by step (Quick plan and mass sessions). */
const { plan, done = [] } = defineProps<{ plan: TMassPlan; done?: boolean[] }>();

const range = ([low, high]: [number, number]) => (Math.abs(high - low) < 1_000_000 ? massText(low) : `${massText(low)}–${massText(high)}`);
</script>

<template>
    <div class="grid gap-0.5 font-mono text-[11.5px] leading-relaxed">
        <template v-for="(step, index) in plan.steps" :key="index">
            <div v-if="step.kind === 'jump'" :class="[step.gamble ? 'text-red-300' : '', done[index] ? 'text-emerald-400' : '']">
                <span class="text-muted-foreground">+{{ clock(step.at) }}</span> {{ step.pilot
                }}<span v-if="step.pilot !== step.shipOwner" class="text-sky-300"> in {{ step.shipOwner }}'s</span>
                <span class="text-muted-foreground"> ({{ step.ship }})</span>
                <b>{{ step.direction === 'out' ? ' OUT ' : ' HOME ' }}</b>
                <span :class="step.option.mode === 'hot' ? 'text-red-300' : 'text-blue-300'">{{ step.option.label }} {{ massText(step.option.mass) }}</span>
                <span class="text-muted-foreground"> → {{ range(step.left) }} left</span>
                <span v-if="step.closer" class="text-sky-300"> · closer</span>
                <span v-if="step.gamble" class="font-sans font-semibold"> · GAMBLE: may be left outside</span>
                <span v-else-if="step.mayCollapse" class="text-purple-300"> · may close it</span>
            </div>
            <div v-else-if="step.kind === 'check'" class="rounded border border-dashed border-purple-500 px-2 py-0.5 font-sans font-semibold text-purple-300">◆ {{ step.text }}</div>
            <div v-else-if="step.kind === 'done'" class="font-sans text-emerald-400">✓ {{ step.text }}</div>
            <div v-else-if="step.kind === 'wait'" class="font-sans text-sky-300">⏳ {{ step.text }}</div>
            <div v-else class="font-sans text-amber-400">{{ step.text }}</div>
        </template>
        <div v-for="tip in plan.tips" :key="tip" class="font-sans text-[11px] text-amber-300/90">💡 {{ tip }}</div>
    </div>
</template>
