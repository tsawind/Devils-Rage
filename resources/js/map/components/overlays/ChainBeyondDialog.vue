<script lang="ts">
import { ref } from 'vue';

/** Patch 37: the system "Delete system and chain beyond" is asking about (null: closed). */
export const chainBeyondTarget = ref<number | null>(null);
</script>

<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { displayAlias } from '@/lib/alias';
import { planChainBeyond } from '@/lib/chainBeyond';
import { clearSideChainSystems } from '@/map/actions/clearSideChains';
import { useMapStore } from '@/map/store/mapStore';
import { computed } from 'vue';

const store = useMapStore();

const open = computed({
    get: () => chainBeyondTarget.value !== null,
    set: (isOpen: boolean) => {
        if (!isOpen) chainBeyondTarget.value = null;
    },
});

const nameOf = (id: number): string => {
    const system = store.systems.get(id);
    return system ? displayAlias(system.alias) || system.solarsystem.name : '?';
};

const plan = computed(() =>
    chainBeyondTarget.value === null
        ? { removed: [], kept: [] }
        : planChainBeyond(
              chainBeyondTarget.value,
              [...store.systems.values()],
              [...store.connections.values()],
              store.meta.value?.home_solarsystem_id ?? null,
              store.meta.value?.rally_solarsystem_id ?? null,
          ),
);

const name = computed(() => (chainBeyondTarget.value === null ? '' : nameOf(chainBeyondTarget.value)));
const others = computed(() => plan.value.removed.slice(1).map(nameOf));
const kept = computed(() => plan.value.kept.map(nameOf));

function confirm(): void {
    const ids = plan.value.removed;
    const label = `${name.value} and the chain beyond`;
    chainBeyondTarget.value = null;
    clearSideChainSystems(ids, label);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Delete {{ name }} and the chain beyond?</DialogTitle>
                <DialogDescription>
                    Hides {{ plan.removed.length }} {{ plan.removed.length === 1 ? 'system' : 'systems' }} (back as they were if someone reconnects within 27 h).
                </DialogDescription>
            </DialogHeader>
            <p v-if="others.length" class="text-sm text-muted-foreground">Beyond it: {{ others.join(', ') }}.</p>
            <p v-if="kept.length" class="text-sm text-muted-foreground">Pinned or rally, so they stay (with what hangs off them): {{ kept.join(', ') }}.</p>
            <DialogFooter>
                <Button variant="secondary" @click="open = false">Cancel</Button>
                <Button variant="destructive" :disabled="plan.removed.length === 0" @click="confirm">Delete {{ plan.removed.length }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
