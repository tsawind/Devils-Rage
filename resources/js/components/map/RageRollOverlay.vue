<script setup lang="ts">
import RageRollDialog from '@/components/map/RageRollDialog.vue';
import { Button } from '@/components/ui/button';
import usePermission from '@/composables/usePermission';
import { lastPaste, useRageRoll } from '@/composables/useRageRoll';
import { useRageRollWatcher } from '@/composables/useRageRollWatcher';
import { useShowMap } from '@/composables/useShowMap';
import { displayAlias } from '@/lib/alias';
import { newStaticQuestion } from '@/lib/rageRoll';
import { useMapStore } from '@/map/store/mapStore';
import type { TSignature } from '@/types/models';
import { computed, ref, watch } from 'vue';

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
    newStatic(oldPick.value, candidate.id);
    question.value = null;
}
</script>

<template>
    <div class="contents">
    <div v-if="rageRoll" class="rage-vignette pointer-events-none absolute inset-0 z-[5]" aria-hidden="true" />

    <div
        v-if="question"
        class="absolute top-3 left-1/2 z-40 w-[22rem] -translate-x-1/2 rounded-lg border border-red-800 bg-neutral-950/95 p-3 text-sm text-stone-100 shadow-lg shadow-red-950/60"
        role="dialog"
        aria-label="New static?"
    >
        <p class="font-display text-base font-bold tracking-wider text-red-400 uppercase">⚡ New {{ question.olds.length === 1 ? staticName(question.olds[0]) : 'static' }}?</p>
        <p class="mt-1 text-xs text-stone-400">
            Yes: the old one's chain keeps its names with a time stamp (e.g. Alpha@1958), its signature and pipe go, and the new signature takes the name.
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
