import { shallowRef } from 'vue';

/**
 * Patch 39: the loop popup. After YOUR jump into a system already on the map, through a hole
 * with its own number (A2 into A01), the mapper asks which name the system keeps. Keep is the
 * default (after 60 s in Rage Scanning it answers Keep by itself); the other name floats.
 */
export type TLoopPrompt = {
    /** The system already on the map. */
    systemId: number;
    /** Its main name now ("A01"). */
    main: string;
    /** The jumped hole's number ("A2"). */
    holeName: string;
    /** The jumped hole (in the system you came from). */
    signatureId: number;
    /** The system you came from. */
    originId: number;
};

export const loopPrompt = shallowRef<TLoopPrompt | null>(null);

export function askLoop(prompt: TLoopPrompt): void {
    loopPrompt.value = prompt;
}

export function closeLoop(): void {
    loopPrompt.value = null;
}
