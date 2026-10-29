import { cropFromCorners, cropPlacement, validCrop } from '@/lib/cameras/crop';
import { describe, expect, it } from 'vitest';

describe('camera window crops', () => {
    it('makes a crop from two corners in either order', () => {
        const crop = cropFromCorners({ x: 0.6, y: 0.8 }, { x: 0.2, y: 0.3 });
        expect(crop?.x).toBe(0.2);
        expect(crop?.y).toBe(0.3);
        expect(Math.round((crop?.w ?? 0) * 100)).toBe(40);
        expect(crop?.h).toBe(0.5);
    });

    it('ignores a click (too small) and the whole picture', () => {
        expect(cropFromCorners({ x: 0.5, y: 0.5 }, { x: 0.51, y: 0.52 })).toBeNull();
        expect(cropFromCorners({ x: -1, y: -1 }, { x: 2, y: 2 })).toBeNull();
    });

    it('drops broken remembered crops', () => {
        expect(validCrop({ x: 0.9, y: 0, w: 0.5, h: 0.5 })).toBeNull();
        expect(validCrop({ x: Number.NaN, y: 0, w: 0.5, h: 0.5 })).toBeNull();
        expect(validCrop({ x: 0.1, y: 0.1, w: 0.5, h: 0.5 })).toEqual({ x: 0.1, y: 0.1, w: 0.5, h: 0.5 });
    });

    it('places the whole picture so only the crop shows', () => {
        expect(cropPlacement({ x: 0.5, y: 0.25, w: 0.5, h: 0.5 }, 1920, 1080)).toEqual({ aspect: 16 / 9, width: 200, height: 200, left: -100, top: -50 });
        expect(cropPlacement(null, 0, 0)).toEqual({ aspect: 16 / 9, width: 100, height: 100, left: 0, top: 0 });
    });
});
