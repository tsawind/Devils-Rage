/**
 * Patch 62: the hole watch. It only looks at the picture (and, if shared, the sound) the
 * camera already streams; nothing is sent to the game.
 *
 * - Picture, hole in space: a jump makes the hole flash. Each frame is shrunk to a small
 *   grey picture; how much it changed from the last frame is compared with how much it
 *   usually changes. A jump stands far above the usual flicker.
 * - Picture, overview box: rows (ships) coming and going. Rows of text are counted; a count
 *   that holds for a moment and differs from before means ships appeared or vanished.
 * - Sound (whole-screen share only: a browser can't take one window's sound): the pilot
 *   teaches it the activation sound once ("Learn"); afterwards a loud moment whose sound
 *   matches it counts.
 *
 * All the tuning numbers are here.
 */
export const DETECT = {
    /** The grey picture the frames are shrunk to. */
    width: 64,
    height: 36,
    /** Frames looked at per second. */
    fps: 8,
    /** How fast the usual change adapts (per frame). */
    baselineRate: 0.03,
    /** A change counts when it is this many spreads above the usual… */
    spreads: 6,
    /** …and at least this big (0–255 mean grey change), so a still picture's noise never counts. */
    minChange: 6,
    /** Frames to watch before anything counts (the usual change has to be learned first). */
    warmupFrames: 24,
    /** After a hit, quiet for this long. */
    cooldownMs: 4000,
    /** Overview rows: a pixel row is part of text when this share of it differs from its neighbour… */
    rowBusyShare: 0.06,
    /** …by this much grey. */
    rowContrast: 40,
    /** Text rows thinner than this (pixels in the grey picture of the box) are noise. */
    minRowHeight: 1,
    /** A new row count has to hold for this many frames. */
    rowsSteadyFrames: 4,
    /** Sound: analyser size and how close a sound must be to the learned one (0–1). */
    fftSize: 2048,
    soundMatch: 0.82,
    /** Sound: loudness jump over the usual (in spreads) that starts a look. */
    soundSpreads: 5,
} as const;

/** Mean absolute difference of two equal-length grey pictures (0–255). */
export function frameChange(a: ArrayLike<number>, b: ArrayLike<number>): number {
    const length = Math.min(a.length, b.length);
    if (!length) return 0;
    let sum = 0;
    for (let index = 0; index < length; index++) sum += Math.abs(a[index] - b[index]);
    return sum / length;
}

/** RGBA pixels to grey (one byte per pixel). */
export function toGrey(rgba: ArrayLike<number>): Uint8Array {
    const grey = new Uint8Array(Math.floor(rgba.length / 4));
    for (let index = 0; index < grey.length; index++) {
        const at = index * 4;
        grey[index] = (rgba[at] * 77 + rgba[at + 1] * 150 + rgba[at + 2] * 29) >> 8;
    }
    return grey;
}

/**
 * "Far above the usual": keeps a running mean and spread of a value and says when a new
 * value is a spike. Quiet for a while after each spike.
 */
export class SpikeWatch {
    private mean = 0;
    private variance = 0;
    private seen = 0;
    private quietUntil = 0;
    private readonly spreads: number;
    private readonly minimum: number;
    private readonly rate: number;
    private readonly warmup: number;
    private readonly cooldownMs: number;

    constructor(options: { spreads?: number; minimum?: number; rate?: number; warmup?: number; cooldownMs?: number } = {}) {
        this.spreads = options.spreads ?? DETECT.spreads;
        this.minimum = options.minimum ?? DETECT.minChange;
        this.rate = options.rate ?? DETECT.baselineRate;
        this.warmup = options.warmup ?? DETECT.warmupFrames;
        this.cooldownMs = options.cooldownMs ?? DETECT.cooldownMs;
    }

    /** Returns how strong the spike is (0–100), or 0 for none. */
    push(value: number, now: number): number {
        this.seen++;
        const spread = Math.sqrt(this.variance);
        const limit = Math.max(this.mean + this.spreads * Math.max(spread, 0.5), this.minimum);
        const hit = this.seen > this.warmup && now >= this.quietUntil && value >= limit;
        if (hit) {
            this.quietUntil = now + this.cooldownMs;
            return Math.min(100, Math.round(50 + (50 * (value - limit)) / Math.max(limit, 1)));
        }
        // A spike doesn't teach the baseline (or the next jump would look ordinary).
        if (value < limit || this.seen <= this.warmup) {
            const rate = this.seen <= this.warmup ? 1 / this.seen : this.rate;
            const delta = value - this.mean;
            this.mean += rate * delta;
            this.variance = (1 - rate) * (this.variance + rate * delta * delta);
        }
        return 0;
    }

