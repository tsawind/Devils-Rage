import { computed, onScopeDispose, ref, watch, type Ref } from 'vue';

/**
 * A countdown for a popup: starts when the popup opens (if `seconds()` gives a
 * number) and calls `onExpire` once when it runs out. It keeps running whatever
 * you click inside the popup, and stops when the popup closes.
 */
export function usePopupCountdown(open: Ref<boolean>, seconds: () => number | null, onExpire: () => void) {
    const deadline = ref<number | null>(null);
    const total = ref(0);
    const now = ref(Date.now());
    let timer: ReturnType<typeof setInterval> | null = null;

    function stopTimer(): void {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    }

    function tick(): void {
        now.value = Date.now();
        if (deadline.value !== null && now.value >= deadline.value) {
            deadline.value = null;
            stopTimer();
            onExpire();
        }
    }

    watch(
        open,
        (isOpen) => {
            stopTimer();
            const limit = isOpen ? seconds() : null;
            if (!limit) {
                deadline.value = null;
                return;
            }
            total.value = limit;
            now.value = Date.now();
            deadline.value = now.value + limit * 1000;
            timer = setInterval(tick, 250);
        },
        { immediate: true },
    );

    onScopeDispose(stopTimer);

    /** Whole seconds left, or null when no countdown is running. */
    const remaining = computed<number | null>(() =>
        deadline.value === null ? null : Math.max(0, Math.ceil((deadline.value - now.value) / 1000)),
    );

    /** 1 → 0 as the countdown runs out, for a shrinking bar. */
    const fraction = computed(() => (deadline.value === null || total.value === 0 ? 0 : Math.max(0, (deadline.value - now.value) / (total.value * 1000))));

    return { remaining, fraction };
}
