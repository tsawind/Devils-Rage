<script setup lang="ts">
import CameraVideo from '@/components/cameras/CameraVideo.vue';
import { cameraPreview, closeCameraPreview } from '@/lib/cameras/preview';
import { useCameras } from '@/composables/useCameras';
import { PictureInPicture2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Patch 62: the little live window that opens from a 📹 on the map (a pipe's pill or a
 * system). Drag it by its top line; ⧉ puts it on top of everything (picture-in-picture).
 */
const { cameras } = useCameras();
const camera = computed(() => cameras.value.find((item) => item.id === cameraPreview.value?.cameraId) ?? null);
const player = ref<InstanceType<typeof CameraVideo> | null>(null);

const offset = ref({ x: 0, y: 0 });
let drag: { x: number; y: number; ox: number; oy: number } | null = null;
function startDrag(event: PointerEvent): void {
    drag = { x: event.clientX, y: event.clientY, ox: offset.value.x, oy: offset.value.y };
    (event.target as HTMLElement).setPointerCapture(event.pointerId);
}
function moveDrag(event: PointerEvent): void {
    if (!drag) return;
    offset.value = { x: drag.ox + event.clientX - drag.x, y: drag.oy + event.clientY - drag.y };
}

const position = computed(() => {
    const at = cameraPreview.value;
    if (!at) return {};
    const width = 384;
    const x = Math.min(Math.max(8, at.x + 12 + offset.value.x), window.innerWidth - width - 8);
    const y = Math.min(Math.max(8, at.y + 12 + offset.value.y), window.innerHeight - 260);
    return { left: `${x}px`, top: `${y}px`, width: `${width}px` };
});
</script>

<template>
    <Teleport to="body">
        <div v-if="cameraPreview && camera" class="fixed z-50 overflow-hidden rounded-lg border border-purple-700 bg-neutral-950 shadow-xl" :style="position">
            <div class="flex cursor-move items-center gap-2 border-b border-purple-900 px-2 py-1 text-xs select-none" @pointerdown="startDrag" @pointermove="moveDrag" @pointerup="drag = null">
                <span class="size-2 rounded-full" :class="camera.status === 'live' ? 'animate-pulse bg-red-500' : 'bg-neutral-500'" />
                <span class="truncate font-semibold text-purple-200">{{ camera.name }}</span>
                <span class="truncate text-neutral-500">{{ camera.owner }}</span>
                <span class="ml-auto flex gap-1">
                    <button type="button" class="rounded p-0.5 text-neutral-400 hover:bg-neutral-800 hover:text-white" title="On top of everything" @pointerdown.stop @click="player?.pictureInPicture()">
                        <PictureInPicture2 class="size-3.5" />
                    </button>
                    <button type="button" class="rounded p-0.5 text-neutral-400 hover:bg-neutral-800 hover:text-white" title="Close" @pointerdown.stop @click="closeCameraPreview()">
                        <X class="size-3.5" />
                    </button>
                </span>
            </div>
            <div class="aspect-video">
                <CameraVideo ref="player" :camera-id="camera.id" />
            </div>
        </div>
    </Teleport>
</template>
