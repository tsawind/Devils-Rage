import { ref, type Ref } from 'vue';

const BARS_FOLDED_KEY = 'map-bars-folded';

function readFlag(key: string): boolean {
    try {
        return typeof window !== 'undefined' && window.localStorage.getItem(key) === '1';
    } catch {
        return false;
    }
}

function writeFlag(key: string, value: boolean): void {
    try {
        window.localStorage.setItem(key, value ? '1' : '0');
    } catch {
        // Storage unavailable: it still works for this visit.
    }
}

/**
 * Patch 23: the site header folded away on the map page (your character floats as a pill
 * over the map, and the map bar's toolbar shrinks to small icons); remembered per browser.
 */
const barsFolded: Ref<boolean> = ref(readFlag(BARS_FOLDED_KEY));

export function useMapChrome() {
    function setBarsFolded(value: boolean): void {
        barsFolded.value = value;
        writeFlag(BARS_FOLDED_KEY, value);
    }

    return { barsFolded, setBarsFolded };
}
