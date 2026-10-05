import { ref, type Ref } from 'vue';

/**
 * Patch 25: the Main and Alt pills' settings, remembered in this browser.
 * - quick list: the characters the pills' left-click lists show (empty = all of them);
 * - the alt shown in the Alt pill;
 * - for your tracked alts' jumps: copy the way-back bookmark (clipboard) and ask which
 *   signature (prompt); both on unless turned off.
 */
const QUICK_KEY = 'pill-quick-characters';
const ALT_KEY = 'pill-alt-id';
const ALT_CLIPBOARD_KEY = 'pill-alt-clipboard';
const ALT_PROMPTS_KEY = 'pill-alt-prompts';

function read<T>(key: string, fallback: T, valid: (value: unknown) => value is T): T {
    try {
        const raw = window.localStorage.getItem(key);
        if (raw === null) return fallback;
        const value: unknown = JSON.parse(raw);
        return valid(value) ? value : fallback;
    } catch {
        return fallback;
    }
}

function write(key: string, value: unknown): void {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage unavailable: it still works for this visit.
    }
}

const isIdList = (value: unknown): value is number[] => Array.isArray(value) && value.every((id) => typeof id === 'number');
const isId = (value: unknown): value is number | null => value === null || typeof value === 'number';
const isFlag = (value: unknown): value is boolean => typeof value === 'boolean';

const quickIds: Ref<number[]> = ref(read(QUICK_KEY, [], isIdList));
const pillAltId: Ref<number | null> = ref(read(ALT_KEY, null, isId));
const altClipboard: Ref<boolean> = ref(read(ALT_CLIPBOARD_KEY, true, isFlag));
const altPrompts: Ref<boolean> = ref(read(ALT_PROMPTS_KEY, true, isFlag));

export function useCharacterPills() {
    /** The characters for a pill's left-click list: your quick list, or everyone when it's empty. */
    function quickList<T extends { id: number }>(characters: readonly T[]): T[] {
        if (quickIds.value.length === 0) return [...characters];
        const quick = new Set(quickIds.value);
        return characters.filter((character) => quick.has(character.id));
    }

    function setQuick(characterId: number, on: boolean): void {
        const ids = new Set(quickIds.value);
        if (on) ids.add(characterId);
        else ids.delete(characterId);
        quickIds.value = [...ids];
        write(QUICK_KEY, quickIds.value);
    }

    function setPillAlt(characterId: number | null): void {
        pillAltId.value = characterId;
        write(ALT_KEY, characterId);
    }

    function setAltClipboard(on: boolean): void {
        altClipboard.value = on;
        write(ALT_CLIPBOARD_KEY, on);
    }

    function setAltPrompts(on: boolean): void {
        altPrompts.value = on;
        write(ALT_PROMPTS_KEY, on);
    }

    return { quickIds, pillAltId, altClipboard, altPrompts, quickList, setQuick, setPillAlt, setAltClipboard, setAltPrompts };
}
