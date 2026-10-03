import { visibleBookmarkName } from '@/lib/bookmark';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { clipboardAllowed, copyButton } from '@/composables/useClipboardSetting';

/**
 * Patch 14: the way back after a jump. A browser can only write the clipboard
 * while the mapper has focus, and right after a jump EVE has it. So the mapper
 * holds the way back: outside combat a red popup asks for a click (any click
 * in the mapper copies it, or a paste of the return signature copies a better
 * one); in combat mode a small chip in the signatures header holds it, so the
 * scanner never has to touch the mapper.
 */
export type THeldWayBack = { name: string; label: string; at: number };

export const heldWayBack = ref<THeldWayBack | null>(null);

async function write(name: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(name);
        return true;
    } catch {
        return false;
    }
}

/** Copy the way back now if the mapper has focus, else hold it until a click or paste. */
export async function offerWayBack(name: string, label = 'way back'): Promise<void> {
    // Patch 19: Clipboard off: the way back is only shown, with a Copy button (nothing is held).
    if (!clipboardAllowed()) {
        heldWayBack.value = null;
        toast.info(`Your ${label}`, { description: visibleBookmarkName(name), action: copyButton(name), duration: 30_000 });
        return;
    }
    if (typeof document !== 'undefined' && document.hasFocus() && (await write(name))) {
        heldWayBack.value = null;
        toast.success('Copied your way back', { description: visibleBookmarkName(name) });
        return;
    }
    heldWayBack.value = { name, label, at: Date.now() };
}

/** Copy the held way back (on a click or paste). Returns true when it was copied. */
export async function copyHeldWayBack(): Promise<boolean> {
    const held = heldWayBack.value;
    if (!held) return false;
    if (!(await write(held.name))) return false;
    if (heldWayBack.value === held) heldWayBack.value = null;
    toast.success('Copied your way back', { description: visibleBookmarkName(held.name) });
    return true;
}

/** The way-back popup's green area asks the signature panel to paste (same as its paste button). */
export const PASTE_SIGNATURES_EVENT = 'wormhole:paste-signatures';

export function requestSignaturePaste(): void {
    window.dispatchEvent(new CustomEvent(PASTE_SIGNATURES_EVENT));
}

/** A better way back was copied (the return signature was pasted and linked): drop the held one. */
export function clearHeldWayBack(): void {
    heldWayBack.value = null;
}
