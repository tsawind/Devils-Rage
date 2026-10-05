import { nextTick } from 'vue';
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

async function freshPlanner() {
    vi.resetModules();
    return (await import('@/composables/useNavigationSystems')).useNavigationSystems();
}

describe('patch 25: the route planner remembers From and To', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('keeps them after a refresh, until Clear', async () => {
        const store = stubStorage();
        const planner = await freshPlanner();
        planner.setFromSystem(30000142);
        planner.setToSystem(30002187);
        await nextTick();
        expect(JSON.parse(store.get('route-planner-systems')!)).toEqual({ from: 30000142, to: 30002187 });

        const again = await freshPlanner();
        expect(again.fromSystemId.value).toBe(30000142);
        expect(again.toSystemId.value).toBe(30002187);

        again.clearAll();
        await nextTick();
        expect(again.fromSystemId.value).toBe(null);
        expect(JSON.parse(store.get('route-planner-systems')!)).toEqual({ from: null, to: null });
    });

    it('starts empty when nothing (or rubbish) is saved', async () => {
        stubStorage({ 'route-planner-systems': '{"from":"Jita"}' });
        const planner = await freshPlanner();
        expect(planner.fromSystemId.value).toBe(null);
        expect(planner.toSystemId.value).toBe(null);
    });
});
