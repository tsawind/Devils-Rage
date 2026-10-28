<script setup lang="ts">
import MapCameraController from '@/actions/App/Http/Controllers/MapCameraController';
import { jsonFetch } from '@/lib/jsonFetch';
import { watch as watchRelay, type TRelayPass, type TRtcSession } from '@/lib/cameras/rtc';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Patch 62: one camera, live. Asks the mapper for a watch pass, then the relay for the
 * video; tries again on its own when the link drops. Muted unless asked.
 */
const { cameraId, muted = true } = defineProps<{ cameraId: number; muted?: boolean }>();

const video = ref<HTMLVideoElement | null>(null);
const state = ref<'connecting' | 'live' | 'lost' | 'refused'>('connecting');
let session: TRtcSession | null = null;
let retry: ReturnType<typeof setTimeout> | null = null;
let closed = false;

async function connect(): Promise<void> {
    if (closed) return;
    state.value = 'connecting';
    const relay = await jsonFetch<TRelayPass>(MapCameraController.watch(cameraId).url, 'POST');
    if (closed) return;
    if (!relay) {
        state.value = 'refused';
        retry = setTimeout(connect, 15_000);
        return;
    }
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

function disconnect(): void {
    if (retry) clearTimeout(retry);
    retry = null;
    session?.stop();
    session = null;
}

onMounted(connect);
onBeforeUnmount(() => {
    closed = true;
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
        <video ref="video" class="size-full object-contain" autoplay playsinline :muted="muted" />
        <div v-if="state !== 'live'" class="absolute inset-0 flex items-center justify-center text-xs text-neutral-400">
            <span v-if="state === 'connecting'" class="animate-pulse">Connecting…</span>
            <span v-else-if="state === 'lost'" class="animate-pulse">Lost the picture, trying again…</span>
            <span v-else>Not allowed to watch this one (or it just stopped)</span>
        </div>
    </div>
</template>
