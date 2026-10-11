<script lang="ts">
import { ref } from 'vue';

/** Patch 37: the system whose "Add note…" box is open (one box for the whole map). */
export const addNoteTarget = ref<{ id: number; name: string } | null>(null);
</script>

<script setup lang="ts">
import SystemNoteController from '@/actions/App/Http/Controllers/SystemNoteController';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router } from '@inertiajs/vue3';
import { computed, ref as localRef, watch } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Patch 37: right-click a system → Add note…: a small box; paste or type, Enter adds it at
 * the end of the system's notes (Shift+Enter: new line). Stamped on the server with the EVE
 * date and time and your main. Text copied from the game arrives as plain text.
 */
const text = localRef('');
const sending = localRef(false);
const open = computed({
    get: () => addNoteTarget.value !== null,
    set: (value: boolean) => {
        if (!value) addNoteTarget.value = null;
    },
});

watch(addNoteTarget, () => (text.value = ''));

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        add();
    }
}

function onPaste(event: ClipboardEvent): void {
    // Plain text only (game text can carry formatting).
    const plain = event.clipboardData?.getData('text/plain');
    if (plain === undefined) return;
    event.preventDefault();
    const target = event.target as HTMLTextAreaElement;
    const start = target.selectionStart ?? text.value.length;
    const end = target.selectionEnd ?? text.value.length;
    text.value = `${text.value.slice(0, start)}${plain}${text.value.slice(end)}`;
}

function add(): void {
    const target = addNoteTarget.value;
    const value = text.value.trim();
    if (!target || !value || sending.value) return;
    sending.value = true;
    router.post(
        SystemNoteController.store(target.id).url,
        { text: value },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['map', 'selected_map_solarsystem'],
            onSuccess: () => {
                toast.success(`Note added to ${target.name}`);
                addNoteTarget.value = null;
            },
            onError: (errors) => toast.error(Object.values(errors)[0] ?? 'The note could not be added.'),
            onFinish: () => (sending.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Note for {{ addNoteTarget?.name }}</DialogTitle>
                <DialogDescription>Added at the end of its notes with the EVE time and your name. Enter adds, Shift+Enter starts a new line.</DialogDescription>
            </DialogHeader>
            <textarea
                v-model="text"
                rows="4"
                maxlength="4000"
                autofocus
                placeholder="Tengu + 2 Lokis on scan, Hawks pos online"
                class="w-full rounded-md border bg-background p-2 text-sm"
                @keydown="onKeydown"
                @paste="onPaste"
            />
            <DialogFooter>
                <Button variant="outline" @click="open = false">Cancel</Button>
                <Button :disabled="sending || !text.trim()" @click="add">Add</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
