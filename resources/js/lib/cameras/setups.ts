import type { TDetectMode } from '@/lib/cameras/broadcaster';

/**
 * Patch 62l: an EVE client's camera setup, and the one a client gets the first time it's
 * shared: the whole client as the camera, and watch areas on the overview (ships in / out),
 * the hole in the middle of the screen (wobble, with the client's own sound), the probe
 * scanner and local. Drag them onto the right spots once; each client keeps its own.
 */
export type TSetupBox = {
    role: 'camera' | 'watch' | 'hide';
    name: string;
    kind: 'hole' | 'structure' | 'other' | 'pi' | 'screen' | 'watch';
    detect: TDetectMode;
    listen: boolean;
    /** Starts when the client's camera is turned on. */
    auto: boolean;
    /** 1 (the slightest change) … 10 (only a clear one). */
    strictness: number;
    x: number;
    y: number;
    w: number;
    h: number;
};

export const DEFAULT_STRICTNESS = 10;

export function defaultSetup(): TSetupBox[] {
    const watch = { role: 'watch', kind: 'watch', strictness: DEFAULT_STRICTNESS } as const;
    return [
        { role: 'camera', name: 'EVE client', kind: 'screen', detect: 'off', listen: false, auto: true, strictness: DEFAULT_STRICTNESS, x: 0, y: 0, w: 1, h: 1 },
        { ...watch, name: 'Overview', detect: 'rows', listen: false, auto: true, x: 0.78, y: 0.08, w: 0.21, h: 0.55 },
        // Small and dead centre: the hole you sit on, with this client's own sound.
        { ...watch, name: 'Hole', detect: 'flash', listen: true, auto: true, x: 0.47, y: 0.45, w: 0.06, h: 0.1 },
        { ...watch, name: 'Probe scanner', detect: 'scan', listen: false, auto: true, x: 0.3, y: 0.05, w: 0.18, h: 0.18 },
        { ...watch, name: 'Local', detect: 'local', listen: false, auto: false, x: 0.02, y: 0.55, w: 0.14, h: 0.3 },
    ];
}

/**
 * The client a shared window is, from the browser's name for it ("window:<handle>:0") and the
 * desktop app's list of EVE windows; null when it can't tell.
 */
export function clientOfWindowLabel(label: string, windows: readonly { name: string; handle: number }[]): string | null {
    const handle = label.match(/^window:(\d+):/)?.[1];
    if (!handle) return null;
    return windows.find((window) => String(window.handle) === handle)?.name ?? null;
}
