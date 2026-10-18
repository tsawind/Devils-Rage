import { KILL_SOUND_FAMILIES, type TKillSoundFamily } from '@/lib/distanceSound';
import { setSoundVolume } from '@/lib/rageSound';
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
    /** Patch 37: volume of every mapper sound (0–1). */
    volume: number;
    /** Patch 40: kill sounds by distance from home, and the sound for the main chain and for side chains. */
    distanceSounds: boolean;
    homeKillSound: TKillSoundFamily;
    mainKillSound: TKillSoundFamily;
    sideKillSound: TKillSoundFamily;
    /** Patch 40b: which default set the kill sounds come from (older picks are replaced once). */
    soundScheme: number;
};

const DEFAULTS: TMapEffects = {
    sounds: true,
    killEffects: true,
    fullScreenBackground: false,
    panelSolidity: 0.75,
    volume: 0.7,
    distanceSounds: true,
    homeKillSound: 'sonar',
    mainKillSound: 'pluck',
    sideKillSound: 'klaxon',
    soundScheme: 2,
};
const isFamily = (value: unknown): value is TKillSoundFamily => KILL_SOUND_FAMILIES.some((family) => family.value === value);

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
            volume: typeof parsed.volume === 'number' ? Math.min(Math.max(parsed.volume, 0), 1) : DEFAULTS.volume,
            distanceSounds: typeof parsed.distanceSounds === 'boolean' ? parsed.distanceSounds : DEFAULTS.distanceSounds,
            // Patch 40b: picks saved before the new defaults (Sonar / Pluck / Klaxon) are replaced once.
            homeKillSound: parsed.soundScheme === 2 && isFamily(parsed.homeKillSound) ? parsed.homeKillSound : DEFAULTS.homeKillSound,
            mainKillSound: parsed.soundScheme === 2 && isFamily(parsed.mainKillSound) ? parsed.mainKillSound : DEFAULTS.mainKillSound,
            sideKillSound: parsed.soundScheme === 2 && isFamily(parsed.sideKillSound) ? parsed.sideKillSound : DEFAULTS.sideKillSound,
            soundScheme: 2,
        };
    } catch {
        return { ...DEFAULTS };
    }
}

const effects = ref<TMapEffects>(read());
setSoundVolume(effects.value.volume);
watch(
    () => effects.value.volume,
    (volume) => setSoundVolume(volume),
);

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
