import { useLocalStorage } from '@vueuse/core';
import type { InjectionKey } from 'vue';

/**
 * Patch 42c: the pop-out draws the real map (its own copy: own zoom, centred on the
 * pop-out's character). Clicking a system there shows its signatures in the pop-out
 * instead of selecting it in the main window.
 */
export type TPopoutMap = { pick: (solarsystemId: number) => void };

export const POPOUT_MAP: InjectionKey<TPopoutMap> = Symbol('popout-map');

/** The pop-out map's zoom (its own, so the main map keeps its size). */
export const popoutMapScale = useLocalStorage('popout-map-scale', 0.7);
