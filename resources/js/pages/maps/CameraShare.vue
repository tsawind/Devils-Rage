<script setup lang="ts">
import MapCameraController from '@/actions/App/Http/Controllers/MapCameraController';
import MapCameraEventController from '@/actions/App/Http/Controllers/MapCameraEventController';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SeoHead from '@/layouts/SeoHead.vue';
import { BoxCamera, SoundEar, startBeat, type TBoxRect, type TDetectMode, type THit } from '@/lib/cameras/broadcaster';
import { QUALITY, type TQuality, type TRelayPass } from '@/lib/cameras/rtc';
import { appClients, appAudioErrors, appHasClientAudio, appLevels, learnFromClient, listenToClients, onAppHeard, onAppLearned, refreshAppClients } from '@/lib/cameras/appAudio';
import { appSignal, inDesktopApp } from '@/lib/desktopApp';
import { formFetch, jsonFetch } from '@/lib/jsonFetch';
import { Monitor, Play, Pause, Plus, Square, Trash2, Video } from 'lucide-vue-next';
import { computed, markRaw, onBeforeUnmount, reactive, ref, shallowRef, watch } from 'vue';

/**
 * Patch 62: "Share a camera". Pick an EVE window (or the whole screen, which can bring its
 * sound), draw boxes on it (the hole in space, the overview, local, a structure) and each box
 * becomes a camera on the map. The hole watch looks for jumps in a box and tells the map.
 * Keep this tab open in its own window (behind EVE is fine; minimised isn't).
 *
 * The see-through frame you lay over the overview comes with the desktop app; here the box
 * is drawn on the picture.
 */
type TPlace = { id: number; name: string };
const props = defineProps<{
    map: { id: number; slug: string; name: string };
    places: { systems: TPlace[]; connections: TPlace[]; holes?: TPlace[] };
    characters: (TPlace & { online?: boolean })[];
    share_links: { id: number; label: string | null; access: string; expires_at: string | null; permanent: boolean }[];
}>();

type TKind = 'hole' | 'structure' | 'other' | 'pi' | 'screen' | 'watch';
/** Patch 62f: a camera (streamed), a watch area (only watches and reports) or a hide area (blacked out). */
type TRole = 'camera' | 'watch' | 'hide';
type TBox = TBoxRect & {
    role?: TRole;
    /** Watch areas: 1 (only big changes) … 10 (the slightest). */
    sensitivity?: number;
    key: string;
    name: string;
    kind: TKind;
    quality: TQuality;
    /** 'c:<pipe id>', 's:<system id>' or '' */
    pin: string;
    guests: number[];
    detect: TDetectMode;
    listen: boolean;
    /** Patch 62d: in the desktop app, whose client's own sound this box listens to. */
    client?: string;
};
type TRun = {
    camera: BoxCamera;
    id: number;
    state: 'starting' | 'live' | 'paused' | 'reconnecting' | 'failed';
    error: string | null;
    hits: number;
    /** Patch 62e: hits by kind (activation / appeared / vanished). */
    kinds: Record<string, number>;
    lastHit: string | null;
};

const WATCHES: { value: TDetectMode; label: string; hint: string }[] = [
    { value: 'flash', label: 'Hole wobble / flash', hint: 'A small box tight around the wormhole in space: a jump makes it wobble.' },
    { value: 'rows', label: 'Overview: ships in / out', hint: 'A box over the overview list only (not its tabs or header).' },
    { value: 'scan', label: 'Probe scanner: results change', hint: 'A box over the probe scanner’s results list.' },
    { value: 'local', label: 'Local: pilots in / out', hint: 'A box over local’s member list.' },
];
const roleOf = (box: TBox): TRole => box.role ?? 'camera';
const ROLE_CHOICES: { role: TRole; label: string; hint: string }[] = [
    { role: 'camera', label: '📹 Camera', hint: 'Streamed to the map and recorded' },
    { role: 'watch', label: '👁 Watch area', hint: 'Not streamed: watches the hole, overview, probe scanner or local and reports' },
    { role: 'hide', label: '⬛ Hide area', hint: 'Blacked out of every camera, still and clip' },
];
/** Patch 62f: what gets drawn when you drag on empty picture. */
const drawRole = ref<TRole>('camera');

const KINDS: { value: TKind; label: string; detect: TDetectMode }[] = [
    { value: 'hole', label: 'Hole (in space)', detect: 'flash' },
    { value: 'other', label: 'Overview / local', detect: 'rows' },
    { value: 'structure', label: 'Structure', detect: 'off' },
    { value: 'pi', label: 'PI (planet)', detect: 'off' },
    { value: 'screen', label: 'Whole picture', detect: 'off' },
];
const BOXES_KEY = `camera-boxes-${props.map.id}`;

