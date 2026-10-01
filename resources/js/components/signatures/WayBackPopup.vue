<script setup lang="ts">
import { useCombat } from '@/composables/combat/useCombat';
import { clearHeldWayBack, copyHeldWayBack, heldWayBack } from '@/composables/signatures/wayBack';
import { visibleBookmarkName } from '@/lib/bookmark';
import { X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, watch } from 'vue';

/**
 * Patch 14: right after a jump EVE has focus, so the mapper can't copy the way
 * back yet. This popup holds it: any click in the mapper copies it (and the
 * popup closes); pasting the return signature copies a better one. Not shown
 * in combat mode (the signatures header has a small chip instead).
 */
const { is_combat } = useCombat();

const shown = computed(() => Boolean(heldWayBack.value) && !is_combat.value);

function handleClick(event: MouseEvent): void {
    if (!heldWayBack.value || is_combat.value) return;
    if (event.target instanceof Element && event.target.closest('[data-wayback-dismiss]')) return;
    void copyHeldWayBack();
}

let listening = false;
watch(
    shown,
    (isShown) => {
        if (isShown && !listening) {
            window.addEventListener('click', handleClick, true);
            listening = true;
        } else if (!isShown && listening) {
            window.removeEventListener('click', handleClick, true);
            listening = false;
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (listening) window.removeEventListener('click', handleClick, true);
});
</script>

<template>
    <div v-if="shown && heldWayBack" class="pointer-events-none absolute inset-x-0 top-4 z-50 flex justify-center">
        <div
            class="pointer-events-auto relative w-[26rem] cursor-pointer rounded-xl border-2 border-red-500 bg-red-950/90 px-5 py-3 text-center text-red-100 shadow-lg"
            role="alert"
        >
            <button
                type="button"
                data-wayback-dismiss
                class="absolute top-2 right-2 rounded p-0.5 text-red-300 hover:bg-red-500/20"
                aria-label="Dismiss without copying"
                @click="clearHeldWayBack"
            >
                <X class="size-4" />
            </button>
            <p class="text-sm font-semibold">Click the mapper to copy your way back</p>
            <p class="mt-1 font-mono text-sm">{{ visibleBookmarkName(heldWayBack.name) }}</p>
            <p class="mt-1 text-xs text-red-300">Or paste the return signature for a better return bookmark</p>
        </div>
    </div>
</template>
