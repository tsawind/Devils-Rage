import { startBeat } from '@/lib/cameras/broadcaster';
import { desktopApp } from '@/lib/desktopApp';
import { ref, type Ref } from 'vue';

/**
 * Patch 62s: the desktop app takes each EVE client's picture itself (app 0.1.8-dev.58+), so
 * sharing a client needs no window picker: the page asks the app for frames a few times a
 * second and plays them as its own video. Nothing leaves this PC unless a camera streams.
 */
const BASE = 'http://drcap.localhost';

export function appCaptures(): boolean {
    return Boolean((desktopApp() as { capture?: boolean } | null)?.capture);
}

export type TAppWindow = { name: string; minimized: boolean };

/** Every EVE client open on this PC (null when the app can't be asked). */
export async function appEveWindows(): Promise<TAppWindow[] | null> {
    try {
        const response = await fetch(`${BASE}/windows`, { cache: 'no-store' });
        if (!response.ok) return null;
        const list = (await response.json()) as TAppWindow[];
        return Array.isArray(list) ? list.map((item) => ({ name: String(item.name), minimized: Boolean(item.minimized) })) : null;
    } catch {
        return null;
    }
}

export type TFeedStatus = 'starting' | 'ok' | 'minimized' | 'gone' | 'blocked';
export type TAppFeed = { stream: MediaStream; status: Ref<TFeedStatus>; stop: () => void };

/** One client's picture as a video stream, `fps` frames a second, from the app. */
export function appClientFeed(name: string, fps = 6): TAppFeed {
    const canvas = document.createElement('canvas');
    canvas.width = 1280;
    canvas.height = 720;
    const draw = canvas.getContext('2d', { alpha: false }) as CanvasRenderingContext2D;
    draw.fillStyle = '#000';
    draw.fillRect(0, 0, canvas.width, canvas.height);
    const stream = canvas.captureStream(0);
    const track = stream.getVideoTracks()[0] as CanvasCaptureMediaStreamTrack;
    const status = ref<TFeedStatus>('starting');
    let busy = false;
    let last = 0;
    const url = `${BASE}/frame?name=${encodeURIComponent(name)}&w=1920&q=75`;
    const stopBeat = startBeat((now) => {
        if (busy || now - last < 1000 / fps) return;
        busy = true;
        last = now;
        void (async () => {
            try {
                const response = await fetch(url, { cache: 'no-store' });
                if (response.ok) {
                    const bitmap = await createImageBitmap(await response.blob());
                    if (canvas.width !== bitmap.width || canvas.height !== bitmap.height) {
                        canvas.width = bitmap.width;
                        canvas.height = bitmap.height;
                    }
                    draw.drawImage(bitmap, 0, 0);
                    bitmap.close();
                    track.requestFrame();
                    status.value = 'ok';
                } else {
                    status.value = response.status === 404 ? 'gone' : response.status === 403 ? 'blocked' : 'minimized';
                }
            } catch {
                status.value = 'blocked';
            } finally {
                busy = false;
            }
        })();
    });
    return {
        stream,
        status,
        stop: () => {
            stopBeat();
            track.stop();
        },
    };
}
