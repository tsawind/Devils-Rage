<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogFooter, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { clipboardAllowed } from '@/composables/useClipboardSetting';
import { customLetter, displayAlias, isCustomName, renameProblem } from '@/lib/alias';
import { visibleBookmarkName } from '@/lib/bookmark';
import { computed, ref, watch } from 'vue';
import type { TRenameMode } from '@/map/actions/names';

/**
 * Patch 37: double-click a hole's name in the signature list to give it your own name.
 * Patch 39: also dotted boxes and systems on the map; a name in use can still be taken
 * (swap, give the other the next number, or keep it as a floating name).
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
    /**
     * Patch 39: who already has a name, so a taken name can still be used: swap with them,
     * give them the next number, or keep it as a floating name. Null when nobody has it.
     */
    holderOf?: ((name: string) => { label: string; next: string | null } | null) | null;
    /** Patch 43: the system's occupier and whose home it is (shown when the system is on the map). */
    details?: { occupier: string; homeOf: string } | null;
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{
    rename: [name: string, copy: boolean, mode: TRenameMode, next: string | null];
    details: [occupier: string, homeOf: string];
}>();

const name = ref(props.currentName);
const occupier = ref(props.details?.occupier ?? '');
const homeOf = ref(props.details?.homeOf ?? '');
watch(open, (isOpen) => {
    if (!isOpen) return;
    name.value = props.currentName;
    occupier.value = props.details?.occupier ?? '';
    homeOf.value = props.details?.homeOf ?? '';
});
const detailsChanged = computed(
    () => Boolean(props.details) && (occupier.value.trim() !== (props.details?.occupier ?? '').trim() || homeOf.value.trim() !== (props.details?.homeOf ?? '').trim()),
);

const trimmed = computed(() => name.value.trim());
const unchanged = computed(() => trimmed.value === props.currentName.trim());
/** Patch 39: the name is someone else's: the three ways to still use it. */
const holder = computed(() => (unchanged.value || !props.holderOf ? null : props.holderOf(trimmed.value)));
const mode = ref<TRenameMode>('swap');
watch(holder, (value) => {
    if (value && mode.value === 'bump' && !value.next) mode.value = 'swap';
});
const problem = computed(() => {
    if (unchanged.value) return null;
    const reason = renameProblem(trimmed.value, props.others, props.ignoredAlias);
    // A name on the map can still be taken with one of the choices below.
    return holder.value && reason?.endsWith('is already on the map.') ? null : reason;
});
const canRename = computed(() => !unchanged.value && problem.value === null && (!holder.value || mode.value !== 'float' || props.jumped));
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

/** Patch 43: only the occupier / home changed (the name stays): just save those. */
const onlyDetails = computed(() => unchanged.value && detailsChanged.value);

function rename(copy: boolean): void {
    if (!canRename.value && !onlyDetails.value) return;
    // Patch 45f: tell the parent first; closing clears what it's renaming (Save did nothing).
    if (detailsChanged.value) emit('details', occupier.value.trim(), homeOf.value.trim());
    if (canRename.value) emit('rename', trimmed.value, copy, holder.value ? mode.value : 'plain', holder.value?.next ?? null);
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogScrollContent class="max-w-md gap-0 overflow-hidden p-0">
            <DialogHeader class="gap-1.5 border-b border-border/50 bg-muted/30 px-6 py-4 text-left">
                <DialogTitle>Rename {{ displayAlias(currentName) || 'this hole' }}</DialogTitle>
                <DialogDescription>
                    Your own name for {{ jumped ? 'this hole and the system it leads to' : 'this hole' }}. The mapper never changes a typed name by
                    itself.
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-3 px-6 py-4 text-xs" @submit.prevent="rename(true)">
                <Input v-model="name" autofocus maxlength="24" class="font-mono" placeholder="Gas" aria-label="New name" />
                <div v-if="details" class="grid grid-cols-2 gap-2">
                    <label class="grid gap-1">
                        <span class="text-muted-foreground">Occupier</span>
                        <Input v-model="occupier" maxlength="255" placeholder="Who lives there" aria-label="Occupier" />
                    </label>
                    <label class="grid gap-1" title="Mark it as someone's home: everyone on the map is pinged (and the /exits channel) when it's connected">
                        <span class="text-muted-foreground">🏠 Home of</span>
                        <Input v-model="homeOf" maxlength="100" placeholder="e.g. The Devil's Rage" aria-label="Home of" />
                    </label>
                </div>
                <p v-if="problem" class="rounded-md bg-amber-500/15 px-3 py-2 text-amber-700 dark:text-amber-300">{{ problem }}</p>
                <div v-if="holder && !problem" class="grid gap-1 rounded-md border border-violet-500/30 bg-violet-500/10 px-3 py-2">
                    <span class="font-medium">{{ trimmed }} is {{ holder.label }}'s. Use it anyway:</span>
                    <label class="flex items-center gap-2" title="They get this one's current name">
                        <input v-model="mode" type="radio" value="swap" /> Swap: {{ holder.label }} becomes {{ currentName || '(no name)' }}
                    </label>
                    <label v-if="holder.next" class="flex items-center gap-2" :title="`${holder.label} moves to the next free number`">
                        <input v-model="mode" type="radio" value="bump" /> Give {{ holder.label }} the next number ({{ holder.next }})
                    </label>
                    <label class="flex items-center gap-2" :class="{ 'opacity-50': !jumped }" :title="jumped ? 'The hole takes the name; the system it leads to keeps its own, and this one floats above it' : 'Only a jumped hole can keep a floating name'">
                        <input v-model="mode" type="radio" value="float" :disabled="!jumped" /> Keep it as a floating name (nobody else changes)
                    </label>
                </div>
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
                    <Button v-if="onlyDetails" @click="rename(false)">Save</Button>
                    <template v-else>
                        <Button v-if="offerQuiet" variant="secondary" :disabled="!canRename" @click="rename(false)">Rename, don't copy</Button>
                        <Button :disabled="!canRename" @click="rename(true)">Rename{{ offerQuiet ? ' and copy' : '' }}</Button>
                    </template>
                </div>
            </DialogFooter>
        </DialogScrollContent>
    </Dialog>
</template>
