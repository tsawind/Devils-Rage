<script setup lang="ts">
import { useCombat } from '@/composables/combat/useCombat';
import { clearHeldWayBack, copyHeldWayBack, heldWayBack, requestSignaturePaste } from '@/composables/signatures/wayBack';
import { displayAlias } from '@/lib/alias';
import { visibleBookmarkName } from '@/lib/bookmark';
import { tryUseMapStore } from '@/map/store/mapStore';
import { ClipboardPaste, X } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Patch 14: right after a jump EVE has focus, so the mapper can't copy the way
 * back yet. This popup holds it. Only a click on the red area copies it (a
 * click elsewhere must not overwrite the signatures you just copied in game);
 * the green area pastes your signatures instead, and when the return signature
 * is among them the better way back is copied and the popup closes. Not shown
 * in combat mode (the signatures header has a small chip instead).
 */
const { is_combat } = useCombat();

const shown = computed(() => Boolean(heldWayBack.value) && !is_combat.value);

/** Patch 37: an alt jumped: its name and the system its scan goes into. */
const store = tryUseMapStore();
const alt = computed(() => {
    const held = heldWayBack.value?.alt;
    if (!held) return null;
    const system = store ? [...store.systems.values()].find((candidate) => candidate.solarsystem_id === held.solarsystemId) : null;
    return { name: held.characterName, system: system ? displayAlias(system.alias) || system.solarsystem.name : 'its system' };
});
</script>

<template>
    <div v-if="shown && heldWayBack" class="pointer-events-none absolute inset-x-0 top-4 z-50 flex justify-center">
        <div class="pointer-events-auto relative flex w-[26rem] flex-col gap-2" role="alert">
            <button
                type="button"
                class="relative rounded-xl border-2 border-red-500 bg-red-950/90 px-5 py-3 text-center text-red-100 shadow-lg transition-colors hover:bg-red-900/90"
                @click="copyHeldWayBack"
            >
                <span v-if="alt" class="block text-xs font-semibold tracking-wide text-red-300 uppercase">{{ alt.name }} jumped into {{ alt.system }}</span>
                <span class="block text-sm font-semibold">Click here to copy {{ alt ? `${alt.name}'s` : 'your' }} way back</span>
                <span class="mt-1 block font-mono text-sm">{{ visibleBookmarkName(heldWayBack.name) }}</span>
                <span class="mt-1 block text-xs text-red-300">Copying replaces your clipboard</span>
            </button>
            <button
                type="button"
                class="absolute top-2 right-2 rounded p-0.5 text-red-300 hover:bg-red-500/20"
                aria-label="Dismiss without copying"
                @click="clearHeldWayBack"
            >
                <X class="size-4" />
            </button>
            <button
                type="button"
                class="rounded-xl border-2 border-emerald-500 bg-emerald-950/90 px-5 py-3 text-center text-emerald-100 shadow-lg transition-colors hover:bg-emerald-900/90"
                @click="requestSignaturePaste"
            >
                <span class="flex items-center justify-center gap-1.5 text-sm font-semibold"
                    ><ClipboardPaste class="size-4" /> {{ alt ? `Click here to paste ${alt.name}'s signatures into ${alt.system}` : 'Click here to paste your signatures' }}</span
                >
                <span class="mt-1 block text-xs text-emerald-300">{{
                    alt ? 'Or Ctrl+V. Your view stays where it is; the way back is copied with the paste' : 'Or Ctrl+V. The return signature gives a better way back'
                }}</span>
            </button>
        </div>
    </div>
</template>
