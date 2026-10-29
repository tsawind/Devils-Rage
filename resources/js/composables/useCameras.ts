import MapCameraController from '@/actions/App/Http/Controllers/MapCameraController';
import { activityByConnection, activityBySignature, addActivation, liveActivations, type TActivation, type TCameraActivityKind, type TPipeActivity } from '@/lib/cameras/activations';
import { computed, ref, shallowRef } from 'vue';

/**
 * Patch 62: the map's cameras and what they saw. One copy per page: the map, the camera
 * wall and the share page read the same feed. Live news comes over the map's channel
 * (ShowMap / the wall call `noteActivity` and `refreshCameras`); a slow poll covers guests
 * and a dropped connection.
 */
export type TCamera = {
    id: number;
    name: string;
    /** Patch 62f: 'watch' = a watch area (not streamed; only reports). */
    kind: 'hole' | 'structure' | 'other' | 'pi' | 'screen' | 'watch';
    /** Patch 62m: standby = on, streams only while someone watches. */
    status: 'starting' | 'live' | 'paused' | 'standby';
    quality: string;
    detect: boolean;
    map_solarsystem_id: number | null;
    map_connection_id: number | null;
    /** Patch 62b: a hole nobody has jumped yet (dotted pipe). */
    signature_id: number | null;
    owner: string | null;
    character: string | null;
    mine: boolean;
    guest_share_link_ids: number[] | null;
    since: string;
};

export type TCameraEvent = {
    id: number;
    map_camera_id: number;
    map_connection_id: number | null;
    signature_id: number | null;
    kind: TCameraActivityKind;
    strength: number;
    /** Patch 62h: seen while the pilot was playing that client. */
    while_playing?: boolean;
    state: 'new' | 'confirmed' | 'dismissed';
    ship_class: string | null;
    side: 'hostile' | 'friendly' | null;
    map_connection_jump_id: number | null;
    clip_url: string | null;
    still_url: string | null;
    happened_at: string;
};

export type TCameraActivityBroadcast = {
    event_id: number;
    camera_id: number;
    camera_name: string;
    map_connection_id: number | null;
    map_solarsystem_id: number | null;
    signature_id: number | null;
    kind: TCameraActivityKind;
    happened_at: string;
    heard_by: 'picture' | 'sound' | 'both';
};

const mapId = ref<number | null>(null);
const mapSlug = ref<string | null>(null);
/** False once the mapper said cameras aren't set up (404): stop asking. */
const enabled = ref(true);
const member = ref(false);
const cameras = shallowRef<TCamera[]>([]);
const events = shallowRef<TCameraEvent[]>([]);
const activations = shallowRef<TActivation[]>([]);
const now = ref(Date.now());

let ticker: ReturnType<typeof setInterval> | null = null;
let poller: ReturnType<typeof setInterval> | null = null;
let pending: ReturnType<typeof setTimeout> | null = null;

/** A one-second clock, only while some pipe has a countdown running. */
function tick(): void {
    now.value = Date.now();
    if (ticker || typeof window === 'undefined') return;
    ticker = setInterval(() => {
        now.value = Date.now();
        if (!liveActivations(activations.value, now.value).length && ticker) {
            clearInterval(ticker);
            ticker = null;
        }
    }, 1000);
}

function fromEvent(event: TCameraEvent): TActivation {
    const camera = cameras.value.find((item) => item.id === event.map_camera_id);
    return {
        eventId: event.id,
        cameraId: event.map_camera_id,
        cameraName: camera?.name ?? 'Camera',
        connectionId: event.map_connection_id,
        signatureId: event.signature_id,
        solarsystemId: camera?.map_solarsystem_id ?? null,
        kind: event.kind,
        at: Date.parse(event.happened_at),
        heardBy: 'picture',
    };
}

