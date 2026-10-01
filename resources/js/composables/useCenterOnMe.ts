import { ref } from 'vue';

/**
 * Patch 15: "Center" next to Follow: keep the map centered on your system
 * after every jump and whenever your system moves on the map. Remembered per
 * browser, off by default.
 */
const KEY = 'map-center-on-me';

function read(): boolean {
    try {
        return typeof window !== 'undefined' && window.localStorage.getItem(KEY) === '1';
    } catch {
        return false;
    }
}

export const centerOnMe = ref(read());

export function setCenterOnMe(value: boolean): void {
    centerOnMe.value = value;
    try {
        window.localStorage.setItem(KEY, value ? '1' : '0');
    } catch {
        // Storage unavailable: the switch still works for this visit.
    }
}
