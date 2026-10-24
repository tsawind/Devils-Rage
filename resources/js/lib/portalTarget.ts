import { computed, inject, provide, shallowRef, type ComputedRef, type InjectionKey, type Ref } from 'vue';

/**
 * Patch 42d: where menus, popovers, selects, tooltips and dialogs open. Normally the page's
 * body; inside the scanner pop-out, the pop-out window's body (else they'd open in the
 * main window, behind the pop-out). Things the main window draws (the rename prompt, add a
 * note, …) open in the pop-out too while you're working there.
 */
const PORTAL_TARGET: InjectionKey<Ref<HTMLElement | null>> = Symbol('portal-target');

/** The pop-out's body while you're working in it (the last click was there). */
export const workingInPopOut = shallowRef<HTMLElement | null>(null);

/** Patch 53: the open pop-out's body (set by PopOutWindow), null when none is open. */
export const openPopOutBody = shallowRef<HTMLElement | null>(null);

/**
 * Patch 53: whether this (main) window has the focus. While it doesn't (you're in EVE with the
 * pop-out on top, or this is a hidden pop-out host in the app), questions the mapper asks open
 * in the pop-out, where you can see them.
 */
export const mainWindowFocused = shallowRef(typeof document === 'undefined' ? true : document.hasFocus());
if (typeof window !== 'undefined') {
    window.addEventListener('focus', () => (mainWindowFocused.value = true));
    window.addEventListener('blur', () => (mainWindowFocused.value = document.hasFocus()));
}

export function providePortalTarget(target: Ref<HTMLElement | null>): void {
    provide(PORTAL_TARGET, target);
}

export function usePortalTarget(): ComputedRef<HTMLElement | undefined> {
    const target = inject(PORTAL_TARGET, null);
    return computed(() => {
        if (target?.value) return target.value;
        if (workingInPopOut.value?.isConnected) return workingInPopOut.value;
        // Patch 53: not looking at the main window: the pop-out shows it.
        if (!mainWindowFocused.value && openPopOutBody.value?.isConnected) return openPopOutBody.value;
        return undefined;
    });
}
