import { appSignal, desktopApp } from '@/lib/desktopApp';
import { ref } from 'vue';

/**
 * Patch 62d: one EVE client's own sound, from the desktop app (it records each client by itself
 * with Windows' process loopback and listens for the activation sound there). The page asks
 * through app signals and hears back through window events; nothing here records anything.
 */
type TAppWithAudio = { clientAudio?: boolean; cameras?: boolean };

export function appHasClientAudio(): boolean {
    return Boolean((desktopApp() as TAppWithAudio | null)?.clientAudio);
}

export function appHasCameras(): boolean {
    return Boolean((desktopApp() as TAppWithAudio | null)?.cameras);
}

/** EVE clients open on this PC (character names). */
export const appClients = ref<string[]>([]);
/** Loudness per client while listening (0-100). */
export const appLevels = ref<Record<string, number>>({});
/** The latest problem per client ("No EVE window for X is open"). */
export const appAudioErrors = ref<Record<string, string>>({});
/** Clients the app is listening to. */
const listening = new Set<string>();
const heardHandlers = new Set<(character: string) => void>();
const learnedHandlers = new Set<(character: string) => void>();

let wired = false;
function wire(): void {
    if (wired || typeof window === 'undefined') return;
    wired = true;
    const detail = <T>(event: Event) => (event as CustomEvent<T>).detail;
    window.addEventListener('devils-rage-app:audio-clients', (event) => {
        const clients = detail<{ clients?: string[] }>(event)?.clients;
        if (Array.isArray(clients)) appClients.value = clients.map(String);
    });
    window.addEventListener('devils-rage-app:audio-level', (event) => {
        const { character, level } = detail<{ character: string; level: number }>(event) ?? {};
        if (character) appLevels.value = { ...appLevels.value, [character]: Number(level) || 0 };
    });
    window.addEventListener('devils-rage-app:heard', (event) => {
        const character = detail<{ character: string }>(event)?.character;
        if (character) heardHandlers.forEach((handler) => handler(character));
    });
    window.addEventListener('devils-rage-app:learned', (event) => {
        const character = detail<{ character: string }>(event)?.character;
        if (character) learnedHandlers.forEach((handler) => handler(character));
    });
    window.addEventListener('devils-rage-app:audio-error', (event) => {
        const { character, message } = detail<{ character: string; message: string }>(event) ?? {};
        if (!character) return;
        listening.delete(character);
        appAudioErrors.value = { ...appAudioErrors.value, [character]: String(message ?? 'Problem') };
    });
}

export function refreshAppClients(): void {
    wire();
    appSignal('audio-clients');
}

export function onAppHeard(handler: (character: string) => void): () => void {
    wire();
    heardHandlers.add(handler);
    return () => heardHandlers.delete(handler);
}

export function onAppLearned(handler: (character: string) => void): () => void {
    wire();
    learnedHandlers.add(handler);
    return () => learnedHandlers.delete(handler);
}

/** Listen to exactly these clients (start the new ones, stop the rest). */
export function listenToClients(wanted: Iterable<string>): void {
    wire();
    const want = new Set(wanted);
    for (const character of [...listening]) {
        if (!want.has(character)) {
            listening.delete(character);
            appSignal(`audio-listen?character=${encodeURIComponent(character)}&on=0`);
        }
    }
    for (const character of want) {
        if (listening.has(character)) continue;
        listening.add(character);
        const { [character]: _old, ...rest } = appAudioErrors.value;
        appAudioErrors.value = rest;
        appSignal(`audio-listen?character=${encodeURIComponent(character)}&on=1`);
    }
}

/** The next loud moment in this client's sound is the activation sound. */
export function learnFromClient(character: string): void {
    appSignal(`audio-learn?character=${encodeURIComponent(character)}`);
}
