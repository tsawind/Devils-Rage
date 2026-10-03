<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { clear_side_chains, clearSideChainSystems, sideChainSummaries } from '@/map/actions/clearSideChains';
import { useMapStore } from '@/map/store/mapStore';
import { computed, ref, watch } from 'vue';

/**
 * Patch 19: "Clear side chains?" — each side chain with a checkbox, how many
 * systems go, pinned ones noted (they stay).
 */
const store = useMapStore();

const open = computed({
    get: () => clear_side_chains.value !== null,
    set: (isOpen: boolean) => {
        if (!isOpen) clear_side_chains.value = null;
    },
});

const chains = computed(() => sideChainSummaries(store));
const ticked = ref<Set<number>>(new Set());
watch(clear_side_chains, (preset) => (ticked.value = new Set(preset ?? [])), { immediate: true });

function toggle(rootId: number): void {
    const next = new Set(ticked.value);
    if (next.has(rootId)) next.delete(rootId);
    else next.add(rootId);
    ticked.value = next;
}

const chosen = computed(() => chains.value.filter((chain) => ticked.value.has(chain.rootId)));
const removeCount = computed(() => chosen.value.reduce((total, chain) => total + chain.removeIds.length, 0));

function confirm(): void {
    const ids = chosen.value.flatMap((chain) => chain.removeIds);
    const label = chosen.value.length === 1 ? `the ${chosen.value[0].name} side chain` : `${chosen.value.length} side chains`;
    clear_side_chains.value = null;
    clearSideChainSystems(ids, label);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle>Clear side chains</DialogTitle>
                <DialogDescription>Tick the chains to remove. Pinned systems and the map's home stay where they are.</DialogDescription>
            </DialogHeader>
            <div class="grid gap-1.5">
                <label
                    v-for="chain in chains"
                    :key="chain.rootId"
                    class="flex cursor-pointer items-center gap-3 rounded-md border border-border/60 px-3 py-2 text-sm hover:bg-muted/50"
                >
                    <input type="checkbox" class="size-4 accent-red-500" :checked="ticked.has(chain.rootId)" @change="toggle(chain.rootId)" />
                    <span class="font-display font-semibold">{{ chain.name }}</span>
                    <span class="ml-auto text-xs text-muted-foreground">
                        {{ chain.removeIds.length }} system{{ chain.removeIds.length === 1 ? '' : 's' }}<template v-if="chain.pinned">
                            · {{ chain.pinned }} pinned stay</template
                        >
                    </span>
                </label>
                <p v-if="chains.length === 0" class="text-sm text-muted-foreground">There are no side chains on the map.</p>
            </div>
            <DialogFooter>
                <Button variant="outline" @click="open = false">Cancel</Button>
                <Button variant="destructive" :disabled="removeCount === 0" @click="confirm">
                    Remove {{ removeCount }} system{{ removeCount === 1 ? '' : 's' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
