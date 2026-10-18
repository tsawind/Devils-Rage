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
