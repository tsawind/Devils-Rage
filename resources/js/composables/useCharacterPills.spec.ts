import { afterEach, describe, expect, it, vi } from 'vitest';

function stubStorage(initial: Record<string, string> = {}): Map<string, string> {
    const store = new Map(Object.entries(initial));
    vi.stubGlobal('window', {
        localStorage: {
            getItem: (key: string) => store.get(key) ?? null,
            setItem: (key: string, value: string) => void store.set(key, value),
        },
    });
    return store;
}

async function freshPills() {
    vi.resetModules();
    return (await import('@/composables/useCharacterPills')).useCharacterPills();
}

const characters = [
    { id: 1, name: 'LuigiSquirrel' },
    { id: 2, name: 'WickedWrench' },
    { id: 3, name: 'TheWrecker' },
];

describe('patch 25: the Main and Alt pills', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('each pill has its own quick list; empty shows everyone', async () => {
        stubStorage();
        const pills = await freshPills();
        expect(pills.quickList('main', characters).map((c) => c.id)).toEqual([1, 2, 3]);
        pills.setQuick('main', 1, true);
        pills.setQuick('alt', 2, true);
        pills.setQuick('alt', 3, true);
        expect(pills.quickList('main', characters).map((c) => c.id)).toEqual([1]);
        expect(pills.quickList('alt', characters).map((c) => c.id)).toEqual([2, 3]);
        pills.setQuick('alt', 3, false);
        expect(pills.quickList('alt', characters).map((c) => c.id)).toEqual([2]);
    });

    it('the old shared list starts both lists', async () => {
        stubStorage({ 'pill-quick-characters': '[2]' });
        const pills = await freshPills();
        expect(pills.quickList('main', characters).map((c) => c.id)).toEqual([2]);
        expect(pills.quickList('alt', characters).map((c) => c.id)).toEqual([2]);
    });

    it('clipboard and prompts for alts are on by default, and remembered when turned off', async () => {
        const store = stubStorage();
        const pills = await freshPills();
        expect(pills.altClipboard.value).toBe(true);
        expect(pills.altPrompts.value).toBe(true);
        pills.setAltClipboard(false);
        pills.setAltPrompts(false);
        pills.setPillAlt(2);
        expect(store.get('pill-alt-clipboard')).toBe('false');

        const again = await freshPills();
        expect(again.altClipboard.value).toBe(false);
        expect(again.altPrompts.value).toBe(false);
        expect(again.pillAltId.value).toBe(2);
    });

    it('ignores broken saved values', async () => {
        stubStorage({ 'pill-quick-characters': '"oops"', 'pill-alt-id': '{}', 'pill-alt-prompts': '1' });
        const pills = await freshPills();
        expect(pills.quickIds.main.value).toEqual([]);
        expect(pills.pillAltId.value).toBe(null);
        expect(pills.altPrompts.value).toBe(true);
    });
});
