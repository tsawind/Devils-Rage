<script setup lang="ts">
import MapCameraEventController from '@/actions/App/Http/Controllers/MapCameraEventController';
import MapConnectionJumpController from '@/actions/App/Http/Controllers/MapConnectionJumpController';
import CameraVideo from '@/components/cameras/CameraVideo.vue';
import { watchClass, watchJumpMass } from '@/lib/fleetRules';
import { getMapChannelName } from '@/const/channels';
import { CameraActivityEvent, CamerasChangedEvent } from '@/const/events';
import { noteActivity, refreshCameras, refreshCamerasSoon, startCameraFeed, stopCameraFeed, useCameras, type TCameraActivityBroadcast, type TCameraEvent } from '@/composables/useCameras';
import { useOnClient } from '@/composables/useOnClient';
import AppLayout from '@/layouts/AppLayout.vue';
import SeoHead from '@/layouts/SeoHead.vue';
import { activityLabel } from '@/lib/cameras/activations';
import { stampTime } from '@/lib/cameras/broadcaster';
import { jsonFetch } from '@/lib/jsonFetch';
import { playActivationTone } from '@/lib/rageSound';
import { useEcho } from '@laravel/echo-vue';
import { PictureInPicture2, Volume2, VolumeX } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';

/**
 * Patch 62: the camera wall. Every camera on the map, live, like a security desk; on the
 * side what the hole watch saw in the last day, each with its clip. Members confirm a jump
 * (and say what went through) or dismiss a false one; clips are deleted after a day.
 */
const props = defineProps<{
    map: { id: number; slug: string; name: string };
    member: boolean;
    places: { systems: { id: number; name: string }[]; connections: { id: number; name: string }[]; holes?: { id: number; name: string }[] };
}>();

const { cameras, events, pipeActivity } = useCameras();

useOnClient(() => {
    startCameraFeed(props.map.id, props.map.slug);
    onBeforeUnmount(stopCameraFeed);
    if (!props.member) return;
    useEcho(getMapChannelName(props.map.id), CamerasChangedEvent, () => refreshCamerasSoon());
    useEcho<TCameraActivityBroadcast>(getMapChannelName(props.map.id), CameraActivityEvent, (event) => {
        noteActivity(event);
        if (event.kind === 'activation' && sound.value) playActivationTone();
    });
});

const sound = ref(true);
const columns = ref(2);
const heard = ref<Record<number, boolean>>({});
const players = ref<Record<number, InstanceType<typeof CameraVideo> | null>>({});
const kindFilter = ref<'all' | 'hole' | 'other' | 'structure' | 'pi' | 'screen'>('all');
const showDismissed = ref(false);
const openClip = ref<number | null>(null);

const shown = computed(() => cameras.value.filter((camera) => camera.kind !== 'watch' && (kindFilter.value === 'all' || camera.kind === kindFilter.value)));
const cameraName = (id: number) => cameras.value.find((camera) => camera.id === id)?.name ?? 'A camera (now off)';
const pipeName = (id: number | null) => (id === null ? null : (props.places.connections.find((pipe) => pipe.id === id)?.name ?? null));
const pinOf = (camera: { map_connection_id: number | null; map_solarsystem_id: number | null; signature_id: number | null }) =>
    pipeName(camera.map_connection_id) ??
    props.places.holes?.find((hole) => hole.id === camera.signature_id)?.name ??
    props.places.systems.find((system) => system.id === camera.map_solarsystem_id)?.name ??
    null;
const eventPlace = (event: TCameraEvent) => pipeName(event.map_connection_id) ?? props.places.holes?.find((hole) => hole.id === event.signature_id)?.name ?? null;
const listed = computed(() => events.value.filter((event) => showDismissed.value || event.state !== 'dismissed'));

const KIND_TEXT: Record<string, string> = {
    activation: '⚡ Activation',
    appeared: '＋ Ship(s) appeared',
    vanished: '－ Ship(s) left',
    scan_new: '＋ New scan result(s)',
    scan_gone: '－ Scan result(s) gone',
    local_in: '＋ Pilot(s) in local',
    local_out: '－ Pilot(s) left local',
};
const SHIP_CLASSES = ['frigate', 'destroyer', 'cruiser', 'battlecruiser', 'battleship', 'industrial', 'capital'];

function ago(iso: string): string {
    const seconds = Math.max(0, Math.round((Date.now() - Date.parse(iso)) / 1000));
    if (seconds < 60) return `${seconds}s ago`;
    if (seconds < 3600) return `${Math.round(seconds / 60)} min ago`;
    return `${Math.round(seconds / 3600)} h ago`;
}

