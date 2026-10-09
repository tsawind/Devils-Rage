import { soundsEnabled, useMapEffects } from '@/composables/useMapEffects';
import { playKillSound } from '@/lib/rageSound';
import { shallowRef } from 'vue';

/**
 * Patch 26: a new killmail makes its system on the map flash. Patch 37: a burst
 * (2 s), then a gentle pulse until 15 s, then a small "💥 kills" marker on the card
 * for 15 minutes, and a kill sound (heavier for capitals). The speaker menu turns
 * the effects and the sound off.
 */
export const KILL_PULSE_MS = 15_000;
export const KILL_MARKER_MS = 15 * 60_000;

export type TKillNote = { at: number; ship: string | null; capital: boolean; value: number | null };

/** Systems (solar system ids) with a kill that just came in, and when. */
const pulses = shallowRef<ReadonlyMap<number, number>>(new Map());
/** Patch 37: the kills of the last 15 minutes per system (this tab), for the marker. */
const recent = shallowRef<ReadonlyMap<number, readonly TKillNote[]>>(new Map());

export function pulseKill(solarsystemId: number, now: number = Date.now(), note: Partial<TKillNote> = {}, options: { sound?: boolean; force?: boolean } = {}): void {
    const { effects } = useMapEffects();
    const kill: TKillNote = { at: now, ship: note.ship ?? null, capital: Boolean(note.capital), value: note.value ?? null };

    if (options.sound ?? soundsEnabled()) playKillSound(kill.capital);
    if (!effects.value.killEffects && !options.force) return;

    const next = new Map(pulses.value);
    next.set(solarsystemId, now);
    pulses.value = next;
    setTimeout(() => {
        if (pulses.value.get(solarsystemId) !== now) return;
        const rest = new Map(pulses.value);
        rest.delete(solarsystemId);
        pulses.value = rest;
    }, KILL_PULSE_MS);

    const kept = new Map(recent.value);
    kept.set(solarsystemId, [...(kept.get(solarsystemId) ?? []).filter((item) => now - item.at < KILL_MARKER_MS), kill]);
    recent.value = kept;
    setTimeout(() => {
        const left = (recent.value.get(solarsystemId) ?? []).filter((item) => Date.now() - item.at < KILL_MARKER_MS);
        const after = new Map(recent.value);
        if (left.length) after.set(solarsystemId, left);
        else after.delete(solarsystemId);
        recent.value = after;
    }, KILL_MARKER_MS + 1000);
}

export function useKillPulses() {
    return { pulses, recent, pulseKill };
}