const video = ref<HTMLVideoElement | null>(null);
const source = shallowRef<MediaStream | null>(null);
const sourceKind = ref<'window' | 'screen' | null>(null);
const audioTrack = computed(() => source.value?.getAudioTracks()[0] ?? null);
const characterId = ref<number | null>(props.characters[0]?.id ?? null);
const error = ref<string | null>(null);

function loadBoxes(): TBox[] {
    try {
        const stored = JSON.parse(window.localStorage.getItem(BOXES_KEY) ?? '[]');
        return Array.isArray(stored) ? (stored as TBox[]) : [];
    } catch {
        return [];
    }
}
const boxes = ref<TBox[]>(loadBoxes());
watch(
    boxes,
    (value) => {
        try {
            window.localStorage.setItem(BOXES_KEY, JSON.stringify(value));
        } catch {
            // Remembered for this visit only.
        }
    },
    { deep: true },
);
const selected = ref<string | null>(boxes.value[0]?.key ?? null);
const runs = reactive(new Map<string, TRun>());

// ---- The picture --------------------------------------------------------------------------
async function pick(kind: 'window' | 'screen'): Promise<void> {
    error.value = null;
    try {
        const options = {
            video: { displaySurface: kind === 'window' ? 'window' : 'monitor', frameRate: 30 },
            // A browser can share sound only with the whole screen (not one window's).
            // Chrome offers "Share with system audio" for a window too; it's the whole computer's sound.
            audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false },
            systemAudio: kind === 'screen' ? 'include' : 'exclude',
            selfBrowserSurface: 'exclude',
            surfaceSwitching: 'include',
        } as DisplayMediaStreamOptions;
        const stream = await navigator.mediaDevices.getDisplayMedia(options);
        stopAll();
        source.value?.getTracks().forEach((track) => track.stop());
        source.value = stream;
        sourceKind.value = kind;
        stream.getVideoTracks()[0]?.addEventListener('ended', () => {
            stopAll();
            source.value = null;
        });
        if (video.value) {
            video.value.srcObject = stream;
            await video.value.play().catch(() => undefined);
        }
        if (!boxes.value.length) addBox(true);
        setUpEar();
    } catch (problem) {
        error.value =
            problem instanceof Error && problem.name === 'NotAllowedError'
                ? null
                : inDesktopApp()
                  ? 'The app couldn’t open the window picker here. Use “Open in browser” for now.'
                  : 'Couldn’t start sharing (Chrome or Edge work best).';
    }
}

// ---- Boxes ---------------------------------------------------------------------------------
function addBox(whole = false, role: TRole = 'camera'): void {
    const key = Math.random().toString(36).slice(2, 10);
    const count = boxes.value.filter((box) => roleOf(box) === role).length + 1;
    const names: Record<TRole, string> = { camera: `Camera ${count}`, watch: `Watch ${count}`, hide: `Hidden ${count}` };
    boxes.value.push({
        key,
        role,
        sensitivity: 6,
        name: whole ? (sourceKind.value === 'screen' ? 'Screen' : 'EVE client') : names[role],
        kind: role === 'watch' ? 'watch' : whole ? 'screen' : 'hole',
        quality: '720p5',
        pin: '',
        guests: [],
        // Patch 62f: cameras stream; watching is what watch areas are for.
        detect: role === 'watch' ? 'flash' : 'off',
        listen: false,
        client: characterName(),
        ...(whole ? { x: 0, y: 0, w: 1, h: 1 } : { x: 0.35, y: 0.3, w: 0.3, h: 0.3 }),
    });
    selected.value = key;
}

/** Patch 62f: hide areas, blacked out of every camera, still and clip. */
const hideRects = computed<TBoxRect[]>(() => boxes.value.filter((box) => roleOf(box) === 'hide').map((box) => ({ x: box.x, y: box.y, w: box.w, h: box.h })));
watch(
    hideRects,
    (masks) => {
        for (const run of runs.values()) run.camera.masks = masks;
    },
    { deep: true },
);

function removeBox(box: TBox): void {
    void stopBox(box);
    boxes.value = boxes.value.filter((item) => item.key !== box.key);
}

function setKind(box: TBox, kind: TKind): void {
    box.kind = kind;
    box.detect = KINDS.find((item) => item.value === kind)?.detect ?? 'off';
    if (kind === 'screen') Object.assign(box, { x: 0, y: 0, w: 1, h: 1 });
}

/** Dragging on the picture: new box on empty space, move inside a box, resize from its corner. */
const stage = ref<HTMLElement | null>(null);
let drag: { mode: 'new' | 'move' | 'size'; box: TBox; startX: number; startY: number; rect: TBoxRect } | null = null;

function point(event: PointerEvent): { x: number; y: number } {
    const area = stage.value?.getBoundingClientRect();
    if (!area) return { x: 0, y: 0 };
    return { x: Math.min(1, Math.max(0, (event.clientX - area.left) / area.width)), y: Math.min(1, Math.max(0, (event.clientY - area.top) / area.height)) };
}

