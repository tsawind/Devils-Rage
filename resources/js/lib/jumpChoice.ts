import { appAttention, inDesktopApp } from '@/lib/desktopApp';
import { popOutWindow } from '@/lib/popOut';
import { shallowRef, watch } from 'vue';

/**
 * Patch 47: "which hole did you come through?" where you can see it. Right after a jump EVE has
 * focus and the mapper's jump prompt is behind it. In the desktop app the scanner pop-out sits on
 * top of EVE, so the prompt also shows there as a short list of holes: one click answers it (with
 * the same defaults the full prompt would use) and the app copies the way back. With no pop-out
 * open, the app keeps the mapper window on top of EVE (without taking focus) until it's answered.
 */
export type TJumpChoiceOption = {
    id: number;
    /** e.g. "ABC-123" */
    signature: string;
    /** The hole's name ("A3"), or what it is ("C5 static"). */
    label: string;
    /** Where it leads, when known ("→ C5"). */
    detail: string;
};

export type TJumpChoice = {
    /** Who jumped (null: you) */
    characterName: string | null;
    from: string;
    to: string;
    options: TJumpChoiceOption[];
    /** Answer the prompt: a signature id, or null for "Unknown / not a hole I know". */
    choose: (signatureId: number | null) => void;
    /** Open the full prompt in the mapper window (more options: alias, mass, life). */
    openFull: () => void;
};

export const jumpChoice = shallowRef<TJumpChoice | null>(null);

let attention = false;

function setAttention(on: boolean): void {
    if (on === attention) return;
    attention = on;
    appAttention(on);
}

if (typeof window !== 'undefined') {
    // No pop-out to answer it in and the mapper is behind EVE: ask the app to keep it in view.
    watch([jumpChoice, popOutWindow], ([choice, pop]) => {
        if (!inDesktopApp()) return;
        const popOpen = Boolean(pop && !pop.closed);
        setAttention(Boolean(choice) && !popOpen && !document.hasFocus());
    });
}
