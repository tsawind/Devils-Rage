import { validCrop, type TCrop } from '@/lib/cameras/crop';
import { ref, watch } from 'vue';

export { cropFromCorners, cropPlacement, type TCrop } from '@/lib/cameras/crop';

/**
 * Patch 62: the little live windows that open from a 📹 on the map.
 * Patch 62i: several at once, each sized and cropped on its own; size and crop are
 * remembered per camera in this browser.
 */
export type TPreview = { cameraId: number; x: number; y: number; width: number; crop: TCrop | null; z: number };

const LAYOUT_KEY = 'dr.cameraPreviewLayout';
const DEFAULT_WIDTH = 384;
export const MIN_WIDTH = 200;

/**
 * Patch 62n: the open windows are remembered too, so they come back after a reload, and a
 * camera that stops and starts again (same box shared again: same camera) reopens in place.
 */
const OPEN_KEY = 'dr.cameraPreviewsOpen';
function loadOpen(): TPreview[] {
    try {
        const stored = JSON.parse(window.localStorage.getItem(OPEN_KEY) ?? '[]');
        if (!Array.isArray(stored)) return [];
        return stored
            .filter((item): item is TPreview => item && typeof item === 'object' && Number.isInteger(item.cameraId))
            .map((item) => ({ cameraId: item.cameraId, x: Number(item.x) || 40, y: Number(item.y) || 80, width: Math.max(MIN_WIDTH, Number(item.width) || DEFAULT_WIDTH), crop: validCrop(item.crop ?? null), z: 1 }));
    } catch {
        return [];
    }
}
export const cameraPreviews = ref<TPreview[]>(typeof window === 'undefined' ? [] : loadOpen());
watch(
    cameraPreviews,
    (open) => {
        try {
            window.localStorage.setItem(OPEN_KEY, JSON.stringify(open));
        } catch {
            // Remembered for this visit only.
        }
    },
    { deep: true },
);
let top = 1;

type TLayout = Record<string, { width: number; crop: TCrop | null }>;
function loadLayout(): TLayout {
    try {
        const stored = JSON.parse(window.localStorage.getItem(LAYOUT_KEY) ?? '{}');
        return stored && typeof stored === 'object' ? (stored as TLayout) : {};
    } catch {
        return {};
    }
}
function saveLayout(preview: TPreview): void {
    try {
        const layout = loadLayout();
        layout[String(preview.cameraId)] = { width: preview.width, crop: preview.crop };
        window.localStorage.setItem(LAYOUT_KEY, JSON.stringify(layout));
    } catch {
        // Remembered for this visit only.
    }
}

/** Opens a camera's window (another click on the same camera closes it). */
export function openCameraPreview(cameraId: number, x: number, y: number): void {
    if (cameraPreviews.value.some((item) => item.cameraId === cameraId)) {
        closeCameraPreview(cameraId);
        return;
    }
    const remembered = loadLayout()[String(cameraId)];
    const offset = cameraPreviews.value.length * 24;
    cameraPreviews.value = [
        ...cameraPreviews.value,
        { cameraId, x: x + 12 + offset, y: y + 12 + offset, width: Math.max(MIN_WIDTH, remembered?.width ?? DEFAULT_WIDTH), crop: validCrop(remembered?.crop ?? null), z: ++top },
    ];
}

export function closeCameraPreview(cameraId?: number): void {
    cameraPreviews.value = cameraId === undefined ? [] : cameraPreviews.value.filter((item) => item.cameraId !== cameraId);
}

export function updateCameraPreview(cameraId: number, change: Partial<Omit<TPreview, 'cameraId'>>, remember = false): void {
    cameraPreviews.value = cameraPreviews.value.map((item) => {
        if (item.cameraId !== cameraId) return item;
        const next = { ...item, ...change };
        if (remember) saveLayout(next);
        return next;
    });
}

export function bringCameraPreviewToFront(cameraId: number): void {
    const item = cameraPreviews.value.find((candidate) => candidate.cameraId === cameraId);
    if (item && item.z !== top) updateCameraPreview(cameraId, { z: ++top });
}