function down(event: PointerEvent, box: TBox | null = null, mode: 'move' | 'size' = 'move'): void {
    if (!source.value) return;
    const at = point(event);
    if (!box) {
        addBox(false, drawRole.value);
        box = boxes.value[boxes.value.length - 1];
        Object.assign(box, { x: at.x, y: at.y, w: 0.01, h: 0.01 });
        drag = { mode: 'new', box, startX: at.x, startY: at.y, rect: { x: at.x, y: at.y, w: 0, h: 0 } };
    } else {
        selected.value = box.key;
        drag = { mode, box, startX: at.x, startY: at.y, rect: { x: box.x, y: box.y, w: box.w, h: box.h } };
    }
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
}

function move(event: PointerEvent): void {
    if (!drag) return;
    const at = point(event);
    const { box, rect } = drag;
    if (drag.mode === 'new') {
        Object.assign(box, { x: Math.min(at.x, drag.startX), y: Math.min(at.y, drag.startY), w: Math.abs(at.x - drag.startX), h: Math.abs(at.y - drag.startY) });
    } else if (drag.mode === 'move') {
        box.x = Math.min(1 - rect.w, Math.max(0, rect.x + at.x - drag.startX));
        box.y = Math.min(1 - rect.h, Math.max(0, rect.y + at.y - drag.startY));
    } else {
        box.w = Math.max(0.02, Math.min(1 - rect.x, rect.w + at.x - drag.startX));
        box.h = Math.max(0.02, Math.min(1 - rect.y, rect.h + at.y - drag.startY));
    }
}

function up(): void {
    if (!drag) return;
    const { box } = drag;
    if (drag.mode === 'new' && (box.w < 0.02 || box.h < 0.02)) boxes.value = boxes.value.filter((item) => item.key !== box.key);
    else if (roleOf(box) === 'camera' && box.kind === 'screen' && (box.w < 1 || box.h < 1)) setKind(box, 'other');
    const run = runs.get(box.key);
    if (run) {
        run.camera.rect = { x: box.x, y: box.y, w: box.w, h: box.h };
        run.camera.resize();
    }
    drag = null;
}

// ---- Sound -------------------------------------------------------------------------------
const ear = shallowRef<SoundEar | null>(null);
const learning = ref(false);
const learned = ref(SoundEar.load() !== null);
function setUpEar(): void {
    ear.value?.close();
    ear.value = null;
    if (!source.value || !audioTrack.value) return;
    const listener = new SoundEar(new MediaStream([audioTrack.value]));
    listener.onLearned = () => {
        learning.value = false;
        learned.value = true;
    };
    ear.value = listener;
}
function learn(): void {
    ear.value?.learn();
    learning.value = true;
    setTimeout(() => (learning.value = false), 30_000);
}

// Patch 62d: in the desktop app, each box can listen to one EVE client's own sound (the app
// records that client by itself), so a sound is never credited to the wrong hole.
const clientAudio = appHasClientAudio();
const appLearning = ref<string | null>(null);
function characterName(): string {
    return props.characters.find((character) => character.id === characterId.value)?.name ?? '';
}
if (clientAudio) {
    refreshAppClients();
    const offHeard = onAppHeard((character) => {
        for (const box of boxes.value) {
            if (box.listen && box.client === character) runs.get(box.key)?.camera.hear();
        }
    });
    const offLearned = onAppLearned(() => {
        appLearning.value = null;
        learned.value = true;
    });
    onBeforeUnmount(() => {
        offHeard();
        offLearned();
        listenToClients([]);
    });
}
/** The clients to listen to: every running box with Listen on. */
watch(
    () =>
        [...runs.keys()].flatMap((key) => {
            const box = boxes.value.find((item) => item.key === key);
            return box?.listen && box.client ? [box.client] : [];
        }),
    (wanted) => {
        if (clientAudio) listenToClients(wanted);
    },
);
function learnFromApp(character: string): void {
    learnFromClient(character);
    appLearning.value = character;
    setTimeout(() => {
        if (appLearning.value === character) appLearning.value = null;
    }, 30_000);
}
/** In the app, a window that can't be shared here can still be shared from the browser. */
function openInBrowser(): void {
    appSignal(`open?url=${encodeURIComponent(window.location.href)}`);
}

// ---- Cameras -----------------------------------------------------------------------------
/** Patch 62e: what's burned into the picture: the camera's name and where it's pinned. */
function stampLabel(box: TBox): string {
    const [kind, id] = box.pin.split(':');
    const list = kind === 'h' ? props.places.holes : kind === 'c' ? props.places.connections : kind === 's' ? props.places.systems : [];
    const where = (list ?? []).find((place) => place.id === Number(id))?.name;
    return [box.name, where].filter(Boolean).join(' · ');
}

function pinFields(box: TBox): { map_connection_id: number | null; map_solarsystem_id: number | null; signature_id: number | null } {
    const [kind, id] = box.pin.split(':');
    if (kind === 'h') return { map_connection_id: null, map_solarsystem_id: null, signature_id: Number(id) };
    return { map_connection_id: kind === 'c' ? Number(id) : null, map_solarsystem_id: kind === 's' ? Number(id) : null, signature_id: null };
}

