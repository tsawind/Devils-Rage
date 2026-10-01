import { ref, type Ref } from 'vue';

/**
 * Patch 16: one shared clock that ticks every minute (for hole ages on every
 * connection without a timer per connection).
 */
const now = ref(Date.now());
let started = false;

export function useMinuteNow(): Ref<number> {
    if (!started && typeof window !== 'undefined') {
        started = true;
        window.setInterval(() => (now.value = Date.now()), 60_000);
    }
    return now;
}
