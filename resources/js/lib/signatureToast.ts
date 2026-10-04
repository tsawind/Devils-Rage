import { shallowRef } from 'vue';

/**
 * Patch 21: signature messages (pasted, updated, static settled, bookmark copied,
 * undo…) get their own column just right of the usual popups, so two arriving
 * together can both be read. Same look and timing as the usual popups; the
 * calls match vue-sonner's (`signatureToast.success(title, { description, action })`).
 */
export type TSignatureToastButton = { label: string; onClick: (event: MouseEvent) => void };
export type TSignatureToastOptions = {
    description?: string;
    action?: TSignatureToastButton;
    cancel?: TSignatureToastButton;
    duration?: number;
};
export type TSignatureToast = TSignatureToastOptions & {
    id: number;
    type: 'success' | 'error' | 'warning' | 'info' | 'default';
    title: string;
};

/** The usual popups' time on screen (vue-sonner's default). */
export const SIGNATURE_TOAST_DURATION = 4000;
/** At most this many shown at once (oldest go first). */
export const SIGNATURE_TOAST_LIMIT = 3;

export const signatureToasts = shallowRef<TSignatureToast[]>([]);

let nextId = 1;
const timers = new Map<number, ReturnType<typeof setTimeout>>();

export function dismissSignatureToast(id: number): void {
    const timer = timers.get(id);
    if (timer) clearTimeout(timer);
    timers.delete(id);
    signatureToasts.value = signatureToasts.value.filter((toast) => toast.id !== id);
}

function show(type: TSignatureToast['type'], title: string, options: TSignatureToastOptions = {}): number {
    const id = nextId++;
    const list = [...signatureToasts.value, { id, type, title, ...options }];
    for (const old of list.slice(0, Math.max(0, list.length - SIGNATURE_TOAST_LIMIT))) {
        const timer = timers.get(old.id);
        if (timer) clearTimeout(timer);
        timers.delete(old.id);
    }
    signatureToasts.value = list.slice(-SIGNATURE_TOAST_LIMIT);
    const duration = options.duration ?? SIGNATURE_TOAST_DURATION;
    if (Number.isFinite(duration)) timers.set(id, setTimeout(() => dismissSignatureToast(id), duration));
    return id;
}

export const signatureToast = Object.assign((title: string, options?: TSignatureToastOptions) => show('default', title, options), {
    success: (title: string, options?: TSignatureToastOptions) => show('success', title, options),
    error: (title: string, options?: TSignatureToastOptions) => show('error', title, options),
    warning: (title: string, options?: TSignatureToastOptions) => show('warning', title, options),
    info: (title: string, options?: TSignatureToastOptions) => show('info', title, options),
    dismiss: (id?: number) => (id === undefined ? signatureToasts.value.forEach((toast) => dismissSignatureToast(toast.id)) : dismissSignatureToast(id)),
});

/** Server popups about signatures ("Signature pasted successfully!") go to the signature column too. */
export function isSignatureMessage(title: string, message?: string | null): boolean {
    return /signature/i.test(`${title} ${message ?? ''}`);
}

/** Like the usual popups, they wait while the pointer is over them. */
export function pauseSignatureToasts(): void {
    for (const timer of timers.values()) clearTimeout(timer);
    timers.clear();
}

export function resumeSignatureToasts(): void {
    for (const toast of signatureToasts.value) {
        const duration = toast.duration ?? SIGNATURE_TOAST_DURATION;
        if (Number.isFinite(duration) && !timers.has(toast.id)) timers.set(toast.id, setTimeout(() => dismissSignatureToast(toast.id), duration));
    }
}
