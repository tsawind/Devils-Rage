import { signatureToast as toast } from '@/lib/signatureToast';
import MapUndo from '@/routes/map-undo';
import { router } from '@inertiajs/vue3';
import { computed, shallowRef } from 'vue';

/**
 * Patch 21: one Undo / Redo for anything you do on the map (moving systems,
 * deleting systems, pipes or a whole selection, adding them by hand, renaming,
 * pipe and signature edits, a paste, clearing a chain), in the order you did it,
 * kept in this browser tab (last 30). Deletes and pastes are saved on the server
 * first (a token sent with the request) and put back from there; edits go back
 * through the usual saves. An edit someone else changed after you is skipped
 * with a message rather than overwriting their change.
 */
export type TUndoEntry = {
    label: string;
    /** Put it back; false = could not (nothing is moved to the redo list). */
    undo: () => boolean;
    redo: () => boolean;
};

const LIMIT = 30;
const undoStack = shallowRef<TUndoEntry[]>([]);
const redoStack = shallowRef<TUndoEntry[]>([]);

export function recordUndo(entry: TUndoEntry): void {
    undoStack.value = [...undoStack.value.slice(-(LIMIT - 1)), entry];
    redoStack.value = [];
}

/** A fresh token for the server to save a change under. */
export function undoToken(): string {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    // randomUUID needs a secure (https) page: the same v4 UUID by hand.
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/** Ask the server to put back what it saved under `token`; with `redoToken` it first saves how things are now. */
export function restoreSaved(token: string, redoToken?: string): void {
    router.post(
        MapUndo.store(token).url,
        redoToken ? { redo_token: redoToken } : {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['map', 'map_navigation', 'selected_map_solarsystem'],
            onError: (errors) => {
                toast.error('Could not undo that', { description: errors.undo ?? 'Something went wrong putting it back.' });
                router.reload({ only: ['map'] });
            },
        },
    );
}

/**
 * An entry for a change the server saved before making it (a paste): Undo puts the
 * saved state back and saves the state it replaces, so Redo can put that back too.
 */
export function recordRestorable(label: string, token: string): void {
    let undoWith = token;
    let redoWith: string | null = null;
    recordUndo({
        label,
        undo: () => {
            redoWith = undoToken();
            restoreSaved(undoWith, redoWith);
            return true;
        },
        redo: () => {
            if (!redoWith) return false;
            undoWith = undoToken();
            restoreSaved(redoWith, undoWith);
            return true;
        },
    });
}

export function undoLast(): void {
    const entry = undoStack.value.at(-1);
    if (!entry) return;
    undoStack.value = undoStack.value.slice(0, -1);
    if (entry.undo()) {
        redoStack.value = [...redoStack.value, entry];
        toast.success(`Undone: ${entry.label}`, { action: { label: 'Redo', onClick: () => redoLast() } });
    }
}

export function redoLast(): void {
    const entry = redoStack.value.at(-1);
    if (!entry) return;
    redoStack.value = redoStack.value.slice(0, -1);
    if (entry.redo()) {
        undoStack.value = [...undoStack.value, entry];
        toast.success(`Redone: ${entry.label}`);
    }
}

export const canUndo = computed(() => undoStack.value.length > 0);
export const canRedo = computed(() => redoStack.value.length > 0);
export const undoLabel = computed(() => undoStack.value.at(-1)?.label ?? null);
export const redoLabel = computed(() => redoStack.value.at(-1)?.label ?? null);

/** Ctrl+Z / Ctrl+Y (Ctrl+Shift+Z), except while typing in a box. */
export function handleUndoKeydown(event: KeyboardEvent): void {
    if (!(event.ctrlKey || event.metaKey) || event.altKey) return;
    const target = event.target as HTMLElement | null;
    if (target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return;
    const key = event.key.toLowerCase();
    if (key === 'z' && !event.shiftKey) {
        if (!canUndo.value) return;
        event.preventDefault();
        undoLast();
    } else if (key === 'y' || (key === 'z' && event.shiftKey)) {
        if (!canRedo.value) return;
        event.preventDefault();
        redoLast();
    }
}

/** For tests: start empty. */
export function resetUndo(): void {
    undoStack.value = [];
    redoStack.value = [];
}
