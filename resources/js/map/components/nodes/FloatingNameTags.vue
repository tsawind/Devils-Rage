<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { displayAlias } from '@/lib/alias';
import { floatingLabel, floatingNames, floatingTooltip, type TFloatingName } from '@/lib/floatingNames';
import { changeNames } from '@/map/actions/names';
import type { MapStore } from '@/map/store/mapStore';
import type { TMapSolarsystem } from '@/pages/maps';
import { X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Patch 39: the floating names of a system in small violet tags above its card: "also A2"
 * (a loop, or an old chain's new number), "was A1~1917" (its name before a rename). ✕ asks
 * first and drops the name (Ctrl+Z puts it back).
 */
const { system, store, canWrite } = defineProps<{ system: TMapSolarsystem; store: MapStore; canWrite: boolean }>();

const scheme = computed(() => store.meta.value?.bookmark_alias_scheme);
const names = computed(() => floatingNames(system, store.connections.values()));
const confirming = ref<string | null>(null);

function via(floating: TFloatingName): string | null {
    return floating.viaSystemId !== undefined ? (store.systems.get(floating.viaSystemId)?.alias ?? null) : null;
}

function drop(floating: TFloatingName): void {
    confirming.value = null;
    const name = displayAlias(floating.name, scheme.value);
    changeNames(system.id, floating.signatureId ? { action: 'drop', signature_id: floating.signatureId } : { action: 'drop', name: floating.name }, `Drop ${name}`);
}
</script>

<template>
    <div v-if="names.length" class="pointer-events-auto absolute -top-[18px] left-1 z-10 flex max-w-[170px] gap-1 overflow-hidden" @click.stop @pointerdown.stop>
        <span
            v-for="floating in names"
            :key="`${floating.kind}:${floating.name}`"
            class="flex shrink-0 items-center gap-0.5 rounded-[3px] border border-violet-500/50 bg-violet-950/85 py-px pr-0.5 pl-1 font-mono text-[11px] leading-[13px] text-violet-200"
            :title="floatingTooltip(floating, system.alias, via(floating), scheme)"
        >
            {{ floatingLabel(floating, scheme) }}
            <Popover v-if="canWrite" :open="confirming === floating.name" @update:open="(value) => (confirming = value ? floating.name : null)">
                <PopoverTrigger as-child>
                    <button type="button" class="rounded-sm text-violet-300/80 hover:bg-violet-800 hover:text-white" :title="`Drop ${displayAlias(floating.name, scheme)}`">
                        <X class="size-3" />
                    </button>
                </PopoverTrigger>
                <PopoverContent class="w-64 text-xs" side="top">
                    <p class="mb-2">
                        Drop <span class="font-mono font-bold">{{ displayAlias(floating.name, scheme) }}</span>?
                        <template v-if="floating.kind === 'was'"> It's forgotten; bookmarks in game that still say it stay as they are.</template>
                        <template v-else> That hole then uses {{ displayAlias(system.alias, scheme) || 'this system' }}'s name, in its bookmark and its way back.</template>
                    </p>
                    <p class="mb-2 text-muted-foreground">Ctrl+Z puts it back.</p>
                    <div class="flex justify-end gap-2">
                        <Button variant="outline" size="sm" class="h-7" @click="confirming = null">Cancel</Button>
                        <Button size="sm" class="h-7" @click="drop(floating)">Drop</Button>
                    </div>
                </PopoverContent>
            </Popover>
        </span>
    </div>
</template>
