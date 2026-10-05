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

async function freshChrome() {
    vi.resetModules();
    return (await import('@/composables/useMapChrome')).useMapChrome;
}

describe('patch 23: folding the top bars and popping out Search + Routing', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('starts unfolded and docked, and shares one state between callers', async () => {
        stubStorage();
        const useMapChrome = await freshChrome();
        const bar = useMapChrome();
        const overlay = useMapChrome();
        expect(bar.barsFolded.value).toBe(false);
        expect(bar.toolsPopped.value).toBe(false);
        bar.setBarsFolded(true);
        expect(overlay.barsFolded.value).toBe(true);
    });

    it('remembers both choices in this browser', async () => {
        const store = stubStorage();
        const useMapChrome = await freshChrome();
        useMapChrome().setBarsFolded(true);
        useMapChrome().setToolsPopped(true);
        expect(store.get('map-bars-folded')).toBe('1');
        expect(store.get('map-tools-popped')).toBe('1');

        const again = (await freshChrome())();
        expect(again.barsFolded.value).toBe(true);
        expect(again.toolsPopped.value).toBe(true);
    });

    it('still works when the browser blocks storage', async () => {
        vi.stubGlobal('window', {
            localStorage: {
                getItem: () => {
                    throw new Error('blocked');
                },
                setItem: () => {
                    throw new Error('blocked');
                },
            },
        });
        const chrome = (await freshChrome())();
        chrome.setBarsFolded(true);
        expect(chrome.barsFolded.value).toBe(true);
    });
});
