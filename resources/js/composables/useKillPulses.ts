import { shallowRef } from 'vue';

/**
 * Patch 26: a new killmail makes its system on the map flash for a few seconds. Systems
 * (solar system ids) with a kill that just came in, and when; listened for once per map page.
 */
export const KILL_PULSE_MS = 6000;

const pulses = shallowRef<ReadonlyMap<number, number>>(new Map());

export function pulseKill(solarsystemId: number, now: number = Date.now()): void {
    const next = new Map(pulses.value);
    next.set(solarsystemId, now);
    pulses.value = next;
    setTimeout(() => {
        if (pulses.value.get(solarsystemId) !== now) return;
        const rest = new Map(pulses.value);
        rest.delete(solarsystemId);
        pulses.value = rest;
    }, KILL_PULSE_MS);
}

export function useKillPulses() {
    return { pulses, pulseKill };
}
