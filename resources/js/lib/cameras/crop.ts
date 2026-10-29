/** Patch 62i: cropping a camera window to part of its picture (all fractions of the whole picture). */
export type TCrop = { x: number; y: number; w: number; h: number };

/** A crop inside the picture (fractions), or null when it isn't one. */
export function validCrop(crop: TCrop | null): TCrop | null {
    if (!crop) return null;
    const { x, y, w, h } = crop;
    if (![x, y, w, h].every((value) => Number.isFinite(value))) return null;
    if (w < 0.03 || h < 0.03 || x < 0 || y < 0 || x + w > 1.0001 || y + h > 1.0001) return null;
    if (w > 0.999 && h > 0.999) return null;
    return { x, y, w, h };
}

/** The crop a dragged rectangle makes (two corners, fractions of the whole picture). */
export function cropFromCorners(a: { x: number; y: number }, b: { x: number; y: number }): TCrop | null {
    const clamp = (value: number): number => Math.min(1, Math.max(0, value));
    const x1 = clamp(Math.min(a.x, b.x));
    const y1 = clamp(Math.min(a.y, b.y));
    return validCrop({ x: x1, y: y1, w: clamp(Math.max(a.x, b.x)) - x1, h: clamp(Math.max(a.y, b.y)) - y1 });
}

/**
 * How to place the whole picture inside a window that shows only the crop: the window's
 * aspect ratio, and the picture's size / offset in % of the window.
 */
export function cropPlacement(crop: TCrop | null, videoWidth: number, videoHeight: number): { aspect: number; width: number; height: number; left: number; top: number } {
    const ratio = videoWidth > 0 && videoHeight > 0 ? videoWidth / videoHeight : 16 / 9;
    const c = crop ?? { x: 0, y: 0, w: 1, h: 1 };
    return { aspect: (ratio * c.w) / c.h, width: 100 / c.w, height: 100 / c.h, left: (-c.x / c.w) * 100 + 0, top: (-c.y / c.h) * 100 + 0 };
}
