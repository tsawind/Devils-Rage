<script setup lang="ts">
import RageRollDialog from '@/components/map/RageRollDialog.vue';
import { Button } from '@/components/ui/button';
import usePermission from '@/composables/usePermission';
import { lastPaste, useRageRoll } from '@/composables/useRageRoll';
import { useRageRollWatcher } from '@/composables/useRageRollWatcher';
import { useRollTimer, useRollTimerTone } from '@/composables/useRollTimer';
import { useShowMap } from '@/composables/useShowMap';
import useUser from '@/composables/useUser';
import { autoCopy, clipboardAllowed, copyButton } from '@/composables/useClipboardSetting';
import { displayAlias } from '@/lib/alias';
import { buildSignatureBookmark, visibleBookmarkName } from '@/lib/bookmark';
import { chainAliases } from '@/lib/combat';
import { signatureToast } from '@/lib/signatureToast';
import { formatCountdown, newStaticQuestion, ROLL_SCAN_AT_SECONDS, ROLL_SIG_DUE_SECONDS } from '@/lib/rageRoll';
import { useMapStore } from '@/map/store/mapStore';
import type { TSignature } from '@/types/models';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

/**
 * Patch 35: everything the rage roll puts over the map: the red breathing
 * vignette (for everyone), the hit watcher and its sound, the RAGE ROLL popup,
 * and "New Alpha?" after your paste in the rolling system.
 */
const { rageRoll, newStatic } = useRageRoll();
const { canEdit } = usePermission();
const page = useShowMap();
const store = useMapStore();
useRageRollWatcher();

/**
 * Patch 57: the roll timer. The countdown at the top of the map, the SCAN tone, and after one of
 * your characters comes back into the rolling system (the jump that may have collapsed the static)
 * a "Collapsed?" button: the timer then counts from that jump, not from the click.
 */
const { timer, markCollapsed } = useRollTimer();
useRollTimerTone();
const user = useUser();
const scanIn = computed(() => (timer.value?.phase === 'counting' ? timer.value.toScan : null));
const progress = computed(() => (timer.value?.phase === 'counting' ? Math.min(100, (timer.value.elapsed / ROLL_SIG_DUE_SECONDS) * 100) : 100));
const scanMark = (ROLL_SCAN_AT_SECONDS / ROLL_SIG_DUE_SECONDS) * 100;

type TCollapsePrompt = { name: string; at: number; from: number };
const collapsePrompt = ref<TCollapsePrompt | null>(null);
let promptTimer: ReturnType<typeof setTimeout> | null = null;
const PROMPT_SECONDS = 45;

const myLocations = computed(() => {
    const mine = new Set((user.value?.characters ?? []).map((character) => character.id));
    const where = new Map<number, { name: string; solarsystemId: number | null }>();
    for (const character of page.props.map_characters ?? []) {
        if (mine.has(character.id)) where.set(character.id, { name: character.name, solarsystemId: character.status?.solarsystem_id ?? null });
    }
    return where;
});

watch(myLocations, (now, before) => {
    const rolling = rageRoll.value?.solarsystem_id;
    if (!rolling || !canEdit.value || !before) return;
    for (const [id, place] of now) {
        const was = before.get(id)?.solarsystemId ?? null;
        if (place.solarsystemId === rolling && was !== null && was !== rolling) {
            showCollapsePrompt({ name: place.name, at: Date.now(), from: was });
        }
    }
});

function showCollapsePrompt(prompt: TCollapsePrompt): void {
    collapsePrompt.value = prompt;
    if (promptTimer) clearTimeout(promptTimer);
    promptTimer = setTimeout(() => (collapsePrompt.value = null), PROMPT_SECONDS * 1000);
}

function confirmCollapse(): void {
    const prompt = collapsePrompt.value;
    if (!prompt) return;
    markCollapsed(prompt.at, prompt.name, prompt.from);
    collapsePrompt.value = null;
}

onBeforeUnmount(() => {
    if (promptTimer) clearTimeout(promptTimer);
});

