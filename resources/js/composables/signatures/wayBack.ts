import { visibleBookmarkName } from '@/lib/bookmark';
import { inDesktopApp } from '@/lib/desktopApp';
import { anyWindowFocused, focusedClipboard } from '@/lib/popOut';
import { ref } from 'vue';
import { signatureToast as toast } from '@/lib/signatureToast';
import { clipboardAllowed, copyButton } from '@/composables/useClipboardSetting';

/**
 * Patch 14: the way back after a jump. A browser can only write the clipboard
 * while the mapper has focus, and right after a jump EVE has it. So the mapper
 * holds the way back: outside combat a red popup asks for a click (any click
 * in the mapper copies it, or a paste of the return signature copies a better
 * one); in combat mode a small chip in the signatures header holds it, so the
 * scanner never has to touch the mapper.
 */
export type THeldWayBack = {
    name: string;
    label: string;
    at: number;
    /** Patch 37: an alt jumped (the main's system stays in view): who, and the system they're in now. */
    alt?: { characterName: string; solarsystemId: number } | null;
    /**
     * Patch 47: the desktop app already put it on the clipboard. It is still held, so your next
     * signature paste (which overwrites the clipboard in EVE) copies it again; no red popup.
     */
    copied?: boolean;
};

export const heldWayBack = ref<THeldWayBack | null>(null);

/** Patch 47: how long a way back the app copied is re-copied by your next paste. */
const COPIED_KEEP_MS = 15 * 60_000;

async function write(name: string): Promise<boolean> {
    try {
        await focusedClipboard().writeText(name);
        return true;
    } catch {
        return false;
    }
}

/** Copy the way back now if the mapper has focus, else hold it until a click or paste. */
export async function offerWayBack(name: string, label = 'way back', alt: THeldWayBack['alt'] = null): Promise<void> {
    // Patch 19: Clipboard off: the way back is only shown, with a Copy button (nothing is held).
    if (!clipboardAllowed()) {
        heldWayBack.value = null;
        toast.info(`Your ${label}`, { description: visibleBookmarkName(name), action: copyButton(name), duration: 30_000 });
        return;
    }
    // Patch 47: in the desktop app the clipboard can be written while EVE has focus: copy it now
    // and keep holding it, so the scan you copy next (pasted by the app) copies it back.
    if (!alt && inDesktopApp() && (await write(name))) {
        heldWayBack.value = { name, label, at: Date.now(), alt: null, copied: true };
        toast.success('Copied your way back', { description: visibleBookmarkName(name) });
        return;
    }
    // Patch 37: an alt's way back is held until its signatures are pasted (they go into the alt's system).
    if (!alt && typeof document !== 'undefined' && anyWindowFocused() && (await write(name))) {
        heldWayBack.value = null;
        toast.success('Copied your way back', { description: visibleBookmarkName(name) });
        return;
    }
    heldWayBack.value = { name, label, at: Date.now(), alt };
}

/** Copy the held way back (on a click or paste). Returns true when it was copied. */
export async function copyHeldWayBack(): Promise<boolean> {
    const held = heldWayBack.value;
    if (!held) return false;
    // Patch 47: one the app copied already goes stale after a while (no popup shows it's still held).
    if (held.copied && Date.now() - held.at > COPIED_KEEP_MS) {
        heldWayBack.value = null;
        return false;
    }
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
