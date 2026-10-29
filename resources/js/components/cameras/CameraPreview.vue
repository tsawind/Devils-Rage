<script setup lang="ts">
import CameraPreviewWindow from '@/components/cameras/CameraPreviewWindow.vue';
import { cameraPreviews } from '@/lib/cameras/preview';

/**
 * Patch 62: the live windows that open from a 📹 on the map.
 * Patch 62i: as many as you open, each moved, sized and cropped on its own.
 */
</script>

<template>
    <Teleport to="body">
        <div>
            <CameraPreviewWindow v-for="item in cameraPreviews" :key="item.cameraId" :preview="item" />
        </div>
    </Teleport>
</template>
