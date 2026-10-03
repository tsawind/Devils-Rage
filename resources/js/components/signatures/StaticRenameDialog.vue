<script setup lang="ts">
import CountdownBar from '@/components/combat/CountdownBar.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogFooter, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import { usePopupCountdown } from '@/composables/combat/usePopupCountdown';
import { visibleBookmarkName } from '@/lib/bookmark';
import { clipboardAllowed } from '@/composables/useClipboardSetting';
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
    /** Patch 18b: `keep` = the bookmark after "Keep" (shown as its own row when set). */
    changes: { label: string; from: string; to: string; keep?: string }[];
    /** Systems already mapped further down: renaming is blocked when there are any. */
    beyond: string[];
    countdownSeconds?: number | null;
    /** Patch 14: the same popup for other renames (Set number, unticking the static). */
    title?: string | null;
    description?: string | null;
    keepLabel?: string | null;
    renameLabel?: string | null;
    /** Patch 20: renaming copies the new bookmark: offer "Rename, don't copy" too. */
    copies?: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    choose: [choice: 'rename' | 'rename-quiet' | 'keep'];
}>();

/** Patch 20: with the Clipboard switch off nothing is copied anyway, so just "Rename". */
const offerQuiet = computed(() => Boolean(props.copies) && clipboardAllowed());

function choose(choice: 'rename' | 'rename-quiet' | 'keep'): void {
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
                <DialogTitle v-else>{{ title ?? `${signatureLabel} is the static: rename ${fromAlias} → ${toAlias}?` }}</DialogTitle>
                <DialogDescription v-if="blocked">{{ title ? 'Nothing was changed.' : 'Marked as the static.' }} It keeps the name {{ fromAlias }}.</DialogDescription>
                <DialogDescription v-else>
                    {{
                        description ??
                        'It is marked as the static either way. Renaming means you change these bookmarks in game; keeping leaves every name as it is.'
                    }}
                </DialogDescription>
            </DialogHeader>

            <CountdownBar :remaining="remaining" :fraction="fraction" :action="blocked ? 'OK' : (keepLabel ?? `Keep ${fromAlias}`)" />

            <div v-if="blocked" class="px-6 py-4 text-xs">
                <p class="rounded-md bg-amber-500/15 px-3 py-2 text-amber-300">
                    Can't rename to {{ toAlias }}: {{ beyond.join(', ') }} {{ beyond.length === 1 ? 'is' : 'are' }} already mapped further down this chain.
                </p>
            </div>
            <div v-else class="grid gap-2 px-6 py-4 text-xs">
                <div v-for="change in changes" :key="change.label" class="grid gap-0.5">
                    <span class="text-muted-foreground">{{ change.label }}</span>
                    <!-- Patch 18b: what each button leaves you with. -->
                    <div v-if="change.keep !== undefined" class="grid grid-cols-[auto_1fr] items-baseline gap-x-3 gap-y-0.5 font-mono">
                        <span class="font-sans font-semibold text-muted-foreground">Now</span>
                        <span>{{ visibleBookmarkName(change.from) }}</span>
                        <span class="font-sans font-semibold text-emerald-400">{{ renameLabel ?? `Rename to ${toAlias}` }}</span>
                        <span class="text-emerald-400">{{ visibleBookmarkName(change.to) }}</span>
                        <span class="font-sans font-semibold text-sky-300">{{ keepLabel ?? `Keep ${fromAlias}` }}</span>
                        <span :class="change.keep === change.from ? 'text-muted-foreground' : 'text-sky-300'">
                            {{ change.keep === change.from ? 'no change' : visibleBookmarkName(change.keep ?? '') }}
                        </span>
                    </div>
                    <span v-else class="font-mono">
                        {{ visibleBookmarkName(change.from) }} <span class="text-muted-foreground">→</span>
                        <span class="text-emerald-400">{{ visibleBookmarkName(change.to) }}</span>
                    </span>
                </div>
            </div>

            <DialogFooter class="gap-2 border-t border-border/50 bg-muted/30 px-6 py-3 sm:justify-between">
                <Button v-if="blocked" autofocus @click="choose('keep')">OK</Button>
                <template v-else>
                    <Button variant="outline" autofocus @click="choose('keep')">{{ keepLabel ?? `Keep ${fromAlias}` }}</Button>
                    <div class="flex flex-wrap justify-end gap-2">
                        <Button v-if="offerQuiet" variant="secondary" @click="choose('rename-quiet')">Rename, don't copy</Button>
                        <Button @click="choose('rename')">{{ renameLabel ?? `Rename to ${toAlias}` }}{{ offerQuiet ? ' and copy' : '' }}</Button>
                    </div>
                </template>
            </DialogFooter>
        </DialogScrollContent>
    </Dialog>
</template>
