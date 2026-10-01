<script setup lang="ts">
import CountdownBar from '@/components/combat/CountdownBar.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogFooter, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import { usePopupCountdown } from '@/composables/combat/usePopupCountdown';
import { visibleBookmarkName } from '@/lib/bookmark';
import { computed } from 'vue';

/**
 * A hole turned out to be the static. The scanner decides whether to rename it
 * to the static's number; keeping the name is the default (closing the popup,
 * or the combat countdown). When systems are already mapped further down the
 * hole, renaming is blocked (they were bookmarked from its name) and the popup
 * only says so.
 */
const props = defineProps<{
    signatureLabel: string;
    fromAlias: string;
    toAlias: string;
    /** Each in-game bookmark that changes, from → to. */
    changes: { label: string; from: string; to: string }[];
    /** Systems already mapped further down: renaming is blocked when there are any. */
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

const blocked = computed(() => props.beyond.length > 0);

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
                <DialogTitle v-if="blocked">{{ signatureLabel }} is the static</DialogTitle>
                <DialogTitle v-else>{{ signatureLabel }} is the static: rename {{ fromAlias }} → {{ toAlias }}?</DialogTitle>
                <DialogDescription v-if="blocked">Marked as the static. It keeps the name {{ fromAlias }}.</DialogDescription>
                <DialogDescription v-else>
                    It is marked as the static either way. Renaming means you change these bookmarks in game; keeping leaves every name as it is.
                </DialogDescription>
            </DialogHeader>

            <CountdownBar :remaining="remaining" :fraction="fraction" :action="blocked ? 'OK' : `Keep ${fromAlias}`" />

            <div v-if="blocked" class="px-6 py-4 text-xs">
                <p class="rounded-md bg-amber-500/15 px-3 py-2 text-amber-300">
                    Can't rename to {{ toAlias }}: {{ beyond.join(', ') }} {{ beyond.length === 1 ? 'is' : 'are' }} already mapped further down this chain.
                </p>
            </div>
            <div v-else class="grid gap-2 px-6 py-4 text-xs">
                <div v-for="change in changes" :key="change.label" class="grid gap-0.5">
                    <span class="text-muted-foreground">{{ change.label }}</span>
                    <span class="font-mono">
                        {{ visibleBookmarkName(change.from) }} <span class="text-muted-foreground">→</span>
                        <span class="text-emerald-400">{{ visibleBookmarkName(change.to) }}</span>
                    </span>
                </div>
            </div>

            <DialogFooter class="gap-2 border-t border-border/50 bg-muted/30 px-6 py-3 sm:justify-between">
                <Button v-if="blocked" autofocus @click="choose('keep')">OK</Button>
                <template v-else>
                    <Button variant="outline" autofocus @click="choose('keep')">Keep {{ fromAlias }}</Button>
                    <Button @click="choose('rename')">Rename to {{ toAlias }}</Button>
                </template>
            </DialogFooter>
        </DialogScrollContent>
    </Dialog>
</template>
