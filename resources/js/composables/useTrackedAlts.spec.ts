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

async function freshAlts() {
    vi.resetModules();
    return (await import('@/composables/useTrackedAlts')).useTrackedAlts();
}

describe('patch 24: tracked alts', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('tracks several alts at once, per map, and remembers them in this browser', async () => {
        const store = stubStorage();
        const alts = await freshAlts();
        alts.setTracked('daisy', 11, true);
        alts.setTracked('daisy', 12, true);
        alts.setTracked('public', 13, true);
        expect([...alts.trackedIds('daisy')].sort()).toEqual([11, 12]);
        expect([...alts.trackedIds('public')]).toEqual([13]);
        expect(JSON.parse(store.get('map-tracked-alts:daisy')!)).toEqual([11, 12]);

        const again = await freshAlts();
        expect([...again.trackedIds('daisy')].sort()).toEqual([11, 12]);
    });

    it('untracks one without touching the others', async () => {
        stubStorage({ 'map-tracked-alts:daisy': '[11,12]' });
        const alts = await freshAlts();
        alts.setTracked('daisy', 11, false);
        expect([...alts.trackedIds('daisy')]).toEqual([12]);
    });

    it('nothing is tracked off a map, or when storage is broken', async () => {
        stubStorage({ 'map-tracked-alts:daisy': 'not json' });
        const alts = await freshAlts();
        expect(alts.trackedIds(null).size).toBe(0);
        expect(alts.trackedIds('daisy').size).toBe(0);
    });
});