function details(box: TBox) {
    const watching = roleOf(box) === 'watch';
    return {
        name: box.name || (watching ? 'Watch' : 'Camera'),
        kind: watching ? 'watch' : box.kind === 'watch' ? 'hole' : box.kind,
        quality: box.quality,
        detect: watching || box.detect !== 'off' || box.listen,
        guest_share_link_ids: box.guests,
        ...pinFields(box),
    };
}

async function startBox(box: TBox): Promise<void> {
    if (!video.value || !source.value || runs.has(box.key) || roleOf(box) === 'hide') return;
    const started = await jsonFetch<{ camera: { id: number }; publish: TRelayPass }>(MapCameraController.store(props.map.slug).url, 'POST', {
        ...details(box),
        character_id: characterId.value,
    });
    if (!started) {
        error.value = 'The mapper wouldn’t start the camera (is the camera relay set up?).';
        return;
    }
    const camera = new BoxCamera(video.value, { x: box.x, y: box.y, w: box.w, h: box.h }, {
        quality: box.quality,
        detect: box.detect,
        listen: box.listen,
        audio: box.kind === 'screen' ? audioTrack.value : null,
        sensitivity: box.sensitivity ?? 6,
    });
    camera.masks = hideRects.value;
    const run: TRun = { camera: markRaw(camera), id: started.camera.id, state: 'starting', error: null, hits: 0, kinds: {}, lastHit: null };
    camera.label = stampLabel(box);
    runs.set(box.key, run);
    camera.onHit = (hit) => void report(box.key, hit);
    camera.onLost = () => void reconnect(box.key);
    // Patch 62f: a watch area only watches (and keeps the last moments for a clip): nothing is streamed.
    if (roleOf(box) === 'watch') {
        camera.record();
        setState(box.key, 'live');
        return;
    }
    try {
        await camera.start(started.publish);
        setState(box.key, 'live');
    } catch (problem) {
        setState(box.key, 'failed', problem instanceof Error ? problem.message : 'Couldn’t reach the relay');
    }
}

function setState(key: string, state: TRun['state'], message: string | null = null): void {
    const run = runs.get(key);
    if (run) runs.set(key, { ...run, state, error: message });
}

async function reconnect(key: string): Promise<void> {
    const run = runs.get(key);
    if (!run) return;
    setState(key, 'reconnecting');
    for (let attempt = 0; attempt < 20 && runs.has(key); attempt++) {
        const answer = await jsonFetch<{ publish: TRelayPass | null }>(MapCameraController.update(run.id).url, 'PATCH', { reconnect: true, status: 'live' });
        if (answer?.publish) {
            try {
                await run.camera.start(answer.publish);
                setState(key, 'live');
                return;
            } catch {
                // Try again shortly.
            }
        }
        await new Promise((resolve) => setTimeout(resolve, Math.min(30_000, 2000 * (attempt + 1))));
    }
    setState(key, 'failed', 'Lost the relay');
}

async function stopBox(box: TBox): Promise<void> {
    const run = runs.get(box.key);
    if (!run) return;
    runs.delete(box.key);
    run.camera.stop();
    await jsonFetch(MapCameraController.destroy(run.id).url, 'DELETE');
}

function stopAll(): void {
    for (const box of boxes.value) void stopBox(box);
}

function togglePause(box: TBox): void {
    const run = runs.get(box.key);
    if (!run) return;
    run.camera.paused = !run.camera.paused;
    setState(box.key, run.camera.paused ? 'paused' : 'live');
    void jsonFetch(MapCameraController.update(run.id).url, 'PATCH', { status: run.camera.paused ? 'paused' : 'live' });
}

/** Changes to a running box go to the mapper (name, pin, guests) and the camera (box, quality, watch). */
let saveTimer: ReturnType<typeof setTimeout> | null = null;
watch(
    boxes,
    () => {
        for (const box of boxes.value) {
            const run = runs.get(box.key);
            if (!run) continue;
            Object.assign(run.camera, { detect: box.detect, listen: box.listen, label: stampLabel(box) });
            run.camera.setSensitivity(box.sensitivity ?? 6);
            if (run.camera.quality !== box.quality) {
                run.camera.quality = box.quality;
                run.camera.resize();
            }
        }
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = setTimeout(() => {
            for (const box of boxes.value) {
                const run = runs.get(box.key);
                if (run) void jsonFetch(MapCameraController.update(run.id).url, 'PATCH', details(box));
            }
        }, 1500);
    },
    { deep: true },
);