export async function refreshCameras(): Promise<void> {
    if (mapSlug.value === null || !enabled.value) return;
    const response = await fetch(MapCameraController.index(mapSlug.value).url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).catch(() => null);
    if (!response) return;
    if (response.status === 404) {
        enabled.value = false;
        return;
    }
    if (!response.ok) return;
    const body = (await response.json()) as { member: boolean; cameras: TCamera[]; events: TCameraEvent[] };
    member.value = body.member;
    cameras.value = body.cameras;
    events.value = body.events;
    // Activations from before this page opened (or missed while offline) still get their minute.
    let list = activations.value;
    for (const event of body.events) list = addActivation(list, fromEvent(event), Date.now());
    activations.value = list;
    if (liveActivations(list, Date.now()).length) tick();
}

/** Several changes in a row (a camera starting, its clip arriving) make one fetch. */
export function refreshCamerasSoon(delayMs = 800): void {
    if (pending) clearTimeout(pending);
    pending = setTimeout(() => {
        pending = null;
        void refreshCameras();
    }, delayMs);
}

/** A camera saw something (from the map's channel). */
export function noteActivity(event: TCameraActivityBroadcast): void {
    activations.value = addActivation(
        activations.value,
        {
            eventId: event.event_id,
            cameraId: event.camera_id,
            cameraName: event.camera_name,
            connectionId: event.map_connection_id,
            signatureId: event.signature_id,
            solarsystemId: event.map_solarsystem_id,
            kind: event.kind,
            at: Math.min(Date.parse(event.happened_at), Date.now()),
            heardBy: event.heard_by,
        },
        Date.now(),
    );
    tick();
    refreshCamerasSoon(1500);
}

/** Starts the feed for a map (safe to call again). */
export function startCameraFeed(id: number, slug: string): void {
    if (mapId.value !== id) {
        mapId.value = id;
        mapSlug.value = slug;
        enabled.value = true;
        cameras.value = [];
        events.value = [];
        activations.value = [];
    }
    void refreshCameras();
    if (!poller && typeof window !== 'undefined') {
        poller = setInterval(() => {
            if (document.visibilityState === 'visible') void refreshCameras();
        }, 45_000);
    }
}

export function stopCameraFeed(): void {
    if (poller) clearInterval(poller);
    poller = null;
}

const pipeActivity = computed<Map<number, TPipeActivity>>(() => activityByConnection(activations.value, now.value));

/** Live cameras pinned to each pipe. */
const pipeCameras = computed(() => {
    const result = new Map<number, TCamera[]>();
    for (const camera of cameras.value) {
        if (camera.kind === 'watch') continue;
        if (camera.map_connection_id === null) continue;
        result.set(camera.map_connection_id, [...(result.get(camera.map_connection_id) ?? []), camera]);
    }
    return result;
});

/** Patch 62b: dotted pipes (holes nobody has jumped yet), by signature. */
const holeActivity = computed<Map<number, TPipeActivity>>(() => activityBySignature(activations.value, now.value));
const holeCameras = computed(() => {
    const result = new Map<number, TCamera[]>();
    for (const camera of cameras.value) {
        if (camera.kind === 'watch') continue;
        if (camera.signature_id === null || camera.map_connection_id !== null) continue;
        result.set(camera.signature_id, [...(result.get(camera.signature_id) ?? []), camera]);
    }
    return result;
});

/** Live cameras pinned to each system on the map (map_solarsystem id). */
const systemCameras = computed(() => {
    const result = new Map<number, TCamera[]>();
    for (const camera of cameras.value) {
        if (camera.kind === 'watch') continue;
        if (camera.map_solarsystem_id === null) continue;
        result.set(camera.map_solarsystem_id, [...(result.get(camera.map_solarsystem_id) ?? []), camera]);
    }
    return result;
});

export function useCameras() {
    return { mapId, mapSlug, enabled, member, cameras, events, activations, pipeActivity, pipeCameras, holeActivity, holeCameras, systemCameras, now };
}
