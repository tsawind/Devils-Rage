import { ref, watch } from 'vue';

/**
 * Patch 37: the speaker menu's switches (all mapper sounds; kill flashes and markers)
 * and the full-screen background with its see-through slider. Per browser, all on by
 * default except the full-screen background.
 */
const STORAGE_KEY = 'map-effects-v1';

type TMapEffects = {
    sounds: boolean;
    killEffects: boolean;
    fullScreenBackground: boolean;
    /** How solid the side windows are over a full-screen background: 0.3 (quite see-through) … 1 (solid). */
    panelSolidity: number;
};

const DEFAULTS: TMapEffects = { sounds: true, killEffects: true, fullScreenBackground: false, panelSolidity: 0.75 };

function read(): TMapEffects {
    try {
        const raw = typeof window !== 'undefined' ? window.localStorage.getItem(STORAGE_KEY) : null;
        if (!raw) return { ...DEFAULTS };
        const parsed = JSON.parse(raw) as Partial<TMapEffects>;
        return {
            sounds: typeof parsed.sounds === 'boolean' ? parsed.sounds : DEFAULTS.sounds,
            killEffects: typeof parsed.killEffects === 'boolean' ? parsed.killEffects : DEFAULTS.killEffects,
            fullScreenBackground: typeof parsed.fullScreenBackground === 'boolean' ? parsed.fullScreenBackground : DEFAULTS.fullScreenBackground,
            panelSolidity: typeof parsed.panelSolidity === 'number' ? Math.min(Math.max(parsed.panelSolidity, 0.3), 1) : DEFAULTS.panelSolidity,
        };
    } catch {
        return { ...DEFAULTS };
    }
}

const effects = ref<TMapEffects>(read());

watch(
    effects,
    (value) => {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(value));
        } catch {
            // Storage unavailable: it still works for this visit.
        }
    },
    { deep: true },
);

export function soundsEnabled(): boolean {
    return effects.value.sounds;
}

export function useMapEffects() {
    return { effects };
}
