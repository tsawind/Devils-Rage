import { averagePrint, countRows, frameChange, printLikeness, RowWatch, soundPrint, SpikeWatch, toGrey } from '@/lib/cameras/detector';
import { describe, expect, it } from 'vitest';

/** A grey picture with text-like rows (alternating bright / dark pixels) at the given heights. */
function overview(width: number, height: number, rows: number[]): Uint8Array {
    const grey = new Uint8Array(width * height).fill(20);
    for (const y of rows) for (let x = 0; x < width; x += 2) grey[y * width + x] = 220;
    return grey;
}

describe('hole watch: picture', () => {
    it('measures change between frames and turns colour into grey', () => {
        expect(frameChange([0, 10, 20], [10, 10, 0])).toBe(10);
        expect([...toGrey([255, 255, 255, 255, 0, 0, 0, 255])]).toEqual([255, 0]);
    });

    it('a flash far above the usual flicker counts once, then stays quiet a while', () => {
        const watch = new SpikeWatch();
        let t = 0;
        for (let frame = 0; frame < 80; frame++) expect(watch.push(1 + (frame % 3) * 0.5, (t += 125))).toBe(0);
        expect(watch.push(40, (t += 125))).toBeGreaterThan(50);
        expect(watch.push(40, (t += 125))).toBe(0);
        for (let frame = 0; frame < 40; frame++) watch.push(1, (t += 125));
        expect(watch.push(35, (t += 125))).toBeGreaterThan(0);
    });

    it('nothing counts while it is still learning, or for small changes on a dead-still picture', () => {
        const watch = new SpikeWatch();
        expect(watch.push(50, 0)).toBe(0);
        const still = new SpikeWatch();
        let t = 0;
        for (let frame = 0; frame < 60; frame++) still.push(0, (t += 125));
        expect(still.push(4, (t += 125))).toBe(0);
        expect(still.push(9, (t += 125))).toBeGreaterThan(0);
    });
});

describe('hole watch: overview rows', () => {
    it('counts rows of text', () => {
        expect(countRows(overview(40, 30, [3, 4, 10, 11, 20, 21]), 40, 30)).toBe(3);
        // A one-pixel gap inside a row (two lines of the same text) is still one row; a single stray line is noise.
        expect(countRows(overview(40, 30, [3, 5, 6, 15]), 40, 30)).toBe(1);
        expect(countRows(overview(40, 30, []), 40, 30)).toBe(0);
    });

    it('reports a steady change only', () => {
        const watch = new RowWatch(3);
        for (let frame = 0; frame < 3; frame++) expect(watch.push(2)).toBe(0);
        expect(watch.push(3)).toBe(0);
        expect(watch.push(2)).toBe(0);
        expect([watch.push(4), watch.push(4), watch.push(4)]).toEqual([0, 0, 2]);
        expect([watch.push(1), watch.push(1), watch.push(1)]).toEqual([0, 0, -3]);
    });
});

describe('hole watch: sound', () => {
    it('matches a sound to the learned one', () => {
        const spectrum = (peak: number) => Float32Array.from({ length: 1024 }, (_, bin) => (Math.abs(bin - peak) < 6 ? -20 : -95));
        const learned = averagePrint([soundPrint(spectrum(8), 48000), soundPrint(spectrum(9), 48000)]);
        expect(printLikeness(learned, soundPrint(spectrum(8), 48000))).toBeGreaterThan(0.82);
        expect(printLikeness(learned, soundPrint(spectrum(300), 48000))).toBeLessThan(0.5);
        expect(printLikeness([], [])).toBe(0);
    });
});
