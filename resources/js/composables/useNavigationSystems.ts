import { readonly, ref, watch } from 'vue';

/** Patch 25: the route planner's From and To are remembered in this browser until you clear them. */
const STORAGE_KEY = 'route-planner-systems';

function readSaved(): { from: number | null; to: number | null } {
    try {
        const saved: unknown = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? 'null');
        if (saved && typeof saved === 'object') {
            const { from, to } = saved as { from?: unknown; to?: unknown };
            return { from: typeof from === 'number' ? from : null, to: typeof to === 'number' ? to : null };
        }
    } catch {
        // Nothing saved (or no storage): start empty.
    }
    return { from: null, to: null };
}

const saved = readSaved();
const fromSystemId = ref<number | null>(saved.from);
const toSystemId = ref<number | null>(saved.to);

watch([fromSystemId, toSystemId], ([from, to]) => {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ from, to }));
    } catch {
        // Storage unavailable: it still works for this visit.
    }
});

export function useNavigationSystems() {
    function setFromSystem(solarsystemId: number): void {
        fromSystemId.value = solarsystemId;
    }

    function setToSystem(solarsystemId: number): void {
        toSystemId.value = solarsystemId;
    }

    function clearFromSystem(): void {
        fromSystemId.value = null;
    }

    function clearToSystem(): void {
        toSystemId.value = null;
    }

    /** Patch 25: Clear: both empty again (the origin goes back to home). */
    function clearAll(): void {
        fromSystemId.value = null;
        toSystemId.value = null;
    }

    function swapSystems(): void {
        const temp = fromSystemId.value;
        fromSystemId.value = toSystemId.value;
        toSystemId.value = temp;
    }

    return {
        fromSystemId: readonly(fromSystemId),
        toSystemId: readonly(toSystemId),
        setFromSystem,
        setToSystem,
        clearFromSystem,
        clearToSystem,
        clearAll,
        swapSystems,
    };
}
