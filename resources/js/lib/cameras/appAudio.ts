import { appSignal, desktopApp } from '@/lib/desktopApp';
import { ref } from 'vue';

/**
 * Patch 62d: one EVE client's own sound, from the desktop app (it records each client by itself
 * with Windows' process loopback and listens for the activation sound there). The page asks
 * through app signals and hears back through window events; nothing here records anything.
 */
type TAppWithAudio = { clientAudio?: boolean; cameras?: boolean | number };

export function appHasClientAudio(): boolean {
    return Boolean((desktopApp() as TAppWithAudio | null)?.clientAudio);
}

/** The app opens camera pages in a window of its own when asked (app 0.1.8-dev.55 and later). */
export function appHasCameras(): boolean {
    return Number((desktopApp() as TAppWithAudio | null)?.cameras ?? 0) >= 2;
}

/** Patch 62l: the app closes a camera window on the page's own Close button (app cameras 3+). */
export function appClosesCameraWindow(): boolean {
    return Number((desktopApp() as TAppWithAudio | null)?.cameras ?? 0) >= 3;
}

export function closeAppCameraWindow(): void {
    appSignal('close-window');
}

/** Asks the app for its cameras / camera wall window. */
export function openAppCameraWindow(path: string): void {
    appSignal(`cameras?url=${encodeURIComponent(new URL(path, window.location.origin).href)}`);
}

/** EVE clients open on this PC (character names). */
export const appClients = ref<string[]>([]);
/** Patch 62l: their windows' handles (app cameras 3+), to tell which shared window is which client. */
export const appWindows = ref<{ name: string; handle: number }[]>([]);

/** Loudness per client while listening (0-100). */
export const appLevels = ref<Record<string, number>>({});
/** The latest problem per client ("No EVE window for X is open"). */
export const appAudioErrors = ref<Record<string, string>>({});
/** Clients the app is listening to. */
const listening = new Set<string>();
const heardHandlers = new Set<(character: string) => void>();
const learnedHandlers = new Set<(character: string) => void>();

/** Patch 62h: the client in front and how long since the last mouse / key input (from the app). */
export const appActive = ref<{ character: string | null; idleMs: number; at: number }>({ character: null, idleMs: Number.POSITIVE_INFINITY, at: 0 });
/** You count as playing a client for this long after your last input in it. */
export const PLAYING_MS = 3000;
/** Whether you're playing this client right now. */
export function playingClient(character: string | undefined, now = Date.now()): boolean {
    const active = appActive.value;
    return Boolean(character) && active.character === character && now - active.at < 2000 && active.idleMs < PLAYING_MS;
}

let wired = false;
function wire(): void {
    if (wired || typeof window === 'undefined') return;
    wired = true;
    const detail = <T>(event: Event) => (event as CustomEvent<T>).detail;
    window.addEventListener('devils-rage-app:audio-clients', (event) => {
        const { clients, windows } = detail<{ clients?: string[]; windows?: { name: string; handle: number }[] }>(event) ?? {};
        if (Array.isArray(clients)) appClients.value = clients.map(String);
        if (Array.isArray(windows)) appWindows.value = windows.map((window) => ({ name: String(window.name), handle: Number(window.handle) }));
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
    window.addEventListener('devils-rage-app:active', (event) => {
        const { character, idleMs } = detail<{ character: string | null; idleMs: number }>(event) ?? {};
        appActive.value = { character: character ?? null, idleMs: Number(idleMs ?? Number.POSITIVE_INFINITY), at: Date.now() };
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