/**
 * Confirming a jump on a hole also logs it on that hole's mass log (worst case for the class,
 * like the watcher's buttons), so the mass counts without a second click.
 */
async function mark(event: TCameraEvent, state: TCameraEvent['state'], extra: Partial<Pick<TCameraEvent, 'ship_class' | 'side'>> = {}): Promise<void> {
    await jsonFetch(MapCameraEventController.update(event.id).url, 'PATCH', { state, ...extra });
    const shipClass = watchClass(extra.ship_class);
    if (state === 'confirmed' && shipClass && event.map_connection_id !== null) {
        await jsonFetch(MapConnectionJumpController.store().url, 'POST', {
            map_connection_id: event.map_connection_id,
            direction: 'unknown',
            mass: watchJumpMass(shipClass),
            side: extra.side ?? 'hostile',
            ship_class: shipClass.key,
            fit: { higgs: shipClass.higgs, prop: shipClass.prop > 0, plate: shipClass.plate > 0 },
        });
    }
    await refreshCameras();
}

const GRID: Record<number, string> = { 1: 'grid-cols-1', 2: 'grid-cols-1 md:grid-cols-2', 3: 'grid-cols-1 md:grid-cols-2 xl:grid-cols-3', 4: 'grid-cols-2 xl:grid-cols-4' };
const gridClass = computed(() => GRID[columns.value] ?? GRID[2]);
</script>