/** A jump: the map hears now; the clip and still follow a few seconds later. */
async function report(key: string, hit: THit): Promise<void> {
    const run = runs.get(key);
    if (!run) return;
    runs.set(key, { ...run, hits: run.hits + 1, kinds: { ...run.kinds, [hit.kind]: (run.kinds[hit.kind] ?? 0) + 1 }, lastHit: `${hit.kind} · ${hit.heardBy} · ${hit.at.toLocaleTimeString()}` });
    const created = await jsonFetch<{ id: number }>(MapCameraEventController.store(run.id).url, 'POST', {
        kind: hit.kind,
        strength: hit.strength,
        heard_by: hit.heardBy,
        happened_at: hit.at.toISOString(),
    });
    if (!created) return;
    // Patch 62e: clips only for activations (ships in / out get the still), so a busy
    // overview overnight can't fill the server.
    const clip = hit.kind === 'activation' || hit.kind === 'local_in' ? await run.camera.clip() : null;
    const files = new FormData();
    if (clip) files.append('clip', clip, 'clip.webm');
    if (hit.still) files.append('still', hit.still, 'still.jpg');
    if (clip || hit.still) await formFetch(MapCameraEventController.attach(created.id).url, files);
}

// ---- The beat ----------------------------------------------------------------------------
const levels = ref<Record<string, number>>({});
let lastLevels = 0;
let lastHeartbeat = 0;
const stopBeat = startBeat((now) => {
    const heard = ear.value?.listen(now) ?? false;
    const listening = [...runs.values()].filter((run) => run.camera.listen).length;
    for (const run of runs.values()) run.camera.beat(now, heard, listening <= 1);
    if (now - lastLevels > 250) {
        lastLevels = now;
        levels.value = Object.fromEntries([...runs.entries()].map(([key, run]) => [key, run.camera.level]));
    }
    if (now - lastHeartbeat > 30_000) {
        lastHeartbeat = now;
        for (const run of runs.values()) {
            if (run.state === 'live' || run.state === 'paused') void jsonFetch(MapCameraController.update(run.id).url, 'PATCH', { status: run.state });
        }
    }
});

onBeforeUnmount(() => {
    stopBeat();
    stopAll();
    ear.value?.close();
    source.value?.getTracks().forEach((track) => track.stop());
});

const selectedBox = computed(() => boxes.value.find((box) => box.key === selected.value) ?? null);
const liveCount = computed(() => [...runs.values()].filter((run) => run.state === 'live').length);

function boxStyle(box: TBox): Record<string, string> {
    return { left: `${box.x * 100}%`, top: `${box.y * 100}%`, width: `${box.w * 100}%`, height: `${box.h * 100}%` };
}

const STATE_LABEL: Record<TRun['state'], string> = { starting: 'Starting…', live: 'Live', paused: 'Paused', reconnecting: 'Reconnecting…', failed: 'Stopped' };
/** Patch 62f: what a box's hits were, in words. */
const HIT_LABEL: Record<string, string> = { activation: '⚡ activations', appeared: '＋ ships in', vanished: '－ ships out', scan_new: '＋ new results', scan_gone: '－ results gone', local_in: '＋ in local', local_out: '－ left local' };
function hitSummary(run: TRun): string {
    return Object.entries(run.kinds)
        .map(([kind, count]) => `${count} ${HIT_LABEL[kind] ?? kind}`)
        .join(' · ');
}
const ROLE_STYLE: Record<TRole, { idle: string; selected: string; tag: string }> = {
    camera: { idle: 'border-2 border-purple-500/80', selected: 'border-2 border-fuchsia-400 bg-fuchsia-500/10', tag: 'bg-purple-900/90 text-purple-100' },
    watch: { idle: 'border-2 border-dashed border-sky-400/80', selected: 'border-2 border-dashed border-sky-300 bg-sky-500/10', tag: 'bg-sky-900/90 text-sky-100' },
    hide: { idle: 'border border-neutral-500 bg-black', selected: 'border-2 border-neutral-300 bg-black', tag: 'bg-neutral-800 text-neutral-200' },
};
</script>

