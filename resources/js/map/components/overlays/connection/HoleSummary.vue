<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { usePilotNames } from '@/composables/usePilotNames';
import { ago, hintLine, massBar, whenLine } from '@/lib/holeSummary';
import { formatMass, type TMassEstimate, type TMassHints } from '@/lib/massEstimate';
import { Check, ClipboardCopy } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Patch 37: the top of the pipe details box, for jumped and dotted pipes alike: the
 * type (or what it could be), who added it and set its type, how long it may live,
 * the mass that may be left (with a bar), where that range comes from, and when
 * someone last looked at it in game.
 */
export type THoleSummary = {
    typeLine: string | null;
    couldBe: readonly string[];
    addedAt: string | null;
    addedBy: string | null;
    typeSetAt: string | null;
    typeSetBy: string | null;
    life: string;
    perJump: string | null;
    estimate: TMassEstimate | null;
    status: string | null;
    loggedSinceMark: number;
    markedAt: string | null;
    markedBy: string | null;
    hints: TMassHints;
    lastLook: { at: number; by: string | null; note: string } | null;
    unchecked: string | null;
    canCheck: boolean;
    canCopy: boolean;
};

const { summary, now } = defineProps<{ summary: THoleSummary; now: number }>();
const emit = defineEmits<{ checked: []; copy: [] }>();

const { label } = usePilotNames();

const STATUS: Record<string, string> = { fresh: 'Fresh', reduced: 'Reduced', critical: 'Crit' };

const bar = computed(() => (summary.estimate ? massBar({ estimate: summary.estimate, loggedSinceMark: summary.loggedSinceMark, status: summary.status, hints: summary.hints }) : null));
const hint = computed(() => hintLine(summary.hints));
const fromLine = computed(() => {
    if (!summary.status || summary.status === 'unknown' || !summary.markedAt) return null;
    const who = summary.markedBy ? ` by ${label(summary.markedBy)}` : summary.status === 'fresh' ? '' : ' by the jump log';
    const logged = summary.loggedSinceMark > 0 ? ` · ${formatMass(summary.loggedSinceMark)} logged since` : ' · nothing logged since';
    return `${STATUS[summary.status] ?? summary.status}${who} ${whenLine(summary.markedAt, now) ?? ''}${logged}`;
});
const lastLookLine = computed(() => {
    const look = summary.lastLook;
    if (!look) return null;
    return `${ago(now - look.at)} · ${label(look.by)} (${look.note})`;
});
const barColor = computed(() => (bar.value?.tone === 'critical' ? 'bg-red-600' : bar.value?.tone === 'reduced' ? 'bg-amber-600' : 'bg-green-700'));
</script>

<template>
    <div class="space-y-1.5 text-xs">
        <div v-if="summary.unchecked" class="rounded-md border border-purple-300 px-2 py-1 text-purple-700 dark:text-purple-200">
            ? Not checked for {{ summary.unchecked }}: look at Show Info
        </div>
        <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1">
            <template v-if="summary.typeLine">
                <dt class="text-muted-foreground">Type</dt>
                <dd class="font-mono">{{ summary.typeLine }}</dd>
            </template>
            <template v-if="summary.couldBe.length">
                <dt class="text-muted-foreground">Could be</dt>
                <dd class="font-mono">{{ summary.couldBe.length === 1 ? `probably ${summary.couldBe[0]}` : summary.couldBe.join(', ') }}</dd>
            </template>
            <template v-if="summary.addedAt">
                <dt class="text-muted-foreground">Added</dt>
                <dd>{{ whenLine(summary.addedAt, now) }}<template v-if="summary.addedBy"> · {{ label(summary.addedBy) }}</template></dd>
            </template>
            <template v-if="summary.typeSetAt && summary.typeSetBy">
                <dt class="text-muted-foreground">Type set</dt>
                <dd>{{ whenLine(summary.typeSetAt, now) }} · {{ label(summary.typeSetBy) }}</dd>
            </template>
            <dt class="text-muted-foreground">Life</dt>
            <dd>{{ summary.life }}</dd>
            <template v-if="summary.perJump">
                <dt class="text-muted-foreground">Per jump</dt>
                <dd class="font-mono">{{ summary.perJump }}</dd>
            </template>
            <template v-if="summary.estimate">
                <dt class="text-muted-foreground">Mass left</dt>
                <dd class="font-mono font-semibold">{{ formatMass(summary.estimate.min) }} ↔ {{ formatMass(summary.estimate.max) }} kg</dd>
            </template>
        </dl>
        <div v-if="bar && summary.estimate">
            <div class="relative h-3.5 overflow-hidden rounded bg-neutral-200 dark:bg-neutral-800" aria-hidden="true">
                <div class="absolute inset-y-0" :class="barColor" :style="{ left: `${bar.left}%`, width: `${bar.width}%` }" />
                <div class="absolute inset-y-0 bg-stone-400 dark:bg-stone-600" :style="{ left: `${bar.loggedLeft}%`, width: `${bar.loggedWidth}%` }" />
                <div class="absolute inset-y-0 left-[10%] border-l-[1.5px] border-dashed border-red-400" />
                <div class="absolute inset-y-0 left-1/2 border-l-[1.5px] border-dashed border-amber-400" />
            </div>
            <div class="mt-0.5 flex justify-between text-[10px] text-muted-foreground">
                <span>0</span>
                <span>Crit 10% · Reduced 50%</span>
                <span>{{ formatMass(summary.estimate.capacity) }}</span>
            </div>
            <div v-if="bar.loggedWidth > 0" class="text-[10px] text-muted-foreground">
                <span class="inline-block size-2 rounded-sm" :class="barColor" /> could be left ·
                <span class="inline-block size-2 rounded-sm bg-stone-400 dark:bg-stone-600" /> logged since the mark
            </div>
        </div>
        <div v-if="fromLine" class="text-muted-foreground">{{ fromLine }}</div>
        <div v-if="hint" class="rounded-md bg-amber-100 px-2 py-1 font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200">? {{ hint }}</div>
        <div class="text-muted-foreground">
            Last checked: <span class="text-foreground">{{ lastLookLine ?? 'never' }}</span>
        </div>
        <div v-if="summary.canCheck || summary.canCopy" class="flex gap-1.5 pt-1">
            <Button v-if="summary.canCheck" variant="outline" size="sm" class="h-7 flex-1 text-xs" @click="emit('checked')">
                <Check class="size-3.5" />
                Checked in game
            </Button>
            <Button v-if="summary.canCopy" variant="outline" size="sm" class="h-7 flex-1 text-xs" @click="emit('copy')">
                <ClipboardCopy class="size-3.5" />
                Copy bookmark
            </Button>
        </div>
    </div>
</template>
