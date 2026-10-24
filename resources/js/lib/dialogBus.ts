import { inDesktopApp } from '@/lib/desktopApp';
import { soloTab } from '@/lib/windowDriver';
import { shallowRef } from 'vue';

/**
 * Patch 54 (step 2): in the desktop app every pop-out is drawn by a hidden mapper page of its own,
 * so a question one page asks (a dialog) can't simply be drawn in another page's window. This bus
 * sends a live copy of every open dialog to the app's other mapper pages: the main window shows
 * the pop-outs' questions, the pop-outs show the main window's. A click or typing in a copy is sent
 * back and done on the real dialog, so it can be answered in either place; both close together.
 *
 * Only in the desktop app (in a browser, two tabs of the same map must not share questions).
 */
export type TDialogRole = 'main' | 'host';
export type TDialogMessage =
    | { kind: 'open' | 'update'; id: string; from: TDialogRole; html: string }
    | { kind: 'close'; id: string }
    | { kind: 'click'; id: string; path: number[] }
    | { kind: 'input'; id: string; path: number[]; value: string }
    | { kind: 'escape'; id: string };

const CHANNEL = 'dr-dialogs';
let channel: BroadcastChannel | null = null;
const listeners = new Set<(message: TDialogMessage) => void>();

/** Copies of other pages' open dialogs, by id. */
export const remoteDialogs = shallowRef<Map<string, { from: TDialogRole; html: string }>>(new Map());

/** This page's part: the main window's page, or a pop-out's hidden host page; null = not taking part. */
export function dialogRole(): TDialogRole | null {
    if (typeof window === 'undefined' || !inDesktopApp()) return null;
    if (!soloTab.value) return 'main';
    try {
        return new URL(window.location.href).searchParams.get('popout') === '1' ? 'host' : null;
    } catch {
        return null;
    }
}

function open(): BroadcastChannel | null {
    if (channel) return channel;
    if (typeof BroadcastChannel === 'undefined' || dialogRole() === null) return null;
    channel = new BroadcastChannel(CHANNEL);
    channel.onmessage = (event: MessageEvent) => receive(event.data as TDialogMessage);
    return channel;
}

export function sendDialog(message: TDialogMessage): void {
    try {
        open()?.postMessage(message);
    } catch {
        // Channel closed: the other windows just don't get a copy.
    }
}

/** Messages about this page's own dialogs (clicks and typing in their copies). */
export function onDialogMessage(listener: (message: TDialogMessage) => void): () => void {
    open();
    listeners.add(listener);
    return () => listeners.delete(listener);
}

/** Whether this page shows copies of dialogs from a page in `from`'s role. */
export function showsCopiesFrom(from: TDialogRole): boolean {
    const role = dialogRole();
    return role !== null && role !== from;
}

function receive(message: TDialogMessage): void {
    if (!message || typeof message !== 'object' || typeof message.id !== 'string') return;
    if (message.kind === 'open' || message.kind === 'update') {
        if (!showsCopiesFrom(message.from)) return;
        const next = new Map(remoteDialogs.value);
        next.set(message.id, { from: message.from, html: message.html });
        remoteDialogs.value = next;
        return;
    }
    if (message.kind === 'close') {
        if (!remoteDialogs.value.has(message.id)) return;
        const next = new Map(remoteDialogs.value);
        next.delete(message.id);
        remoteDialogs.value = next;
        return;
    }
    for (const listener of listeners) listener(message);
}

/** Start listening for other pages' dialogs (once per page). */
export function joinDialogs(): void {
    open();
}

/** The element's place under `root` as child indexes (the same in the copy). */
export function pathOf(root: Element, element: Element): number[] | null {
    const path: number[] = [];
    let node: Element | null = element;
    while (node && node !== root) {
        const parent: Element | null = node.parentElement;
        if (!parent) return null;
        path.unshift(Array.prototype.indexOf.call(parent.children, node));
        node = parent;
    }
    return node === root ? path : null;
}

export function elementAt(root: Element, path: readonly number[]): Element | null {
    let node: Element | null = root;
    for (const index of path) {
        node = node?.children[index] ?? null;
        if (!node) return null;
    }
    return node;
}
