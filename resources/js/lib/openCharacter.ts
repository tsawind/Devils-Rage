import { characterColor } from '@/lib/characterColor';
import { addCharacterTab } from '@/lib/characterTabs';
import { canPopOut, focusedClipboard, openPopOut } from '@/lib/popOut';
import { signatureToast as toast } from '@/lib/signatureToast';
import { urlAs } from '@/lib/windowDriver';
import { windowName } from '@/lib/windowRegistry';

/**
 * Patch 44: the three ways to open one character for scanning, the same from the Alt pill,
 * the Main pill and the character tabs.
 *
 * - Pop out: a small always-on-top scanner over EVE (Chrome / Edge). A browser keeps only
 *   one open at a time: for a second one, use another browser (Edge next to Chrome) with
 *   "Copy link for another browser".
 * - Own window: a separate mapper window with no address bar, for another screen.
 * - Browser tab: a new tab next to this one.
 *
 * Own windows and browser tabs show one character: no Alt / Main pills, no character tabs.
 */
export type TOpenHow = 'popout' | 'window' | 'tab';
export type TOpenable = { id: number; name: string; map_color?: string | null };

export const OPEN_LABELS: Record<TOpenHow, { label: string; hint: string }> = {
    popout: { label: 'Pop out', hint: 'A small scanner that stays on top of EVE (Chrome / Edge)' },
    window: { label: 'Own window', hint: 'A separate mapper window with no address bar, for another screen' },
    tab: { label: 'Browser tab', hint: 'A new tab next to this one in this browser window' },
};

function openWindow(character: TOpenable, how: 'window' | 'tab', popOutThere = false): void {
    const url = new URL(urlAs(window.location.href, character.id, true), window.location.origin);
    if (popOutThere) url.searchParams.set('popout', '1');
    window.open(url.pathname + url.search, windowName(character.id), how === 'window' ? 'popup=yes,width=1400,height=900' : undefined);
}

export async function openCharacter(character: TOpenable, how: TOpenHow): Promise<void> {
    addCharacterTab(character.id);
    if (how !== 'popout') {
        openWindow(character, how);
        return;
    }
    if (!canPopOut()) {
        openWindow(character, 'window');
        return;
    }
    if (!(await openPopOut(character.id, character.name, characterColor(character)).catch(() => false))) openWindow(character, 'window');
}

/** This window was opened to pop its character out (?popout=1): it waits for a click here. */
export function wantsPopOutHere(): boolean {
    try {
        return new URL(window.location.href).searchParams.get('popout') === '1';
    } catch {
        return false;
    }
}

/** Drop ?popout=1 from the address once it's done (a refresh doesn't ask again). */
export function forgetPopOutHere(): void {
    try {
        const url = new URL(window.location.href);
        url.searchParams.delete('popout');
        window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
    } catch {
        // Nothing to drop.
    }
}

/**
 * Patch 45c: a link that opens this character on its own in another browser (Edge next to
 * Chrome: each browser gets its own pop-out). Log in there once; the first click pops it out.
 */
export async function copyLinkForBrowser(character: TOpenable): Promise<void> {
    const url = new URL(urlAs(window.location.href, character.id, true), window.location.origin);
    url.searchParams.set('popout', '1');
    try {
        await focusedClipboard().writeText(url.toString());
        toast.success(`Copied ${character.name}'s link`, { description: 'Paste it into another browser (Edge, Firefox…): click once there and it pops out.' });
    } catch {
        toast.error('Could not copy the link.');
    }
}
