<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogFooter, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { clipboardAllowed } from '@/composables/useClipboardSetting';
import { customLetter, isCustomName, renameProblem } from '@/lib/alias';
import { visibleBookmarkName } from '@/lib/bookmark';
import { computed, ref, watch } from 'vue';

/**
 * Patch 37: double-click a hole's name in the signature list to give it your own name.
 * The popup shows the in-game bookmarks that change (this side, and the way back on the
 * far side), and refuses a name already on the map or one whose first letter another
 * chain already uses. Holes beyond a typed name are numbered by its first letter
 * ("Gas" → G1, G2…).
 */
const props = defineProps<{
    currentName: string;
    /** The names on the map except this hole's system and its chain below. */
    others: readonly string[];
    ignoredAlias: string | null;
    /** The bookmarks for a name: this side, and the way back when the hole is jumped. */
    bookmarks: (name: string) => { thisSide: string; wayBack: string | null };
    jumped: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ rename: [name: string, copy: boolean] }>();

const name = ref(props.currentName);
watch(open, (isOpen) => {
    if (isOpen) name.value = props.currentName;
});

const trimmed = computed(() => name.value.trim());
const unchanged = computed(() => trimmed.value === props.currentName.trim());
const problem = computed(() => (unchanged.value ? null : renameProblem(trimmed.value, props.others, props.ignoredAlias)));
const canRename = computed(() => !unchanged.value && problem.value === null);
const before = computed(() => props.bookmarks(props.currentName));
const after = computed(() => (canRename.value ? props.bookmarks(trimmed.value) : null));
const beyondNote = computed(() => {
    if (!canRename.value) return null;
    if (isCustomName(trimmed.value)) {
        const letter = customLetter(trimmed.value);
        return `Holes beyond it: ${letter}1, ${letter}2… (rage lanes keep their jump numbers).`;
    }
    return `Holes beyond it: ${trimmed.value.toUpperCase()}1, ${trimmed.value.toUpperCase()}2…`;
});
const offerQuiet = computed(() => clipboardAllowed());

function rename(copy: boolean): void {
    if (!canRename.value) return;
    open.value = false;
    emit('rename', trimmed.value, copy);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogScrollContent class="max-w-md gap-0 overflow-hidden p-0">
            <DialogHeader class="gap-1.5 border-b border-border/50 bg-muted/30 px-6 py-4 text-left">
                <DialogTitle>Rename {{ currentName || 'this hole' }}</DialogTitle>
                <DialogDescription>
                    Your own name for {{ jumped ? 'this hole and the system it leads to' : 'this hole' }}. The mapper never changes a typed name by
                    itself.
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-3 px-6 py-4 text-xs" @submit.prevent="rename(true)">
                <Input v-model="name" autofocus maxlength="24" class="font-mono" placeholder="Gas" aria-label="New name" />
                <p v-if="problem" class="rounded-md bg-amber-500/15 px-3 py-2 text-amber-700 dark:text-amber-300">{{ problem }}</p>
                <div v-if="after" class="grid gap-2">
                    <div class="grid gap-0.5">
                        <span class="text-muted-foreground">This side</span>
                        <span class="font-mono">
                            {{ visibleBookmarkName(before.thisSide) }} <span class="text-muted-foreground">→</span>
                            <span class="text-emerald-600 dark:text-emerald-400">{{ visibleBookmarkName(after.thisSide) }}</span>
                        </span>
                    </div>
                    <div v-if="after.wayBack && before.wayBack" class="grid gap-0.5">
                        <span class="text-muted-foreground">Way back (far side)</span>
                        <span class="font-mono">
                            {{ visibleBookmarkName(before.wayBack) }} <span class="text-muted-foreground">→</span>
                            <span class="text-emerald-600 dark:text-emerald-400">{{ visibleBookmarkName(after.wayBack) }}</span>
                        </span>
                    </div>
                    <span class="text-muted-foreground">{{ beyondNote }}</span>
                </div>
            </form>
            <DialogFooter class="gap-2 border-t border-border/50 bg-muted/30 px-6 py-3 sm:justify-between">
                <Button variant="outline" @click="open = false">Cancel</Button>
                <div class="flex flex-wrap justify-end gap-2">
                    <Button v-if="offerQuiet" variant="secondary" :disabled="!canRename" @click="rename(false)">Rename, don't copy</Button>
                    <Button :disabled="!canRename" @click="rename(true)">Rename{{ offerQuiet ? ' and copy' : '' }}</Button>
                </div>
            </DialogFooter>
        </DialogScrollContent>
    </Dialog>
</template>
