import { sendSize, stampTime } from '@/lib/cameras/broadcaster';
import { describe, expect, it } from 'vitest';

describe('camera box send size', () => {
    it('sends a small box at its own size', () => {
        expect(sendSize({ x: 0.7, y: 0.1, w: 0.2, h: 0.3 }, 1920, 1080, '720p5')).toEqual({ width: 384, height: 324 });
    });

    it('shrinks a big box to fit the quality, keeping its shape, in even pixels', () => {
        expect(sendSize({ x: 0, y: 0, w: 1, h: 1 }, 2560, 1440, '720p5')).toEqual({ width: 1280, height: 720 });
        expect(sendSize({ x: 0, y: 0, w: 1, h: 1 }, 3440, 1440, '1080p10')).toEqual({ width: 1920, height: 804 });
    });

    it('stamps EVE time (UTC) like the game clock', () => {
        expect(stampTime(new Date(Date.UTC(2026, 9, 29, 4, 7, 3)))).toBe('2026.10.29 04:07:03');
    });
});
