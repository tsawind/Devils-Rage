import { ref } from 'vue';

/**
 * Patch 39: the mapper windows and tabs open in this browser tell each other which map and
 * which main they have (BroadcastChannel), so each character is mapped by exactly one
 * window: a character is mapped by the window where it is the main; tracked alts nobody
 * has as main are mapped by the oldest window on that map. Windows that stop talking for
 * a while count as closed.
 */
export type TWindowInfo = { id: string; startedAt: number; slug: string | null; driver: number | null; seenAt: number };

const CHANNEL = 'dr-map-windows';
const BEAT_MS = 4_000;
const GONE_MS = 11_000;

export const others = ref<Map<string, TWindowInfo>>(new Map());

const self: Omit<TWindowInfo, 'seenAt'> = {
    id: Math.random().toString(36).slice(2),
    startedAt: Date.now(),
    slug: null,
    driver: null,
};

let channel: BroadcastChannel | null = null;
let timer: ReturnType<typeof setInterval> | null = null;

function post(type: 'hello' | 'bye'): void {
    try {
        channel?.postMessage({ type, ...self });
    } catch {
        // Channel closed: nothing to tell.
    }
}

function prune(now: number): void {
    let changed = false;
    const next = new Map(others.value);
    for (const [id, info] of next) {
        if (now - info.seenAt > GONE_MS) {
            next.delete(id);
            changed = true;
        }
    }
    if (changed) others.value = next;
}

/** Start talking (once per page); safe where BroadcastChannel doesn't exist. */
export function joinWindows(): void {
    if (channel || typeof window === 'undefined' || typeof BroadcastChannel === 'undefined') return;
    channel = new BroadcastChannel(CHANNEL);
    channel.onmessage = (event: MessageEvent) => {
        const data = event.data as Partial<TWindowInfo> & { type?: string };
        if (!data?.id || data.id === self.id) return;
        const next = new Map(others.value);
        if (data.type === 'bye') {
            next.delete(data.id);
        } else {
            const known = next.has(data.id);
            next.set(data.id, { id: data.id, startedAt: Number(data.startedAt ?? 0), slug: data.slug ?? null, driver: data.driver ?? null, seenAt: Date.now() });
            // A new window: answer right away so it knows about us before its first jump.
            if (!known) post('hello');
        }
        others.value = next;
    };
    post('hello');
    timer = setInterval(() => {
        post('hello');
        prune(Date.now());
    }, BEAT_MS);
    window.addEventListener('pagehide', () => {
        post('bye');
        if (timer) clearInterval(timer);
    });
}

/** What this window has open (map and main); told to the others right away. */
export function announce(slug: string | null, driver: number | null): void {
    if (self.slug === slug && self.driver === driver) return;
    self.slug = slug;
    self.driver = driver;
    post('hello');
}

/** Another window on this map has this character as its main. */
export function drivenElsewhere(slug: string | null, characterId: number, list: Iterable<TWindowInfo> = others.value.values()): boolean {
    for (const info of list) {
        if (info.slug === slug && info.driver === characterId) return true;
    }
    return false;
}

/** Whether this window maps its main's jumps: not when an older window on this map has the same main. */
export function mapsMainHere(slug: string | null, driver: number | null, list: Iterable<TWindowInfo> = others.value.values(), me = self): boolean {
    if (driver === null) return true;
    for (const info of list) {
        if (info.slug === slug && info.driver === driver && info.startedAt < me.startedAt) return false;
    }
    return true;
}

/** Whether this window maps a tracked alt: nobody has it as main, and no older window is on this map. */
export function mapsAltHere(slug: string | null, altId: number, list: Iterable<TWindowInfo> = others.value.values(), me = self): boolean {
    const all = [...list];
    if (drivenElsewhere(slug, altId, all)) return false;
    return !all.some((info) => info.slug === slug && info.startedAt < me.startedAt);
}

/** The browser window name used for a character's own window or tab (opening it again focuses it). */
export function windowName(characterId: number): string {
    return `dr-map-${characterId}`;
}
