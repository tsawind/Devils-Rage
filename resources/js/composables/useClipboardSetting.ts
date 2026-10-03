import { usePage } from '@inertiajs/vue3';

/**
 * Patch 19: the per-person Clipboard switch. On (the default), the mapper
 * copies bookmark names for you when you arm a hole, pick a type, jump or
 * rename. Off, it never writes the clipboard on its own: the name is shown in
 * the message with a Copy button instead. Copy buttons you press yourself
 * always copy.
 */
export function clipboardAllowed(): boolean {
    try {
        const settings = (usePage().props as unknown as { map_user_settings?: { clipboard_enabled?: boolean } }).map_user_settings;
        return settings?.clipboard_enabled !== false;
    } catch {
        return true;
    }
}

/** Write the clipboard on the mapper's own initiative, if the switch allows it. Resolves true when copied. */
export async function autoCopy(text: string | null | undefined): Promise<boolean> {
    if (!text || !clipboardAllowed()) return false;
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        return false;
    }
}

/** A toast button that copies `text` when pressed (used when the mapper didn't copy it itself). */
export function copyButton(text: string): { label: string; onClick: () => void } {
    return { label: '⧉ Copy', onClick: () => void navigator.clipboard.writeText(text).catch(() => undefined) };
}

export function useClipboardSetting() {
    return { clipboardAllowed, autoCopy, copyButton };
}
