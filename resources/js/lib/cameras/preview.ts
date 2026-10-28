import { ref } from 'vue';

/** Patch 62: which camera's little live window is open on the map, and where it was asked for. */
export const cameraPreview = ref<{ cameraId: number; x: number; y: number } | null>(null);

export function openCameraPreview(cameraId: number, x: number, y: number): void {
    cameraPreview.value = cameraPreview.value?.cameraId === cameraId ? null : { cameraId, x, y };
}

export function closeCameraPreview(): void {
    cameraPreview.value = null;
}
