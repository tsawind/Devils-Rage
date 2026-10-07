<script setup lang="ts">
import { Button } from '@/components/ui/button';
import InputError from '@/components/ui/error/InputError.vue';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import usePermission from '@/composables/usePermission';
import { useRageRoll } from '@/composables/useRageRoll';
import { rageRollHit } from '@/composables/useRageRollWatcher';
import { useStaticSolarsystem } from '@/composables/useStaticSolarsystems';
import { displayAlias } from '@/lib/alias';
import { formatElapsed, parseTargets } from '@/lib/rageRoll';
import { tryUseMapStore } from '@/map/store/mapStore';
import { useNow } from '@vueuse/core';
import { computed, ref, watch } from 'vue';

/**
 * Patch 35: the RAGE ROLL chip in the map bar, for everyone while a roll is on:
 * what is rolling, the time since it started, the targets (editable by members),
 * and a green TARGET HIT when the static opened into one.
 */
const { rageRoll, setTargets, stopRageRoll } = useRageRoll();
const { canEdit } = usePermission();
const now = useNow({ interval: 1000 });

const solarsystem = useStaticSolarsystem(computed(() => rageRoll.value?.solarsystem_id ?? null));
const name = computed(() => {
    const roll = rageRoll.value;
    if (!roll) return '';
    const store = tryUseMapStore();
    let alias: string | null = null;
    for (const system of store?.systems.values() ?? []) {
        if (system.solarsystem_id === roll.solarsystem_id) alias = system.alias;
    }
    return displayAlias(alias) || solarsystem.value?.name || 'the static';
});
const statics = computed(() => (solarsystem.value?.statics ?? []).map((value) => `${value.name} → ${value.leads_to.toUpperCase()}`).join(' + '));
const elapsed = computed(() => formatElapsed(rageRoll.value?.started_at ?? null, now.value.getTime()));
const targetNames = computed(() => (rageRoll.value?.targets ?? []).map((target) => target.name));

const editing = ref('');
const error = ref<string | null>(null);
watch(
    targetNames,
    (names) => {
        editing.value = names.join(', ');
    },
    { immediate: true },
);

function saveTargets(): void {
    error.value = null;
    setTargets(parseTargets(editing.value), (message) => (error.value = message));
}
</script>

<template>
    <Popover v-if="rageRoll">
        <PopoverTrigger as-child>
            <button
                type="button"
                class="rage-chip flex h-8 max-w-[26rem] items-center gap-1.5 rounded-full px-3 font-display text-xs font-bold tracking-widest whitespace-nowrap text-white uppercase transition-colors"
                :class="rageRollHit ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-red-800 hover:bg-red-700'"
                :title="rageRollHit ? `Target hit: ${rageRollHit.text}` : `Rage roll: ${name}`"
            >
                <template v-if="rageRollHit">
                    <span>🎯 Target hit</span>
                    <span class="min-w-0 truncate font-sans tracking-normal normal-case">{{ rageRollHit.text }}</span>
                </template>
                <template v-else>
                    <span>⚡ Rage roll</span>
                    <span class="min-w-0 truncate">· {{ name }}</span>
                    <span v-if="statics" class="hidden truncate font-mono tracking-normal lg:inline">· {{ statics }}</span>
                    <span v-if="rageRoll.scanning" class="rounded bg-black/30 px-1 tracking-normal">⚔ scan</span>
                </template>
                <span class="font-mono tracking-normal text-red-100/80">{{ elapsed }}</span>
            </button>
        </PopoverTrigger>
        <PopoverContent align="center" class="w-80 border-red-900/70">
            <div class="grid gap-3 text-sm">
                <div>
                    <p class="font-display font-bold tracking-wider text-red-400 uppercase">⚡ Rage roll · {{ name }}</p>
                    <p class="text-xs text-muted-foreground">
                        Started by {{ rageRoll.started_by ?? 'someone' }} · {{ elapsed }} ago<span v-if="rageRoll.scanning"> · Rage Scanning session (Rage speed for everyone)</span>
                    </p>
                    <p v-if="statics" class="mt-1 font-mono text-xs">{{ statics }}</p>
                </div>

                <div class="grid gap-1.5">
                    <p class="text-xs font-medium">Target systems</p>
                    <template v-if="canEdit">
                        <form class="flex gap-2" @submit.prevent="saveTargets">
                            <Input v-model="editing" placeholder="J123456, J234567" class="h-8 font-mono text-xs" />
                            <Button type="submit" size="sm" variant="secondary">Save</Button>
                        </form>
                        <InputError :message="error ?? undefined" />
                    </template>
                    <p v-else class="font-mono text-xs">{{ targetNames.join(', ') || 'None' }}</p>
                    <p class="text-xs text-muted-foreground">When the static opens into one, everyone with the map open hears it.</p>
                </div>

                <Button v-if="canEdit" size="sm" variant="ghost" class="justify-self-start text-red-400 hover:text-red-300" @click="stopRageRoll">End the roll</Button>
            </div>
        </PopoverContent>
    </Popover>
</template>

<style scoped>
.rage-chip {
    animation: rage-chip 1.2s ease-in-out infinite;
}
@keyframes rage-chip {
    0%,
    100% {
        box-shadow: 0 0 0 1px rgb(239 68 68 / 0.6), 0 0 8px rgb(220 38 38 / 0.5);
    }
    50% {
        box-shadow: 0 0 0 1px rgb(248 113 113 / 0.9), 0 0 18px rgb(220 38 38 / 0.9);
    }
}
@media (prefers-reduced-motion: reduce) {
    .rage-chip {
        animation: none;
    }
}
</style>