    get usual(): { mean: number; spread: number } {
        return { mean: this.mean, spread: Math.sqrt(this.variance) };
    }
}

/** Counts rows of text (overview entries) in a grey picture. */
export function countRows(grey: ArrayLike<number>, width: number, height: number): number {
    let rows = 0;
    let run = 0;
    for (let y = 0; y < height; y++) {
        let busy = 0;
        for (let x = 1; x < width; x++) {
            if (Math.abs(grey[y * width + x] - grey[y * width + x - 1]) >= DETECT.rowContrast) busy++;
        }
        if (busy / Math.max(1, width - 1) >= DETECT.rowBusyShare) {
            run++;
        } else {
            if (run >= DETECT.minRowHeight) rows++;
            run = 0;
        }
    }
    if (run >= DETECT.minRowHeight) rows++;
    return rows;
}

/** A steady change in the row count: ships appeared (+) or vanished (−). */
export class RowWatch {
    private known: number | null = null;
    private candidate: number | null = null;
    private held = 0;
    private readonly steadyFrames: number;

    constructor(steadyFrames: number = DETECT.rowsSteadyFrames) {
        this.steadyFrames = steadyFrames;
    }

    /** Returns the change once a new count has held, else 0. */
    push(count: number): number {
        if (this.known === null) {
            if (count === this.candidate) this.held++;
            else {
                this.candidate = count;
                this.held = 1;
            }
            if (this.held >= this.steadyFrames) this.known = count;
            return 0;
        }
        if (count === this.known) {
            this.candidate = null;
            this.held = 0;
            return 0;
        }
        if (count === this.candidate) this.held++;
        else {
            this.candidate = count;
            this.held = 1;
        }
        if (this.held < this.steadyFrames) return 0;
        const change = count - this.known;
        this.known = count;
        this.candidate = null;
        this.held = 0;
        return change;
    }
}

/** How alike two sound prints are (cosine, 0–1). */
export function printLikeness(a: ArrayLike<number>, b: ArrayLike<number>): number {
    const length = Math.min(a.length, b.length);
    let dot = 0;
    let left = 0;
    let right = 0;
    for (let index = 0; index < length; index++) {
        dot += a[index] * b[index];
        left += a[index] * a[index];
        right += b[index] * b[index];
    }
    return left && right ? Math.max(0, dot / Math.sqrt(left * right)) : 0;
}

/**
 * A sound print: the analyser's spectrum (dB) folded into 32 bands, low sounds finer than
 * high ones (an activation is mostly low rumble), as loudness above the picture's middle band.
 */
export function soundPrint(spectrumDb: ArrayLike<number>, sampleRate: number, bands = 32): number[] {
    const binHz = sampleRate / 2 / spectrumDb.length;
    const low = 30;
    const high = Math.min(8000, sampleRate / 2);
    const print = new Array<number>(bands).fill(0);
    const counts = new Array<number>(bands).fill(0);
    for (let bin = 1; bin < spectrumDb.length; bin++) {
        const hz = bin * binHz;
        if (hz < low || hz > high) continue;
        const band = Math.min(bands - 1, Math.floor((Math.log(hz / low) / Math.log(high / low)) * bands));
        print[band] += Math.max(0, spectrumDb[bin] + 100);
        counts[band]++;
    }
    const levels = print.map((value, band) => (counts[band] ? value / counts[band] : 0));
    // Only what stands out from the steady background hum counts.
    const floor = [...levels].sort((a, b) => a - b)[Math.floor(levels.length / 2)];
    return levels.map((value) => Math.max(0, value - floor));
}

/** Average of several prints (a learned sound, or the moments after a loud start). */
export function averagePrint(prints: readonly (readonly number[])[]): number[] {
    if (!prints.length) return [];
    const result = new Array<number>(prints[0].length).fill(0);
    for (const print of prints) for (let index = 0; index < result.length; index++) result[index] += print[index] / prints.length;
    return result;
}
