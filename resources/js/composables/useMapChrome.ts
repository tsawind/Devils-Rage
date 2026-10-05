import { ref, type Ref } from 'vue';

const BARS_FOLDED_KEY = 'map-bars-folded';
const TOOLS_POPPED_KEY = 'map-tools-popped';

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

/** Patch 23: both top bars folded away (more room for the map); remembered per browser. */
const barsFolded: Ref<boolean> = ref(readFlag(BARS_FOLDED_KEY));
/** Patch 23: Search and Routing popped out of the bar, floating in the map; remembered per browser. */
const toolsPopped: Ref<boolean> = ref(readFlag(TOOLS_POPPED_KEY));

/** Patch 23: the map's floating overlay is mounted (its teleport targets exist). */
const floatReady: Ref<boolean> = ref(false);

export function useMapChrome() {
    function setBarsFolded(value: boolean): void {
        barsFolded.value = value;
        writeFlag(BARS_FOLDED_KEY, value);
    }

    function setToolsPopped(value: boolean): void {
        toolsPopped.value = value;
        writeFlag(TOOLS_POPPED_KEY, value);
    }

    return { barsFolded, toolsPopped, floatReady, setBarsFolded, setToolsPopped };
}
