<script setup lang="ts">
import CameraVideo from '@/components/cameras/CameraVideo.vue';
import { useCameras } from '@/composables/useCameras';
import {
    bringCameraPreviewToFront,
    closeCameraPreview,
    cropFromCorners,
    cropPlacement,
    MIN_WIDTH,
    updateCameraPreview,
    type TPreview,
} from '@/lib/cameras/preview';
import { Crop, Maximize, PictureInPicture2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Patch 62: one little live window from a 📹 on the map. Drag it by its top line; ⧉ puts it
 * on top of everything (picture-in-picture).
 * Patch 62i: one of several; the corner resizes it, ✂ crops it to a part of the picture
 * (drag a box over what to keep), ⤢ shows the whole picture again.
 */
const { preview } = defineProps<{ preview: TPreview }>();
const { cameras } = useCameras();
const camera = computed(() => cameras.value.find((item) => item.id === preview.cameraId) ?? null);
const player = ref<InstanceType<typeof CameraVideo> | null>(null);
const videoSize = ref({ width: 16, height: 9 });
const cropping = ref(false);

const placement = computed(() => cropPlacement(cropping.value ? null : preview.crop, videoSize.value.width, videoSize.value.height));

const style = computed(() => {
    const maxWidth = Math.max(MIN_WIDTH, window.innerWidth - 16);
    // The picture's height follows its width; keep the whole window on screen.
    const maxByHeight = Math.max(MIN_WIDTH, (window.innerHeight - 60) * placement.value.aspect);
    const width = Math.min(preview.width, maxWidth, maxByHeight);
    const height = width / placement.value.aspect + 28;
    const x = Math.min(Math.max(8, preview.x), window.innerWidth - width - 8);
    const y = Math.min(Math.max(8, preview.y), window.innerHeight - height - 8);
    return { left: `${x}px`, top: `${y}px`, width: `${width}px`, zIndex: 50 + preview.z };
});

// ---- Moving and resizing ------------------------------------------------------------------
let drag: { kind: 'move' | 'size'; x: number; y: number; ox: number; oy: number; width: number } | null = null;
function startDrag(event: PointerEvent, kind: 'move' | 'size'): void {
    bringCameraPreviewToFront(preview.cameraId);
    drag = { kind, x: event.clientX, y: event.clientY, ox: preview.x, oy: preview.y, width: preview.width };
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
}
function moveDrag(event: PointerEvent): void {
    if (!drag) return;
    const dx = event.clientX - drag.x;
    const dy = event.clientY - drag.y;
    if (drag.kind === 'move') {
        updateCameraPreview(preview.cameraId, { x: drag.ox + dx, y: drag.oy + dy });
        return;
    }
    // The corner: follow whichever way the mouse went further (the height comes with the width).
    const grow = Math.abs(dx) >= Math.abs(dy * placement.value.aspect) ? dx : dy * placement.value.aspect;
    updateCameraPreview(preview.cameraId, { width: Math.max(MIN_WIDTH, Math.round(drag.width + grow)) });
}
function endDrag(): void {
    if (drag?.kind === 'size') updateCameraPreview(preview.cameraId, {}, true);
    drag = null;
}

// ---- Cropping -----------------------------------------------------------------------------
const picture = ref<HTMLElement | null>(null);
const box = ref<{ a: { x: number; y: number }; b: { x: number; y: number } } | null>(null);
function pointIn(event: PointerEvent): { x: number; y: number } {
    const rect = picture.value?.getBoundingClientRect();
    if (!rect || rect.width === 0 || rect.height === 0) return { x: 0, y: 0 };
    return { x: (event.clientX - rect.left) / rect.width, y: (event.clientY - rect.top) / rect.height };
}
function startCrop(event: PointerEvent): void {
    const at = pointIn(event);
    box.value = { a: at, b: at };
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
}
function moveCrop(event: PointerEvent): void {
    if (box.value) box.value = { ...box.value, b: pointIn(event) };
}
function endCrop(): void {
    const chosen = box.value ? cropFromCorners(box.value.a, box.value.b) : null;
    box.value = null;
    if (!chosen) return;
    cropping.value = false;
    // Keep the picture about as big on screen: the window shrinks with the part kept.
    updateCameraPreview(preview.cameraId, { crop: chosen, width: Math.max(MIN_WIDTH, Math.round(preview.width * Math.max(chosen.w, 0.5))) }, true);
}
const boxStyle = computed(() => {
    if (!box.value) return null;
    const { a, b } = box.value;
    return {
        left: `${Math.min(a.x, b.x) * 100}%`,
        top: `${Math.min(a.y, b.y) * 100}%`,
        width: `${Math.abs(a.x - b.x) * 100}%`,
        height: `${Math.abs(a.y - b.y) * 100}%`,
    };
});
function wholePicture(): void {
    cropping.value = false;
    updateCameraPreview(preview.cameraId, { crop: null }, true);
}
</script>

<template>
    <div v-if="camera" class="fixed overflow-hidden rounded-lg border border-purple-700 bg-neutral-950 shadow-xl" :style="style" @pointerdown="bringCameraPreviewToFront(preview.cameraId)">
        <div class="flex h-7 cursor-move items-center gap-2 border-b border-purple-900 px-2 text-xs select-none" @pointerdown="(event) => startDrag(event, 'move')" @pointermove="moveDrag" @pointerup="endDrag">
            <span class="size-2 shrink-0 rounded-full" :class="camera.status === 'live' ? 'animate-pulse bg-red-500' : 'bg-neutral-500'" />
            <span class="truncate font-semibold text-purple-200">{{ camera.name }}</span>
            <span class="truncate text-neutral-500">{{ camera.owner }}</span>
            <span class="ml-auto flex shrink-0 gap-1">
                <button
                    type="button"
                    class="rounded p-0.5 hover:bg-neutral-800 hover:text-white"
                    :class="cropping ? 'bg-purple-900 text-white' : 'text-neutral-400'"
                    :title="cropping ? 'Drag a box over the part to keep (click ✂ again to stop)' : 'Crop: show only part of the picture'"
                    @pointerdown.stop
                    @click="cropping = !cropping"
                >
                    <Crop class="size-3.5" />
                </button>
                <button v-if="preview.crop" type="button" class="rounded p-0.5 text-neutral-400 hover:bg-neutral-800 hover:text-white" title="Whole picture again" @pointerdown.stop @click="wholePicture">
                    <Maximize class="size-3.5" />
                </button>
                <button type="button" class="rounded p-0.5 text-neutral-400 hover:bg-neutral-800 hover:text-white" title="On top of everything" @pointerdown.stop @click="player?.pictureInPicture()">
                    <PictureInPicture2 class="size-3.5" />
                </button>
                <button type="button" class="rounded p-0.5 text-neutral-400 hover:bg-neutral-800 hover:text-white" title="Close" @pointerdown.stop @click="closeCameraPreview(preview.cameraId)">
                    <X class="size-3.5" />
                </button>
            </span>
        </div>
        <div ref="picture" class="relative overflow-hidden" :style="{ aspectRatio: String(placement.aspect) }">
            <div class="absolute" :style="{ width: `${placement.width}%`, height: `${placement.height}%`, left: `${placement.left}%`, top: `${placement.top}%` }">
                <CameraVideo ref="player" :camera-id="camera.id" @size="(width, height) => (videoSize = { width, height })" />
            </div>
            <div v-if="cropping" class="absolute inset-0 cursor-crosshair bg-black/20" @pointerdown.stop="startCrop" @pointermove="moveCrop" @pointerup="endCrop">
                <p v-if="!box" class="pointer-events-none absolute inset-x-0 top-1 text-center text-[11px] text-purple-100 drop-shadow">Drag a box over the part to keep</p>
                <div v-if="boxStyle" class="pointer-events-none absolute border-2 border-fuchsia-400 bg-fuchsia-400/10" :style="boxStyle" />
            </div>
            <div
                class="absolute right-0 bottom-0 size-4 cursor-nwse-resize"
                style="background: linear-gradient(135deg, transparent 50%, rgb(168 85 247 / 0.8) 50%)"
                title="Drag to resize"
                @pointerdown.stop="(event) => startDrag(event, 'size')"
                @pointermove="moveDrag"
                @pointerup="endDrag"
            />
        </div>
    </div>
</template>
