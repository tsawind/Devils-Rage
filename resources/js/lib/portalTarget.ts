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

export function providePortalTarget(target: Ref<HTMLElement | null>): void {
    provide(PORTAL_TARGET, target);
}

export function usePortalTarget(): ComputedRef<HTMLElement | undefined> {
    const target = inject(PORTAL_TARGET, null);
    return computed(() => target?.value ?? (workingInPopOut.value?.isConnected ? workingInPopOut.value : undefined));
}
