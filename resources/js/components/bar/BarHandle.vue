<script setup lang="ts">
/**
 * Patch 41: a divider in the slim bar you can drag: more or less room for the tabs before it.
 * Tabs that no longer fit go into that area's "+N" list.
 */
const { width, min = 60, max = 900 } = defineProps<{ width: number; min?: number; max?: number }>();
const emit = defineEmits<{ resize: [width: number] }>();

function start(event: PointerEvent): void {
    if (event.button !== 0) return;
    const handle = event.currentTarget as HTMLElement;
    const from = event.clientX;
    const startWidth = width;
    handle.setPointerCapture(event.pointerId);
    handle.dataset.dragging = '1';
    const move = (moved: PointerEvent) => emit('resize', Math.round(Math.min(max, Math.max(min, startWidth + moved.clientX - from))));
    const up = () => {
        delete handle.dataset.dragging;
        handle.removeEventListener('pointermove', move);
        handle.removeEventListener('pointerup', up);
    };
    handle.addEventListener('pointermove', move);
    handle.addEventListener('pointerup', up);
}
</script>

<template>
    <div
        class="group relative h-7 w-2.5 shrink-0 cursor-col-resize touch-none"
        role="separator"
        aria-orientation="vertical"
        title="Drag: more or less room"
        @pointerdown="start"
    >
        <span
            class="absolute top-1.5 bottom-1.5 left-1/2 w-px -translate-x-1/2 bg-border transition-all group-hover:w-0.5 group-hover:bg-sky-400 group-data-[dragging]:w-0.5 group-data-[dragging]:bg-sky-400"
        />
    </div>
</template>
