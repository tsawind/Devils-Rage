import { autoCopy, clipboardAllowed, copyButton, onMapperCopy } from '@/composables/useClipboardSetting';
import { visibleBookmarkName } from '@/lib/bookmark';
import { signatureToast as toast } from '@/lib/signatureToast';

/**
 * Patch 39: the re-copy window. Right after the mapper copies a hole's bookmark, a change to
 * that same hole (type, mass, life, size) within 20 s copies the updated name again
 * ("Updated copy: …"), so the bookmark you are about to paste in game is the right one.
 * Copying anything else ends the window. With the Clipboard switch off, the message's
 * ⧉ Copy button holds the latest name instead.
 */
export const RECOPY_WINDOW_MS = 20_000;

type TWindow = { key: string; at: number; name: string; rebuild: () => string };
let current: TWindow | null = null;

// Any other copy the mapper makes ends the window (its own re-copies keep it).
onMapperCopy((text) => {
    if (current && text !== current.name) current = null;
});

/** The mapper just copied (or offered) `name` for the hole `key`; `rebuild` gives its name from the latest data. */
export function noteHoleCopy(key: string, name: string, rebuild: () => string, now: number = Date.now()): void {
    current = { key, at: now, name, rebuild };
}

/** Something else was copied: no re-copy. */
export function endRecopyWindow(): void {
    current = null;
}

/** Whether a change to `key` now would re-copy. */
export function recopyOpen(key: string, now: number = Date.now()): boolean {
    return current !== null && current.key === key && now - current.at <= RECOPY_WINDOW_MS;
}

/** A change to the hole `key` was saved: re-copy its name if it changed within the window. Returns the new name, or null. */
export function recopyAfterChange(key: string, now: number = Date.now()): string | null {
    if (!current || !recopyOpen(key, now)) return null;
    const name = current.rebuild();
    if (!name || name === current.name) return null;
    current = { ...current, name };
    if (clipboardAllowed()) {
        void autoCopy(name).then((copied) =>
            copied
                ? toast.success('Updated copy', { description: visibleBookmarkName(name) })
                : toast.info('Updated name', { description: visibleBookmarkName(name), action: copyButton(name) }),
        );
    } else {
        toast.info('Updated name', { description: visibleBookmarkName(name), action: copyButton(name), duration: 15_000 });
    }
    return name;
}

/** The key of a signature's hole. */
export function holeKey(signatureId: number): string {
    return `sig:${signatureId}`;
}
