import { ref, type Ref } from 'vue';

const now = ref(Date.now());
let started = false;

/** One shared clock for all map nodes, ticking once a minute (dead ends expire after 4 hours). */
export function useMinuteClock(): Readonly<Ref<number>> {
    if (!started && typeof window !== 'undefined') {
        started = true;
        window.setInterval(() => {
            now.value = Date.now();
        }, 60_000);
    }
    return now;
}
