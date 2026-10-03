import { ref } from 'vue';

/**
 * Patch 15: "Center" next to Follow: keep the map centered on your system
 * after every jump and whenever your system moves on the map. Remembered per
 * browser. Patch 19: on by default (like every toolbar toggle).
 */
const KEY = 'map-center-on-me';

function read(): boolean {
    try {
        if (typeof window === 'undefined') return true;
        // Anyone's own choice is remembered; a new browser starts on.
        return window.localStorage.getItem(KEY) !== '0';
    } catch {
        return true;
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