type TQuestion = { olds: TSignature[]; candidates: TSignature[] };
const question = ref<TQuestion | null>(null);
const oldPick = ref<number | null>(null);

const rollingSystem = computed(() => {
    const roll = rageRoll.value;
    if (!roll) return null;
    for (const system of store.systems.values()) {
        if (system.solarsystem_id === roll.solarsystem_id) return system;
    }
    return null;
});

watch(
    () => [lastPaste.value?.at, page.props.selected_map_solarsystem] as const,
    () => {
        const paste = lastPaste.value;
        const selected = page.props.selected_map_solarsystem;
        if (!paste || !canEdit.value || !rollingSystem.value || !selected) return;
        if (paste.mapSolarsystemId !== rollingSystem.value.id || selected.id !== paste.mapSolarsystemId) return;
        lastPaste.value = null;
        const found = newStaticQuestion(paste.beforeIds, (selected.signatures ?? []) as TSignature[]);
        if (!found) return;
        question.value = found;
        oldPick.value = found.olds[0]?.id ?? null;
    },
);

/** The name the old static goes by on the map (Alpha, A0…), for the question. */
function staticName(signature: TSignature): string {
    return displayAlias(signature.alias) || signature.signature_id?.slice(0, 3) || 'the static';
}

function answer(candidate: TSignature): void {
    if (oldPick.value === null) return;
    const old = question.value?.olds.find((signature) => signature.id === oldPick.value) ?? null;
    newStatic(oldPick.value, candidate.id);
    question.value = null;
    copyNewStatic(candidate, old);
}

/** Patch 37: the new static takes the old one's name: copy its bookmark ("  Alpha XUY C6s"). */
function copyNewStatic(candidate: TSignature, old: TSignature | null): void {
    const system = rollingSystem.value;
    if (!system || !old?.alias) return;
    const name = buildSignatureBookmark({
        signature: {
            ...candidate,
            is_static: true,
            is_wandering: false,
            wormhole: candidate.wormhole ?? old.wormhole,
            signature_type: candidate.wormhole ? candidate.signature_type : (old.signature_type ?? candidate.signature_type),
        },
        currentSystem: {
            alias: system.alias,
            class: system.solarsystem.class,
            statics: (system.solarsystem as { statics?: { name: string; leads_to?: string | null }[] | null }).statics ?? null,
            combatHome: Boolean(system.combat_home),
            combatColor: system.combat_color ?? null,
        },
        aliases: chainAliases([...store.systems.values()], system),
        formats: page.props.map,
        plannedAlias: old.alias,
    });
    if (!name) return;
    if (clipboardAllowed()) {
        void autoCopy(name);
        signatureToast.success(`New ${staticName(old)}: copied ${visibleBookmarkName(name)}`);
    } else {
        signatureToast.success(`New ${staticName(old)}: bookmark it as ${visibleBookmarkName(name)}`, { action: copyButton(name), duration: 20_000 });
    }
}
</script>