<template>
    <AppLayout>
        <SeoHead :title="`Cameras · ${map.name}`" description="Live cameras on the map" />
        <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-3 p-3 xl:flex-row">
            <section class="flex min-w-0 flex-1 flex-col gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="mr-2 text-lg font-semibold">Cameras <span class="text-sm font-normal text-muted-foreground">· {{ map.name }}</span></h1>
                    <select v-model="kindFilter" class="h-8 rounded-md border border-border bg-background px-2 text-xs">
                        <option value="all">All cameras</option>
                        <option value="hole">Holes</option>
                        <option value="other">Overview / local</option>
                        <option value="structure">Structures</option>
                        <option value="pi">PI</option>
                        <option value="screen">Whole pictures</option>
                    </select>
                    <div class="flex overflow-hidden rounded-md border border-border text-xs">
                        <button v-for="count in [1, 2, 3, 4]" :key="count" type="button" class="px-2 py-1" :class="columns === count ? 'bg-muted font-semibold' : 'text-muted-foreground'" @click="columns = count">{{ count }}</button>
                    </div>
                    <button type="button" class="flex items-center gap-1 rounded-md border border-border px-2 py-1 text-xs" :title="sound ? 'Activation tone on' : 'Activation tone off'" @click="sound = !sound">
                        <Volume2 v-if="sound" class="size-3.5" /><VolumeX v-else class="size-3.5" /> Tone
                    </button>
                </div>
                <div v-if="!shown.length" class="rounded-md border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                    No cameras on right now.<template v-if="member"> Start one from the map's 📹 → Share a camera.</template>
                </div>
                <div class="grid gap-2" :class="gridClass">
                    <article
                        v-for="camera in shown"
                        :key="camera.id"
                        class="overflow-hidden rounded-md border bg-neutral-950"
                        :class="camera.map_connection_id !== null && pipeActivity.has(camera.map_connection_id) ? 'border-fuchsia-500 shadow-[0_0_14px_var(--color-fuchsia-600)]' : 'border-neutral-800'"
                    >
                        <header class="flex items-center gap-2 px-2 py-1 text-xs">
                            <span class="size-2 rounded-full" :class="camera.status === 'live' ? 'animate-pulse bg-red-500' : 'bg-neutral-500'" />
                            <span class="truncate font-semibold text-neutral-100">{{ camera.name }}</span>
                            <span v-if="pinOf(camera)" class="truncate text-purple-300">{{ pinOf(camera) }}</span>
                            <span class="truncate text-neutral-500">{{ camera.owner }}<template v-if="camera.character"> · {{ camera.character }}</template></span>
                            <span v-if="camera.status === 'paused'" class="text-amber-400">paused</span>
                            <span
                                v-if="camera.map_connection_id !== null && pipeActivity.get(camera.map_connection_id)"
                                class="rounded-full bg-purple-700 px-2 text-[11px] font-bold text-white"
                                >⚡ {{ activityLabel(pipeActivity.get(camera.map_connection_id)!) }}</span
                            >
                            <span class="ml-auto flex gap-1">
                                <button type="button" class="rounded p-0.5 text-neutral-400 hover:text-white" :title="heard[camera.id] ? 'Mute' : 'Sound (whole-screen cameras only)'" @click="heard[camera.id] = !heard[camera.id]">
                                    <Volume2 v-if="heard[camera.id]" class="size-3.5" /><VolumeX v-else class="size-3.5" />
                                </button>
                                <button type="button" class="rounded p-0.5 text-neutral-400 hover:text-white" title="On top of everything" @click="players[camera.id]?.pictureInPicture()">
                                    <PictureInPicture2 class="size-3.5" />
                                </button>
                            </span>
                        </header>
                        <div class="aspect-video">
                            <CameraVideo :ref="(player) => (players[camera.id] = player as InstanceType<typeof CameraVideo> | null)" :camera-id="camera.id" :muted="!heard[camera.id]" />
                        </div>
                    </article>
                </div>
            </section>

            <aside class="flex w-full shrink-0 flex-col gap-2 xl:w-[26rem]">
                <div class="flex items-center gap-2">
                    <h2 class="text-sm font-semibold">Hole watch · last 24 h</h2>
                    <label class="ml-auto flex items-center gap-1 text-xs text-muted-foreground"><input v-model="showDismissed" type="checkbox" /> dismissed</label>
                </div>
                <p v-if="!listed.length" class="text-xs text-muted-foreground">Nothing seen yet. Cameras with the hole watch on report jumps here, each with a short clip.</p>
                <div v-for="event in listed" :key="event.id" class="flex flex-col gap-1.5 rounded-md border p-2 text-xs" :class="event.state === 'dismissed' ? 'border-border opacity-50' : event.kind === 'activation' ? 'border-purple-800' : 'border-border'">
                    <div class="flex items-center gap-2">
                        <span class="font-semibold" :class="event.kind === 'activation' ? 'text-fuchsia-300' : 'text-neutral-200'">{{ KIND_TEXT[event.kind] ?? event.kind }}</span>
                        <span class="truncate text-muted-foreground">{{ cameraName(event.map_camera_id) }}</span>
                        <span class="ml-auto shrink-0 text-muted-foreground" :title="new Date(event.happened_at).toLocaleString()">{{ stampTime(new Date(event.happened_at)).slice(11) }} EVE · {{ ago(event.happened_at) }}</span>
                    </div>
                    <p v-if="eventPlace(event)" class="text-purple-300">{{ eventPlace(event) }}</p>
                    <button v-if="event.still_url && openClip !== event.id" type="button" class="relative overflow-hidden rounded" title="Play the clip" @click="openClip = event.id">
                        <img :src="event.still_url" alt="What the camera saw" class="aspect-video w-full object-cover" loading="lazy" />
                        <span v-if="event.clip_url" class="absolute inset-0 flex items-center justify-center bg-black/30 text-2xl text-white">▶</span>
                    </button>
                    <video v-if="event.clip_url && (openClip === event.id || !event.still_url)" :src="event.clip_url" class="aspect-video w-full rounded bg-black" controls preload="none" :autoplay="openClip === event.id" />
                    <p v-if="!event.clip_url && !event.still_url" class="text-muted-foreground">Clip on its way…</p>
                    <div v-if="member" class="flex flex-wrap items-center gap-1">
                        <template v-if="event.state === 'new'">
                            <select class="h-6 rounded border border-border bg-background px-1" @change="mark(event, 'confirmed', { ship_class: ($event.target as HTMLSelectElement).value, side: 'hostile' })">
                                <option value="" selected disabled>Confirm: hostile…</option>
                                <option v-for="ship in SHIP_CLASSES" :key="ship" :value="ship">{{ ship }}</option>
                            </select>
                            <select class="h-6 rounded border border-border bg-background px-1" @change="mark(event, 'confirmed', { ship_class: ($event.target as HTMLSelectElement).value, side: 'friendly' })">
                                <option value="" selected disabled>friendly…</option>
                                <option v-for="ship in SHIP_CLASSES" :key="ship" :value="ship">{{ ship }}</option>
                            </select>
                            <button type="button" class="rounded border border-border px-2 py-0.5 hover:bg-muted" @click="mark(event, 'dismissed')">Not a jump</button>
                        </template>
                        <template v-else>
                            <span :class="event.state === 'confirmed' ? 'text-green-400' : 'text-muted-foreground'">
                                {{ event.state === 'confirmed' ? `Confirmed${event.side ? ` · ${event.side}` : ''}${event.ship_class ? ` ${event.ship_class}` : ''}` : 'Dismissed' }}
                            </span>
                            <button type="button" class="ml-auto rounded px-1 text-muted-foreground hover:text-foreground" @click="mark(event, 'new')">undo</button>
                        </template>
                    </div>
                    <p v-if="member && event.state === 'confirmed' && event.map_connection_id" class="text-[11px] text-muted-foreground">Logged on the hole's mass log (undo there if it was wrong).</p>
                </div>
            </aside>
        </div>
    </AppLayout>
</template>
