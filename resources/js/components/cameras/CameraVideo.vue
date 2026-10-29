<script setup lang="ts">
import MapCameraController from '@/actions/App/Http/Controllers/MapCameraController';
import { watch as watchRelay, type TRelayPass, type TRtcSession } from '@/lib/cameras/rtc';
import { driverHeaders } from '@/lib/windowDriver';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Patch 62: one camera, live. Asks the mapper for a watch pass, then the relay for the
 * video; tries again on its own when the link drops. Muted unless asked.
 */
const { cameraId, muted = true } = defineProps<{ cameraId: number; muted?: boolean }>();
// Patch 62i: the picture's own size (for cropping windows).
const emit = defineEmits<{ size: [width: number, height: number] }>();
function reportSize(): void {
    if (video.value && video.value.videoWidth > 0) emit('size', video.value.videoWidth, video.value.videoHeight);
}

const video = ref<HTMLVideoElement | null>(null);
// Patch 62i: 'off' is the mapper saying no (the camera stopped, or not yours to watch);
// 'busy' is the mapper not answering (e.g. while it updates), which used to read as "not allowed".
const state = ref<'connecting' | 'live' | 'lost' | 'off' | 'busy'>('connecting');
let session: TRtcSession | null = null;
let retry: ReturnType<typeof setTimeout> | null = null;
let closed = false;

async function connect(): Promise<void> {
    if (closed) return;
    state.value = 'connecting';
    const asked = await askForPass();
    if (closed) return;
    if (!('url' in asked)) {
        state.value = asked.refused ? 'off' : 'busy';
        retry = setTimeout(connect, asked.refused ? 15_000 : 5000);
        return;
    }
    const relay = asked;
    try {
        session = await watchRelay(
            relay,
            (stream) => {
                if (video.value && video.value.srcObject !== stream) video.value.srcObject = stream;
                state.value = 'live';
            },
            () => {
                state.value = 'lost';
                session?.stop();
                session = null;
                retry = setTimeout(connect, 3000);
            },
        );
        if (closed) session.stop();
    } catch {
        state.value = 'lost';
        retry = setTimeout(connect, 5000);
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/** The watch pass, or why not: refused (403 / 404) or no answer (anything else). */
async function askForPass(): Promise<TRelayPass | { refused: boolean }> {
    try {
        const response = await fetch(MapCameraController.watch(cameraId).url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken(), ...driverHeaders() },
        });
        if (response.ok) return (await response.json()) as TRelayPass;
        return { refused: response.status === 403 || response.status === 404 };
    } catch {
        return { refused: false };
    }
}

function disconnect(): void {
    if (retry) clearTimeout(retry);
    retry = null;
    session?.stop();
    session = null;
}

// Patch 62m: "still watching" every minute, so a camera that streams only while someone
// watches keeps streaming (it goes back to standby a couple of minutes after the last viewer).
let stillWatching: ReturnType<typeof setInterval> | null = null;
onMounted(() => {
    void connect();
    stillWatching = setInterval(() => {
        if (state.value === 'live') void askForPass();
    }, 60_000);
});
onBeforeUnmount(() => {
    closed = true;
    if (stillWatching) clearInterval(stillWatching);
    disconnect();
});
watch(
    () => cameraId,
    () => {
        disconnect();
        void connect();
    },
);

/** Always on top (the browser's picture-in-picture), e.g. over EVE. */
async function pictureInPicture(): Promise<void> {
    try {
        if (document.pictureInPictureElement) await document.exitPictureInPicture();
        else await video.value?.requestPictureInPicture();
    } catch {
        // Not supported here.
    }
}

defineExpose({ pictureInPicture, video });
</script>

<template>
    <div class="relative size-full bg-black">
        <video ref="video" class="size-full object-contain" autoplay playsinline :muted="muted" @loadedmetadata="reportSize" @resize="reportSize" />
        <div v-if="state !== 'live'" class="absolute inset-0 flex items-center justify-center text-xs text-neutral-400">
            <span v-if="state === 'connecting'" class="animate-pulse">Connecting… (a camera on standby starts in a few seconds)</span>
            <span v-else-if="state === 'lost'" class="animate-pulse">Lost the picture, trying again…</span>
            <span v-else-if="state === 'busy'" class="animate-pulse">The mapper isn't answering (updating?), trying again…</span>
            <span v-else class="px-4 text-center">This camera is off: its share window closed or stopped sending. Trying again…</span>
        </div>
    </div>
</template>
