<script setup lang="ts">
import CountdownBar from '@/components/combat/CountdownBar.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogFooter, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import { usePopupCountdown } from '@/composables/combat/usePopupCountdown';
import { visibleBookmarkName } from '@/lib/bookmark';

/**
 * A hole that already has a number (bookmarked in game) turned out to be the
 * static. The scanner decides whether to rename it to the static's number;
 * keeping the name is the default (closing the popup, or the combat countdown).
 */
const props = defineProps<{
    signatureLabel: string;
    fromAlias: string;
    toAlias: string;
    /** Each in-game bookmark that changes, from → to. */
    changes: { label: string; from: string; to: string }[];
    /** Systems further down that keep their names. */
    beyond: string[];
    countdownSeconds?: number | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    choose: [choice: 'rename' | 'keep'];
}>();

function choose(choice: 'rename' | 'keep'): void {
    if (!open.value) return;
    open.value = false;
    emit('choose', choice);
}

function handleOpenChange(isOpen: boolean): void {
    if (!isOpen) choose('keep');
}

const { remaining, fraction } = usePopupCountdown(
    open,
    () => props.countdownSeconds ?? null,
    () => choose('keep'),
);
</script>

<template>
    <Dialog :open="open" @update:open="handleOpenChange">
        <DialogScrollContent class="max-w-md gap-0 overflow-hidden p-0">
            <DialogHeader class="gap-1.5 border-b border-border/50 bg-muted/30 px-6 py-4 text-left">
                <DialogTitle>{{ signatureLabel }} is the static: rename {{ fromAlias }} → {{ toAlias }}?</DialogTitle>
                <DialogDescription>
                    It is marked as the static either way. Renaming means these bookmarks change in game; keeping leaves every name as it is.
                </DialogDescription>
            </DialogHeader>

            <CountdownBar :remaining="remaining" :fraction="fraction" :action="`Keep ${fromAlias}`" />

            <div class="grid gap-2 px-6 py-4 text-xs">
                <div v-for="change in changes" :key="change.label" class="grid gap-0.5">
                    <span class="text-muted-foreground">{{ change.label }}</span>
                    <span class="font-mono">
                        {{ visibleBookmarkName(change.from) }} <span class="text-muted-foreground">→</span>
                        <span class="text-emerald-400">{{ visibleBookmarkName(change.to) }}</span>
                    </span>
                </div>
                <p v-if="beyond.length" class="text-muted-foreground">Systems further down keep their names: {{ beyond.join(', ') }}</p>
            </div>

            <DialogFooter class="gap-2 border-t border-border/50 bg-muted/30 px-6 py-3 sm:justify-between">
                <Button variant="outline" autofocus @click="choose('keep')">Keep {{ fromAlias }}</Button>
                <Button @click="choose('rename')">Rename to {{ toAlias }}</Button>
            </DialogFooter>
        </DialogScrollContent>
    </Dialog>
</template>
