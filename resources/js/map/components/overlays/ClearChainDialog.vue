<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useShowMap } from '@/composables/useShowMap';
import { displayAlias } from '@/lib/alias';
import { combatColorHex, combatColorLabel } from '@/lib/combat';
import { clear_chain_color, planChainClear } from '@/map/actions/clearChain';
import { clearCombatChain } from '@/map/actions/combat';
import { useMapStore } from '@/map/store/mapStore';
import { computed } from 'vue';

/**
 * "Clear the Red chain?" (patch 12): what goes, what stays, and who is still
 * working the chain (their Combat turns off).
 */
const store = useMapStore();
const page = useShowMap();

const open = computed({
    get: () => clear_chain_color.value !== null,
    set: (isOpen: boolean) => {
        if (!isOpen) clear_chain_color.value = null;
    },
});

const color = computed(() => clear_chain_color.value ?? '');
const label = computed(() => combatColorLabel(color.value) ?? color.value);
const hex = computed(() => combatColorHex(color.value) ?? '#888888');

const plan = computed(() =>
    planChainClear(color.value, [...store.systems.values()], [...store.connections.values()], store.meta.value?.home_solarsystem_id ?? null),
);

const home = computed(() => (plan.value.homeId !== null ? (store.systems.get(plan.value.homeId) ?? null) : null));
const homeName = computed(() => (home.value ? displayAlias(home.value.alias) || home.value.solarsystem.name : null));

/** Kept systems other than the home: they stay where they are still attached. */
const keptOthers = computed(() =>
    plan.value.kept
        .filter((id) => id !== plan.value.homeId)
        .map((id) => store.systems.get(id))
        .filter((system) => system !== undefined)
        .map((system) => displayAlias(system.alias) || system.solarsystem.name),
);

const workers = computed(() => home.value?.combat_workers ?? []);
const workersText = computed(() => {
    const names = workers.value;
    if (names.length === 0) return '';
    if (names.length === 1) return `${names[0]} is still working this chain. Their Combat will turn off.`;
    return `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]} are still working this chain. Their Combat will turn off.`;
});

function confirm(): void {
    const chain = color.value;
    clear_chain_color.value = null;
    if (chain) clearCombatChain(page.props.map.slug, chain, label.value);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <span class="inline-block size-2.5 rounded-full" :style="{ backgroundColor: hex }" />
                    Clear the {{ label }} chain?
                </DialogTitle>
                <DialogDescription>
                    Removes {{ plan.removed.length }} {{ plan.removed.length === 1 ? 'system' : 'systems' }} from the map.
                    <template v-if="homeName && plan.homeStays"> {{ homeName }} stays: it is still linked, so it moves back to the main chain.</template>
                    <template v-else-if="homeName"> {{ homeName }} goes too: nothing else is linked to it.</template>
                </DialogDescription>
            </DialogHeader>
            <p v-if="keptOthers.length" class="text-sm text-muted-foreground">
                Still attached elsewhere, so they stay (with their names): {{ keptOthers.join(', ') }}.
            </p>
            <p v-if="workersText" class="rounded-md bg-amber-500/15 px-3 py-2 text-sm text-amber-300">{{ workersText }}</p>
            <DialogFooter>
                <Button variant="secondary" @click="open = false">Cancel</Button>
                <Button variant="destructive" @click="confirm">Clear {{ label }} chain</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
