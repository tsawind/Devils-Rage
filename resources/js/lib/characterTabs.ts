import { useStorage } from '@vueuse/core';

/**
 * Patch 41b: the character tabs on the slim bar hold only the characters you add: mostly the
 * ones you scan with in a pop-out, their own window or browser tab, or on another map. Your
 * main and alt stay in the Main / Alt pills. Kept in this browser (every window shares it).
 */
export const characterTabIds = useStorage<number[]>('character-tabs', []);

export function addCharacterTab(characterId: number): void {
    if (!characterTabIds.value.includes(characterId)) characterTabIds.value = [...characterTabIds.value, characterId];
}

export function removeCharacterTab(characterId: number): void {
    characterTabIds.value = characterTabIds.value.filter((id) => id !== characterId);
}

/** Patch 51b: what a dragged character carries (tabs, the Main / Alt pills and their lists). */
export const CHARACTER_DRAG = 'application/x-dr-character';

export function startCharacterDrag(event: DragEvent, characterId: number): void {
    if (!event.dataTransfer) return;
    event.dataTransfer.effectAllowed = 'copyMove';
    event.dataTransfer.setData(CHARACTER_DRAG, String(characterId));
    event.dataTransfer.setData('text/plain', String(characterId));
}

export function draggedCharacter(event: DragEvent): number | null {
    const raw = event.dataTransfer?.getData(CHARACTER_DRAG) || '';
    const id = Number(raw);
    return Number.isFinite(id) && id > 0 ? id : null;
}

export function isCharacterDrag(event: DragEvent): boolean {
    return Boolean(event.dataTransfer?.types?.includes(CHARACTER_DRAG));
}

/** Puts a character's tab before `beforeId` (or last): adds it when it isn't a tab yet. */
export function placeCharacterTab(characterId: number, beforeId: number | null): void {
    const ids = characterTabIds.value.filter((id) => id !== characterId);
    const at = beforeId === null ? -1 : ids.indexOf(beforeId);
    if (at < 0) ids.push(characterId);
    else ids.splice(at, 0, characterId);
    characterTabIds.value = ids;
}

/** Patch 51: a tab dragged onto another one takes its place (the others shift along). */
export function moveCharacterTab(characterId: number, ontoId: number): void {
    const ids = [...characterTabIds.value];
    const from = ids.indexOf(characterId);
    const to = ids.indexOf(ontoId);
    if (from < 0 || to < 0 || from === to) return;
    ids.splice(from, 1);
    ids.splice(to, 0, characterId);
    characterTabIds.value = ids;
}