<template>
    <div class="contents">
    <div v-if="rageRoll" class="rage-vignette pointer-events-none absolute inset-0 z-[5]" aria-hidden="true" />

    <div
        v-if="rageRoll && timer?.phase === 'counting'"
        class="pointer-events-none absolute top-3 left-1/2 z-30 flex -translate-x-1/2 flex-col items-center gap-1 rounded-xl border border-red-700 bg-neutral-950/90 px-5 py-2 text-stone-100 shadow-lg shadow-red-950/60"
        role="timer"
        aria-live="off"
    >
        <span class="text-[10px] font-bold tracking-[0.14em] text-red-300 uppercase">New static signature in</span>
        <span class="font-mono text-4xl leading-none font-extrabold tabular-nums">{{ formatCountdown(timer.toDue) }}</span>
        <div class="relative h-1.5 w-64 rounded-full bg-red-950">
            <div class="h-1.5 rounded-full bg-red-500" :style="{ width: `${progress}%` }" />
            <div class="absolute -top-1 h-3.5 w-0.5 bg-amber-300" :style="{ left: `${scanMark}%` }" />
        </div>
        <span class="text-xs" :class="scanIn !== null && scanIn <= 0 ? 'font-bold text-amber-300' : 'text-stone-400'">
            {{ scanIn !== null && scanIn > 0 ? `Press Scan in ${formatCountdown(scanIn)}` : 'SCAN NOW' }}<span v-if="timer.by" class="text-stone-500"> · collapsed by {{ timer.by }}</span>
        </span>
    </div>

    <div
        v-if="rageRoll && collapsePrompt && canEdit"
        class="absolute right-3 bottom-16 z-40 w-72 rounded-lg border border-red-800 bg-neutral-950/95 p-3 text-sm text-stone-100 shadow-lg shadow-red-950/60"
        role="dialog"
        aria-label="Did the static collapse?"
    >
        <p class="font-display font-bold tracking-wider text-red-400 uppercase">⚡ Collapsed?</p>
        <p class="mt-1 text-xs text-stone-400">{{ collapsePrompt.name }} just came back. If that jump killed the static, the timer counts from that jump and the old chain is removed (unless someone's still in it).</p>
        <div class="mt-2 flex gap-2">
            <Button size="sm" class="bg-red-700 font-semibold text-white hover:bg-red-600" @click="confirmCollapse">Yes, start the timer</Button>
            <Button size="sm" variant="ghost" class="text-stone-300" @click="collapsePrompt = null">Not yet</Button>
        </div>
    </div>

    <div
        v-if="question"
        class="absolute top-3 left-1/2 z-40 w-[22rem] -translate-x-1/2 rounded-lg border border-red-800 bg-neutral-950/95 p-3 text-sm text-stone-100 shadow-lg shadow-red-950/60"
        role="dialog"
        aria-label="New static?"
    >
        <p class="font-display text-base font-bold tracking-wider text-red-400 uppercase">⚡ New {{ question.olds.length === 1 ? staticName(question.olds[0]) : 'static' }}?</p>
        <p class="mt-1 text-xs text-stone-400">
            Yes: the old one's chain keeps its names with a time stamp (e.g. Alpha~1958), its signature and pipe go, and the new signature takes the name.
        </p>
        <div v-if="question.olds.length > 1" class="mt-2 flex flex-wrap gap-1">
            <span class="text-xs text-stone-400">Replacing:</span>
            <button
                v-for="old in question.olds"
                :key="old.id"
                type="button"
                class="rounded px-1.5 py-0.5 font-mono text-xs"
                :class="oldPick === old.id ? 'bg-red-800 text-white' : 'bg-stone-800 text-stone-300'"
                @click="oldPick = old.id"
            >
                {{ staticName(old) }} · {{ old.signature_id?.slice(0, 3) }}
            </button>
        </div>
        <div class="mt-3 flex flex-wrap gap-2">
            <Button
                v-for="candidate in question.candidates"
                :key="candidate.id"
                size="sm"
                class="bg-red-700 font-mono font-semibold text-white hover:bg-red-600"
                @click="answer(candidate)"
            >
                Yes, {{ candidate.signature_id?.slice(0, 3) ?? 'this one' }}
            </Button>
            <Button size="sm" variant="ghost" class="text-stone-300" @click="question = null">No</Button>
        </div>
    </div>

    <RageRollDialog v-if="canEdit" />
    </div>
</template>

<style scoped>
/* Patch 35: a slow red breath around the map edges, over faint dark hazard stripes. */
.rage-vignette {
    background: repeating-linear-gradient(135deg, rgb(0 0 0 / 0) 0 18px, rgb(127 29 29 / 0.05) 18px 36px);
    box-shadow: inset 0 0 90px rgb(153 27 27 / 0.75);
    animation: rage-breathe 2.4s ease-in-out infinite;
}
@keyframes rage-breathe {
    0%,
    100% {
        opacity: 0.55;
    }
    50% {
        opacity: 1;
    }
}
@media (prefers-reduced-motion: reduce) {
    .rage-vignette {
        animation: none;
        opacity: 0.7;
    }
}
</style>