<template>
    <AppLayout>
        <SeoHead :title="`Share a camera · ${map.name}`" description="Stream part of an EVE window to the map" />
        <div class="mx-auto flex w-full max-w-[1500px] flex-col gap-3 p-3 lg:flex-row">
            <section class="flex min-w-0 flex-1 flex-col gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="mr-2 text-lg font-semibold">Share a camera <span class="text-sm font-normal text-muted-foreground">· {{ map.name }}</span></h1>
                    <Button size="sm" variant="outline" @click="pick('window')"><Video class="mr-1 size-4" /> An EVE window</Button>
                    <Button size="sm" variant="outline" @click="pick('screen')"><Monitor class="mr-1 size-4" /> Whole screen</Button>
                    <select v-if="characters.length" v-model.number="characterId" class="h-8 rounded-md border border-border bg-background px-2 text-xs" title="Who is this client">
                        <option v-for="character in characters" :key="character.id" :value="character.id">{{ character.online ? '● ' : '' }}{{ character.name }}{{ character.online ? ' (online)' : '' }}</option>
                    </select>
                    <span v-if="liveCount" class="ml-auto flex items-center gap-1 text-xs text-red-400"><span class="size-2 animate-pulse rounded-full bg-red-500" /> {{ liveCount }} live</span>
                </div>
                <p v-if="error" class="rounded border border-red-800 bg-red-950/40 px-2 py-1 text-xs text-red-200">
                    {{ error }}
                    <button v-if="inDesktopApp()" type="button" class="ml-2 underline" @click="openInBrowser">Open in browser</button>
                </p>

                <div v-show="source" ref="stage" class="relative w-full touch-none overflow-hidden rounded-md border border-border bg-black select-none" @pointerdown.self="(event) => down(event)" @pointermove="move" @pointerup="up">
                    <video ref="video" class="pointer-events-none block h-auto w-full" autoplay playsinline muted />
                    <div
                        v-for="box in boxes"
                        :key="box.key"
                        class="absolute cursor-move"
                        :class="[selected === box.key ? ROLE_STYLE[roleOf(box)].selected : ROLE_STYLE[roleOf(box)].idle, runs.get(box.key)?.state === 'live' && roleOf(box) === 'camera' ? 'shadow-[0_0_0_1px_var(--color-red-500)]' : '']"
                        :style="boxStyle(box)"
                        @pointerdown.stop="(event) => down(event, box)"
                    >
                        <span class="absolute -top-5 left-0 rounded px-1 text-[10px] whitespace-nowrap" :class="ROLE_STYLE[roleOf(box)].tag">
                            <span v-if="runs.get(box.key)?.state === 'live'" class="mr-1 inline-block size-1.5 rounded-full" :class="roleOf(box) === 'watch' ? 'bg-sky-400' : 'bg-red-500'" />{{ roleOf(box) === 'watch' ? '👁 ' : roleOf(box) === 'hide' ? '⬛ ' : '📹 ' }}{{ box.name }}
                        </span>
                        <span class="absolute right-0 bottom-0 size-3 cursor-se-resize bg-fuchsia-400" @pointerdown.stop="(event) => down(event, box, 'size')" />
                    </div>
                </div>
                <div v-if="!source" class="flex flex-col gap-2 rounded-md border border-dashed border-border p-6 text-sm text-muted-foreground">
                    <p class="font-medium text-foreground">Pick what to share, then draw boxes on it.</p>
                    <p>· <b>An EVE window</b>: one client. Then draw boxes on it:</p>
                    <p class="pl-3">📹 <b>Camera</b>: streamed to the map and recorded (the hole, a structure, the whole client).</p>
                    <p class="pl-3">👁 <b>Watch area</b>: not streamed, it only watches and reports: a small box tight on the hole (wobble), one on the overview (ships in / out), on the probe scanner (results change) or on local (pilots in / out).</p>
                    <p class="pl-3">⬛ <b>Hide area</b>: blacked out of every camera, still and clip.</p>
                    <p>· <b>Sound</b>: tick “Share with system audio” in Chrome's picker. It's the whole computer's sound, every client mixed: mute the other EVE clients in Windows' Volume mixer (each client is listed there) so only the watching client is heard. With several boxes listening, the sound only backs up a jump a box's picture saw.</p>
                    <p>· Keep this tab open in its own window. Behind EVE is fine; minimised isn't. Nothing is sent to the game.</p>
                    <p v-if="boxes.length" class="text-xs">Your {{ boxes.length }} box{{ boxes.length === 1 ? '' : 'es' }} from last time come back when you share.</p>
                </div>
            </section>

            <aside class="flex w-full shrink-0 flex-col gap-2 lg:w-96">
                <div class="flex items-center gap-2">
                    <h2 class="text-sm font-semibold">Boxes</h2>
                    <Button size="sm" variant="ghost" class="ml-auto h-7" :disabled="!source" @click="addBox(true)">Whole picture</Button>
                </div>
                <div class="flex flex-col gap-1 rounded-md border border-border p-2 text-xs">
                    <span class="text-muted-foreground">Drag on the picture to draw a…</span>
                    <div class="flex overflow-hidden rounded border border-border">
                        <button
                            v-for="choice in ROLE_CHOICES"
                            :key="choice.role"
                            type="button"
                            class="flex-1 px-2 py-1"
                            :class="drawRole === choice.role ? 'bg-muted font-semibold' : 'text-muted-foreground'"
                            :title="choice.hint"
                            @click="drawRole = choice.role"
                        >
                            {{ choice.label }}
                        </button>
                    </div>
                    <Button size="sm" variant="ghost" class="h-6 self-start px-1" :disabled="!source" @click="addBox(false, drawRole)"><Plus class="mr-1 size-3.5" /> Add one in the middle</Button>
                </div>
                <div
                    v-for="box in boxes"
                    :key="box.key"
                    class="flex flex-col gap-2 rounded-md border p-2 text-xs"
                    :class="selected === box.key ? 'border-fuchsia-600 bg-fuchsia-950/20' : 'border-border'"
                    @click="selected = box.key"
                >
                    <div class="flex items-center gap-2">
                        <input v-model="box.name" class="h-7 min-w-0 flex-1 rounded border border-border bg-background px-2 text-xs" maxlength="80" placeholder="Name on the map" />
                        <span class="shrink-0 text-[11px]" :class="runs.get(box.key)?.state === 'live' ? 'text-red-400' : 'text-muted-foreground'">{{ runs.get(box.key) ? STATE_LABEL[runs.get(box.key)!.state] : 'Off' }}</span>
                        <template v-if="runs.get(box.key)">
                            <button type="button" class="rounded p-1 hover:bg-muted" :title="runs.get(box.key)!.state === 'paused' ? 'Resume' : 'Pause'" @click.stop="togglePause(box)">
                                <Play v-if="runs.get(box.key)!.state === 'paused'" class="size-3.5" /><Pause v-else class="size-3.5" />
                            </button>
                            <button type="button" class="rounded p-1 text-red-400 hover:bg-muted" title="Stop" @click.stop="stopBox(box)"><Square class="size-3.5" /></button>
                        </template>
                        <span v-else-if="roleOf(box) === 'hide'" class="text-[11px] text-muted-foreground">hidden from all</span>
                        <button v-else-if="roleOf(box) === 'watch'" type="button" class="rounded bg-sky-700 px-2 py-1 font-semibold text-white hover:bg-sky-600 disabled:opacity-40" :disabled="!source" @click.stop="startBox(box)">Watch</button>
                        <button v-else type="button" class="rounded bg-red-700 px-2 py-1 font-semibold text-white hover:bg-red-600 disabled:opacity-40" :disabled="!source" @click.stop="startBox(box)">Go live</button>
                        <button type="button" class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-red-400" title="Remove the box" @click.stop="removeBox(box)"><Trash2 class="size-3.5" /></button>
                    </div>
                    <p v-if="runs.get(box.key)?.error" class="text-red-300">{{ runs.get(box.key)!.error }}</p>
                    <p v-if="selected === box.key && roleOf(box) === 'hide'" class="text-muted-foreground">Blacked out of every camera, still and clip (drag it over what nobody should see).</p>
                    <div v-if="selected === box.key && roleOf(box) !== 'hide'" class="grid grid-cols-[5.5rem_1fr] items-center gap-x-2 gap-y-1.5">
                        <template v-if="roleOf(box) === 'watch'">
                            <label>Watch for</label>
                            <select v-model="box.detect" class="h-7 rounded border border-border bg-background px-1">
                                <option v-for="item in WATCHES" :key="item.value" :value="item.value">{{ item.label }}</option>
                            </select>
                            <span />
                            <p class="text-[11px] text-muted-foreground">{{ WATCHES.find((item) => item.value === box.detect)?.hint }}</p>
                            <label>Sensitivity</label>
                            <div class="flex items-center gap-2">
                                <input v-model.number="box.sensitivity" type="range" min="1" max="10" step="1" class="flex-1" title="1: only big changes · 10: the slightest" />
                                <span class="w-5 text-right">{{ box.sensitivity ?? 6 }}</span>
                            </div>
                        </template>
                        <template v-else>
                            <label>What it shows</label>
                            <select :value="box.kind" class="h-7 rounded border border-border bg-background px-1" @change="setKind(box, ($event.target as HTMLSelectElement).value as TKind)">
                                <option v-for="kind in KINDS" :key="kind.value" :value="kind.value">{{ kind.label }}</option>
                            </select>
                        </template>
                        <label>On the map</label>
                        <select v-model="box.pin" class="h-7 rounded border border-border bg-background px-1">
                            <option value="">Not pinned</option>
                            <optgroup v-if="places.holes?.length" label="Holes not jumped yet (dotted)">
                                <option v-for="hole in places.holes" :key="hole.id" :value="`h:${hole.id}`">{{ hole.name }}</option>
                            </optgroup>
                            <optgroup label="Holes (pipes)">
                                <option v-for="pipe in places.connections" :key="pipe.id" :value="`c:${pipe.id}`">{{ pipe.name }}</option>
                            </optgroup>
                            <optgroup label="Systems">
                                <option v-for="system in places.systems" :key="system.id" :value="`s:${system.id}`">{{ system.name }}</option>
                            </optgroup>
                        </select>
                        <template v-if="roleOf(box) === 'camera'">
                            <label>Quality</label>
                            <select v-model="box.quality" class="h-7 rounded border border-border bg-background px-1">
                                <option v-for="(quality, key) in QUALITY" :key="key" :value="key">{{ quality.label }}</option>
                            </select>
                            <label>Also watch</label>
                            <select v-model="box.detect" class="h-7 rounded border border-border bg-background px-1" title="Watch areas do this better: draw a 👁 box over just the part to watch">
                                <option value="off">Off (use watch areas)</option>
                                <option v-for="item in WATCHES" :key="item.value" :value="item.value">{{ item.label }}</option>
                            </select>
                        </template>
                        <label>Listen</label>
                        <div v-if="clientAudio" class="flex items-center gap-2">
                            <input v-model="box.listen" type="checkbox" :disabled="!box.client" />
                            <select v-model="box.client" class="h-7 min-w-0 flex-1 rounded border border-border bg-background px-1" title="This client's own sound only (the app records it by itself)" @focus="refreshAppClients()">
                                <option value="">Pick a client…</option>
                                <option v-for="client in appClients" :key="client" :value="client">{{ client }}'s client</option>
                                <option v-if="box.client && !appClients.includes(box.client)" :value="box.client">{{ box.client }} (not open)</option>
                            </select>
                        </div>
                        <label v-else class="flex items-center gap-2" :class="audioTrack ? '' : 'opacity-50'">
                            <input v-model="box.listen" type="checkbox" :disabled="!audioTrack" />
                            <span>{{ audioTrack ? 'Activation sound counts for this box' : 'Share again with “Share with system audio” on' }}</span>
                        </label>
                        <template v-if="clientAudio && box.listen && box.client">
                            <span />
                            <div class="flex items-center gap-2">
                                <span class="h-1.5 flex-1 overflow-hidden rounded bg-muted" :title="`${box.client}'s client sound`"><span class="block h-full bg-sky-500 transition-all" :style="{ width: `${appLevels[box.client ?? ''] ?? 0}%` }" /></span>
                                <button type="button" class="rounded border border-border px-1.5 text-[11px] hover:bg-muted disabled:opacity-50" :disabled="appLearning !== null || !runs.get(box.key)" :title="runs.get(box.key) ? 'The next loud moment in this client is the activation sound' : 'Go live first'" @click="learnFromApp(box.client ?? '')">
                                    {{ appLearning === box.client ? 'Listening… (30 s)' : 'Learn' }}
                                </button>
                            </div>
                            <span v-if="appAudioErrors[box.client ?? '']" />
                            <p v-if="appAudioErrors[box.client ?? '']" class="text-red-300">{{ appAudioErrors[box.client ?? ''] }}</p>
                        </template>
                        <template v-if="share_links.length && roleOf(box) === 'camera'">
                            <label class="self-start">Guests</label>
                            <div class="flex flex-col gap-0.5">
                                <label v-for="link in share_links" :key="link.id" class="flex items-center gap-2">
                                    <input v-model="box.guests" type="checkbox" :value="link.id" />
                                    <span class="truncate">{{ link.label || `Link #${link.id}` }} <span class="text-muted-foreground">{{ link.permanent ? '· permanent' : '' }}</span></span>
                                </label>
                            </div>
                        </template>
                    </div>
                    <div v-if="runs.get(box.key) && box.detect === 'flash'" class="flex items-center gap-2" title="How much it's changing right now; a jump should stand far above the usual">
                        <span class="text-muted-foreground">Movement</span>
                        <span class="h-1.5 flex-1 overflow-hidden rounded bg-muted"><span class="block h-full bg-fuchsia-500 transition-all" :style="{ width: `${levels[box.key] ?? 0}%` }" /></span>
                    </div>
                    <p v-if="runs.get(box.key)?.hits" class="text-purple-300">
                        {{ hitSummary(runs.get(box.key)!) }} · last {{ runs.get(box.key)!.lastHit }}
                    </p>
                </div>
                <div v-if="clientAudio" class="flex flex-col gap-1 rounded-md border border-border p-2 text-xs">
                    <p class="font-semibold">Activation sound (app)</p>
                    <p class="text-muted-foreground">
                        Each box listens to its own client only, so Discord and the other clients can't set it off. {{ learned ? 'Learned.' : 'Teach it once: Go live, press Learn on a listening box, then let a ship jump (or play the sound) within 30 s.' }}
                    </p>
                </div>
                <div v-else-if="audioTrack" class="flex flex-col gap-1 rounded-md border border-border p-2 text-xs">
                    <p class="font-semibold">Activation sound</p>
                    <p class="text-muted-foreground">
                        {{ learned ? 'Learned. Teach it again if it misses jumps or hears other things.' : 'Not learned yet: press Learn, then let a ship jump (or play the sound) within 30 s.' }}
                        Discord and other clients are in the same sound: mute what you can in Windows' Volume mixer (each EVE client has its own slider there).
                    </p>
                    <Button size="sm" variant="outline" class="h-7 self-start" :disabled="learning" @click="learn">{{ learning ? 'Listening… (30 s)' : 'Learn' }}</Button>
                </div>
                <p v-if="selectedBox" class="text-[11px] text-muted-foreground">Drag on the picture to draw a box, drag a box to move it, its pink corner to size it.</p>
            </aside>
        </div>
    </AppLayout>
</template>
