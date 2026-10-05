import { ref, type Ref } from 'vue';

/**
 * Patch 24: your alts that are tracked on a map besides your main: their jumps are mapped
 * like your main's (same prompt), but Follow / Center stay on your main. Several at once,
 * remembered per map in this browser.
 */
const trackedByMap: Ref<Record<string, number[]>> = ref({});
const loaded = new Set<string>();

function storageKey(mapSlug: string): string {
    return `map-tracked-alts:${mapSlug}`;
}

function load(mapSlug: string): void {
    if (loaded.has(mapSlug)) return;
    loaded.add(mapSlug);
    try {
        const ids: unknown = JSON.parse(window.localStorage.getItem(storageKey(mapSlug)) ?? '[]');
        trackedByMap.value = { ...trackedByMap.value, [mapSlug]: Array.isArray(ids) ? ids.filter((id): id is number => typeof id === 'number') : [] };
    } catch {
        trackedByMap.value = { ...trackedByMap.value, [mapSlug]: [] };
    }
}

export function useTrackedAlts() {
    function trackedIds(mapSlug: string | null | undefined): ReadonlySet<number> {
        if (!mapSlug) return new Set();
        load(mapSlug);
        return new Set(trackedByMap.value[mapSlug] ?? []);
    }

    function setTracked(mapSlug: string, characterId: number, tracked: boolean): void {
        load(mapSlug);
        const ids = new Set(trackedByMap.value[mapSlug] ?? []);
        if (tracked) ids.add(characterId);
        else ids.delete(characterId);
        trackedByMap.value = { ...trackedByMap.value, [mapSlug]: [...ids] };
        try {
            window.localStorage.setItem(storageKey(mapSlug), JSON.stringify([...ids]));
        } catch {
            // Storage unavailable: it stays tracked for this visit.
        }
    }

    return { trackedIds, setTracked };
}
