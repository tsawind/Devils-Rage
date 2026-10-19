import { useLocalStorage } from '@vueuse/core';

/**
 * Patch 45: systems whose kill sounds you muted (right-click → Mute kill sounds), by their
 * place on the map. Per person, in this browser. A system that leaves the map is unmuted.
 */
const muted = useLocalStorage<number[]>('muted-systems', []);

export function isSystemMuted(mapSolarsystemId: number): boolean {
    return muted.value.includes(mapSolarsystemId);
}

export function setSystemMuted(mapSolarsystemId: number, on: boolean): void {
    const rest = muted.value.filter((id) => id !== mapSolarsystemId);
    muted.value = on ? [...rest, mapSolarsystemId] : rest;
}

/** Systems that left the map: back to normal. */
export function forgetMutedSystems(removedIds: readonly number[]): void {
    if (!removedIds.length) return;
    const gone = new Set(removedIds);
    if (muted.value.some((id) => gone.has(id))) muted.value = muted.value.filter((id) => !gone.has(id));
}
