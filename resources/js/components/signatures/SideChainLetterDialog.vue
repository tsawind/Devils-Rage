<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogFooter, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import { displayAlias } from '@/lib/alias';
import { SIDE_CHAIN_LETTERS } from '@/lib/sideChain';
import { ref, watch } from 'vue';

/**
 * A new side chain needs a letter (patch 12): another chain not linked to
 * Daisy already uses the plain numbers. Any free letter can be picked; the
 * suggestion (Z, then Y, X…) is used when the popup is closed.
 */
const props = defineProps<{
    systemName: string;
    suggested: string;
    taken: ReadonlySet<string>;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    choose: [letter: string];
}>();

const picked = ref(props.suggested);
watch(
    () => [open.value, props.suggested] as const,
    ([isOpen, suggested]) => {
        if (isOpen) picked.value = suggested;
    },
);

function choose(letter: string): void {
    if (!open.value) return;
    open.value = false;
    emit('choose', letter);
}

function handleOpenChange(isOpen: boolean): void {
    if (!isOpen) choose(picked.value || props.suggested);
}
</script>

<template>
    <Dialog :open="open" @update:open="handleOpenChange">
        <DialogScrollContent class="max-w-md gap-0 overflow-hidden p-0">
            <DialogHeader class="gap-1.5 border-b border-border/50 bg-muted/30 px-6 py-4 text-left">
                <DialogTitle>New side chain from {{ systemName }}: pick a letter</DialogTitle>
                <DialogDescription>
                    Another chain not linked to Daisy already uses the plain numbers. The start shows as its callsign on the map; bookmarks read
                    {{ picked }}1, {{ picked }}11, {{ picked }}111-1…
                </DialogDescription>
            </DialogHeader>
            <div class="flex flex-wrap gap-1.5 px-6 py-4">
                <button
                    v-for="letter in SIDE_CHAIN_LETTERS"
                    :key="letter"
                    type="button"
                    :disabled="taken.has(letter)"
                    :title="taken.has(letter) ? `${letter} is already used on the map` : displayAlias(letter)"
                    class="flex size-8 items-center justify-center rounded-md border text-sm transition-colors disabled:cursor-not-allowed disabled:line-through disabled:opacity-30"
                    :class="letter === picked ? 'border-2 border-foreground font-semibold' : 'border-border hover:bg-muted'"
                    @click="picked = letter"
                >
                    {{ letter }}
                </button>
            </div>
            <DialogFooter class="gap-2 border-t border-border/50 bg-muted/30 px-6 py-3">
                <Button :disabled="!picked" @click="choose(picked)">Use {{ picked }} ({{ displayAlias(picked) }})</Button>
            </DialogFooter>
        </DialogScrollContent>
    </Dialog>
</template>
