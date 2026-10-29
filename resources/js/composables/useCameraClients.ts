import MapCameraClientController from '@/actions/App/Http/Controllers/MapCameraClientController';
import { jsonFetch } from '@/lib/jsonFetch';
import { ref } from 'vue';

/**
 * Patch 62k: the EVE clients your share window has up on this map (one per shared window),
 * for the pilot tabs and the hole watcher: turn a client's cameras on / off and pick the hole
 * it watches. One copy per page; the map reloads it when your share window reports a change.
 */
export type TCameraClient = { name: string; on: boolean; pin: string; boxes: number; live: number };
export type TCameraPlace = { id: number; name: string };
export type TCameraPlaces = { systems: TCameraPlace[]; connections: TCameraPlace[]; holes: TCameraPlace[] };

const slug = ref<string | null>(null);
const clients = ref<TCameraClient[]>([]);
const places = ref<TCameraPlaces>({ systems: [], connections: [], holes: [] });
let loading: Promise<void> | null = null;

export async function refreshCameraClients(): Promise<void> {
    if (!slug.value) return;
    loading ??= (async () => {
        const answer = await jsonFetch<{ clients: TCameraClient[]; places: TCameraPlaces }>(MapCameraClientController.index(slug.value!).url);
        if (answer) {
            clients.value = answer.clients;
            places.value = answer.places;
        }
    })().finally(() => (loading = null));
    await loading;
}

/** Ask the share window to change a client: on / off, and / or what it watches. */
export async function controlCameraClient(name: string, change: { on?: boolean; pin?: string }): Promise<boolean> {
    if (!slug.value) return false;
    // Show it at once; the share window's report confirms (or puts it back).
    clients.value = clients.value.map((client) => (client.name === name ? { ...client, ...change } : client));
    const answer = await jsonFetch<{ sent: boolean }>(MapCameraClientController.control(slug.value).url, 'POST', { client: name, ...change });
    if (!answer?.sent) void refreshCameraClients();
    return Boolean(answer?.sent);
}

/** The client of yours called this (your character's name), if your share window has it. */
export function cameraClientFor(name: string | null | undefined): TCameraClient | null {
    return name ? (clients.value.find((client) => client.name === name) ?? null) : null;
}

/** "Daisy → Alpha" for a pin ('c:12', 'h:5', 's:3'). */
export function cameraPlaceName(pin: string): string {
    const [kind, id] = pin.split(':');
    const list = kind === 'h' ? places.value.holes : kind === 'c' ? places.value.connections : kind === 's' ? places.value.systems : [];
    return list.find((place) => place.id === Number(id))?.name ?? '';
}

export function useCameraClients(mapSlug?: string) {
    if (mapSlug && slug.value !== mapSlug) {
        slug.value = mapSlug;
        clients.value = [];
        void refreshCameraClients();
    }
    return { clients, places };
}
