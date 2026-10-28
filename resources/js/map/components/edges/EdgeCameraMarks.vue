<script setup lang="ts">
import { useCameras } from '@/composables/useCameras';
import { activityLabel } from '@/lib/cameras/activations';
import { openCameraPreview } from '@/lib/cameras/preview';
import type { Vec2 } from '@/map/core/types';
import { computed } from 'vue';

/**
 * Patch 62: cameras on a pipe. A 📹 on the pill's left corner while a camera watches the
 * hole (click: its live picture). When a camera sees an activation, a purple "Activation"
 * pops above the pill for a minute with the jump-cloak countdown (×N when several).
 */
const { connectionId = null, signatureId = null, center, pillHeight, pillWidth, scale = 1, dot = false } = defineProps<{
    connectionId?: number | null;
    /** Patch 62b: a dotted pipe (hole nobody has jumped yet). */
    signatureId?: number | null;
    center: Vec2;
    pillWidth: number;
    pillHeight: number;
    scale?: number;
    dot?: boolean;
}>();

const { pipeActivity, pipeCameras, holeActivity, holeCameras } = useCameras();
const activity = computed(() => (connectionId !== null ? pipeActivity.value.get(connectionId) : signatureId !== null ? holeActivity.value.get(signatureId) : null) ?? null);
const cameras = computed(() => (connectionId !== null ? pipeCameras.value.get(connectionId) : signatureId !== null ? holeCameras.value.get(signatureId) : null) ?? []);
const label = computed(() => (activity.value ? activityLabel(activity.value) : ''));
/** The first seconds after a jump: a ring pulses out from the popup. */
const fresh = computed(() => (activity.value?.secondsLeft ?? 0) > 55);

const halfHeight = computed(() => (dot ? 6 : pillHeight / 2) * scale);
const halfWidth = computed(() => (dot ? 6 : pillWidth / 2) * scale);
const popupWidth = computed(() => Math.max(118, label.value.length * 6.4 + 22));

function open(event: MouseEvent): void {
    const camera = cameras.value.find((item) => item.id === activity.value?.newest.cameraId) ?? cameras.value[0];
    if (camera) openCameraPreview(camera.id, event.clientX, event.clientY);
}
</script>

<template>
    <g>
        <g
            v-if="cameras.length"
            class="pointer-events-auto cursor-pointer"
            :transform="`translate(${center.x - halfWidth + 2 * scale}, ${center.y - halfHeight - 2 * scale})`"
            @pointerdown.stop
            @click.stop="open"
        >
            <title>{{ cameras.map((camera) => `📹 ${camera.name} (${camera.owner ?? '?'})`).join('\n') }} — click to watch</title>
            <circle :r="8 * scale" fill="var(--color-purple-900)" stroke="var(--color-purple-400)" stroke-width="1.2" />
            <text text-anchor="middle" dominant-baseline="central" :font-size="9 * scale">📹</text>
        </g>
        <foreignObject
            v-if="activity"
            :x="center.x - (popupWidth * scale) / 2"
            :y="center.y - halfHeight - 28 * scale"
            :width="popupWidth * scale"
            :height="24 * scale"
            class="pointer-events-auto cursor-pointer overflow-visible"
            @pointerdown.stop
            @click.stop="open"
        >
            <div
                :style="{ width: `${popupWidth}px`, height: '24px', transform: `scale(${scale})`, transformOrigin: '0 0' }"
                class="relative flex items-center justify-center"
                :title="`Seen by ${activity.cameras.join(', ')} — hold cloak until the countdown ends. Click: watch`"
            >
                <span v-if="fresh" class="absolute inset-0 animate-ping rounded-full bg-fuchsia-500/50" />
                <span
                    class="relative flex h-[20px] items-center gap-1 rounded-full border border-fuchsia-300 bg-purple-700 px-2 text-[11px] font-bold whitespace-nowrap text-white shadow-[0_0_10px_var(--color-fuchsia-500)]"
                    :class="activity.secondsLeft === 0 ? 'opacity-60' : ''"
                >
                    <span aria-hidden="true">⚡</span>{{ label }}
                </span>
            </div>
        </foreignObject>
    </g>
</template>
