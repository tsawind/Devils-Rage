import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

/**
 * Patch 48: while the mapper restarts (a deploy, EVE downtime jobs, a server reboot) the proxy
 * answers instead of the mapper: "404 page not found", 502, 503 or 504. Inertia used to show that
 * answer in a big white popup that stayed until a manual reload. Now the page notes it's
 * reconnecting, checks the mapper's health address now and then, and reloads once it answers.
 * Nothing runs while the mapper is fine: the check only starts after such an error.
 */

/** Whether a response came from the proxy in front of the mapper rather than from the mapper. */
export function isServerDownResponse(status: number | null | undefined, body: unknown): boolean {
    if (status === 502 || status === 503 || status === 504) return true;
    // A real "not found" from the mapper is a page of its own; the proxy's is this one line.
    return status === 404 && typeof body === 'string' && body.trim().toLowerCase() === '404 page not found';
}

/** How long to wait before checking again: every 5 s for the first 2 minutes, then every 15 s. */
export function retryDelay(elapsedMs: number): number {
    return elapsedMs < 120_000 ? 5_000 : 15_000;
}

const HEALTH_URL = '/up';
const TOAST_ID = 'server-reconnect';
let reconnecting = false;

async function mapperAnswers(): Promise<boolean> {
    try {
        const response = await fetch(HEALTH_URL, { cache: 'no-store', credentials: 'same-origin' });
        return response.ok;
    } catch {
        return false;
    }
}

/** Starts waiting for the mapper to come back (once), then reloads the page. */
export function waitForServer(): void {
    if (reconnecting || typeof window === 'undefined') return;
    reconnecting = true;
    const started = Date.now();
    toast.loading('Mapper restarting', { id: TOAST_ID, description: 'Reconnecting by itself, the page reloads when it is back.', duration: Infinity });

    const check = async (): Promise<void> => {
        if (await mapperAnswers()) {
            toast.success('Mapper is back', { id: TOAST_ID, description: 'Reloading…', duration: 3_000 });
            // A moment for the rest of the mapper (websockets, workers) to come up too.
            setTimeout(() => window.location.reload(), 2_000);
            return;
        }
        setTimeout(() => void check(), retryDelay(Date.now() - started));
    };
    setTimeout(() => void check(), retryDelay(0));
}

type TExceptionEvent = { detail?: { response?: { status?: number; data?: unknown } }; preventDefault: () => void };

/** Hooks into Inertia: proxy errors and lost connections wait for the mapper instead of a white popup. */
export function initServerReconnect(): void {
    router.on('httpException', (raw) => {
        const event = raw as unknown as TExceptionEvent;
        const response = event.detail?.response;
        if (!isServerDownResponse(response?.status, response?.data)) return;
        event.preventDefault();
        waitForServer();
    });
    router.on('networkError', () => {
        waitForServer();
    });
}
