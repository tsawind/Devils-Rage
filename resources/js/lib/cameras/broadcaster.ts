import { ClipRecorder } from '@/lib/cameras/clipRecorder';
import { averagePrint, countRows, DETECT, frameChange, printLikeness, RowWatch, soundPrint, SpikeWatch, toGrey } from '@/lib/cameras/detector';
import { publish, QUALITY, type TQuality, type TRelayPass, type TRtcSession } from '@/lib/cameras/rtc';

/**
 * Patch 62: the sending side. One shared picture (an EVE window or the whole screen) and
 * any number of boxes on it; each box is its own camera: its part of the picture is drawn
 * onto a canvas and that canvas is streamed. The hole watch looks at the same part.
 *
 * Browsers slow down timers in tabs you aren't looking at (that's all the time here: you're
 * playing EVE), so the beat comes from a small worker, which isn't slowed.
 */
export type TBoxRect = { x: number; y: number; w: number; h: number };
/**
 * Patch 62f: what a box watches for. flash = the hole in space wobbles / flashes (a jump);
 * rows = ships on the overview; scan = probe scanner results; local = pilots in local.
 */
export type TDetectMode = 'off' | 'flash' | 'rows' | 'scan' | 'local';
export type THitKind = 'activation' | 'appeared' | 'vanished' | 'scan_new' | 'scan_gone' | 'local_in' | 'local_out';
export type THit = { kind: THitKind; strength: number; heardBy: 'picture' | 'sound' | 'both'; at: Date; still: Blob | null; playing?: boolean };

/** Row counting modes and what more / fewer rows means. */
const ROW_KINDS: Partial<Record<TDetectMode, [THitKind, THitKind]>> = {
    rows: ['appeared', 'vanished'],
    scan: ['scan_new', 'scan_gone'],
    local: ['local_in', 'local_out'],
};

/**
 * Patch 62l: strictness 1 (the slightest change counts) … 10 (only a clear one): how many
 * spreads above the usual a hole's wobble must be. (62f had it the other way round, as
 * "sensitivity", and its 10 was the jumpiest.)
 */
export function spreadsFor(strictness: number): number {
    return Math.round(2 + clampStrictness(strictness) * 1.2);
}

/** Patch 62l: and how many looks (8 a second) a new row count must hold on the overview, scanner or local. */
export function rowsFramesFor(strictness: number): number {
    return Math.max(6, DETECT.rowsSteadyFrames + (clampStrictness(strictness) - 5) * 2);
}

function clampStrictness(strictness: number): number {
    return Math.min(10, Math.max(1, Math.round(Number.isFinite(strictness) ? strictness : 10)));
}

/** The part of `mask` that falls inside `crop`, in the crop's own 0–1 coordinates (null: none). */
export function maskInCrop(mask: TBoxRect, crop: TBoxRect): TBoxRect | null {
    const left = Math.max(mask.x, crop.x);
    const top = Math.max(mask.y, crop.y);
    const right = Math.min(mask.x + mask.w, crop.x + crop.w);
    const bottom = Math.min(mask.y + mask.h, crop.y + crop.h);
    if (right <= left || bottom <= top || crop.w <= 0 || crop.h <= 0) return null;
    return { x: (left - crop.x) / crop.w, y: (top - crop.y) / crop.h, w: (right - left) / crop.w, h: (bottom - top) / crop.h };
}

const BEAT_MS = 50;

/** One beat for every box on the page (a worker, so a background tab keeps time). */
export function startBeat(onBeat: (now: number) => void): () => void {
    let stop: () => void;
    try {
        const code = `let t=setInterval(()=>postMessage(0),${BEAT_MS});onmessage=()=>{clearInterval(t);close()}`;
        const url = URL.createObjectURL(new Blob([code], { type: 'text/javascript' }));
        const worker = new Worker(url);
        // Patch 62i: when a beat runs long the worker's ticks queue up; run one and drop the
        // rest, or the page falls further behind until it hangs.
        let last = 0;
        worker.onmessage = () => {
            const now = performance.now();
            if (now - last < BEAT_MS * 0.6) return;
            onBeat(now);
            last = performance.now();
        };
        stop = () => {
            worker.postMessage(0);
            worker.terminate();
            URL.revokeObjectURL(url);
        };
    } catch {
        const timer = setInterval(() => onBeat(performance.now()), BEAT_MS);
        stop = () => clearInterval(timer);
    }
    return stop;
}

/** EVE time: UTC, 2026.10.29 04:27:13 like the game's own clock. */
export function stampTime(when: Date): string {
    const two = (value: number) => String(value).padStart(2, '0');
    return `${when.getUTCFullYear()}.${two(when.getUTCMonth() + 1)}.${two(when.getUTCDate())} ${two(when.getUTCHours())}:${two(when.getUTCMinutes())}:${two(when.getUTCSeconds())}`;
}

/** The size the box is sent at: its own size, shrunk to fit the quality, even numbers. */
export function sendSize(rect: TBoxRect, sourceWidth: number, sourceHeight: number, quality: TQuality): { width: number; height: number } {
    const width = Math.max(2, rect.w * sourceWidth);
    const height = Math.max(2, rect.h * sourceHeight);
    const limit = QUALITY[quality];
    const shrink = Math.min(1, limit.width / width, limit.height / height);
    const even = (value: number) => Math.max(2, Math.round((value * shrink) / 2) * 2);
    return { width: even(width), height: even(height) };
}

/**
 * Listens to the shared sound for the activation sound. It has to be taught once ("Learn"):
 * the next loud moment becomes the sound to match. Kept in this browser.
 */
export class SoundEar {
    private readonly ctx: AudioContext;
    private readonly analyser: AnalyserNode;
    private readonly spectrum: Float32Array<ArrayBuffer>;
    private readonly wave: Float32Array<ArrayBuffer>;
    private readonly loudness = new SpikeWatch({ spreads: DETECT.soundSpreads, minimum: 0.02, cooldownMs: 3000 });
    private collecting: number[][] | null = null;
    private learning = false;
    learned: number[] | null;
    onLearned: (() => void) | null = null;

    constructor(stream: MediaStream) {
        this.ctx = new AudioContext();
        this.analyser = this.ctx.createAnalyser();
        this.analyser.fftSize = DETECT.fftSize;
        this.ctx.createMediaStreamSource(stream).connect(this.analyser);
        this.spectrum = new Float32Array(this.analyser.frequencyBinCount);
        this.wave = new Float32Array(this.analyser.fftSize);
        this.learned = SoundEar.load();
    }

    static load(): number[] | null {
        try {
            const stored = JSON.parse(window.localStorage.getItem('camera-activation-sound') ?? 'null');
            return Array.isArray(stored) ? stored : null;
        } catch {
            return null;
        }
    }

    /** The next loud moment (within 30 s) is the activation sound. */
    learn(): void {
        this.learning = true;
        setTimeout(() => (this.learning = false), 30_000);
    }

    get isLearning(): boolean {
        return this.learning;
    }

    /** Call every beat; true when the activation sound was just heard. */
    listen(now: number): boolean {
        if (this.ctx.state === 'suspended') void this.ctx.resume();
        this.analyser.getFloatTimeDomainData(this.wave);
        let sum = 0;
        for (const sample of this.wave) sum += sample * sample;
        const rms = Math.sqrt(sum / this.wave.length);
        const onset = this.loudness.push(rms, now) > 0;
        if (onset && !this.collecting) this.collecting = [];
        if (!this.collecting) return false;
        this.analyser.getFloatFrequencyData(this.spectrum);
        this.collecting.push(soundPrint(this.spectrum, this.ctx.sampleRate));
        // About 0.6 s of sound after the start.
        if (this.collecting.length < 12) return false;
        const print = averagePrint(this.collecting);
        this.collecting = null;
        if (this.learning) {
            this.learning = false;
            this.learned = print;
            try {
                window.localStorage.setItem('camera-activation-sound', JSON.stringify(print));
            } catch {
                // Kept for this visit only.
            }
            this.onLearned?.();
            return false;
        }
        return this.learned !== null && printLikeness(this.learned, print) >= DETECT.soundMatch;
    }

    close(): void {
        void this.ctx.close();
    }
}

/** One box on the shared picture, streamed as its own camera. */
export class BoxCamera {
    private readonly source: HTMLVideoElement;
    private readonly canvas = document.createElement('canvas');
    private readonly draw: CanvasRenderingContext2D;
    private readonly look = document.createElement('canvas');
    private readonly lookDraw: CanvasRenderingContext2D;
    private readonly stream: MediaStream;
    private readonly track: CanvasCaptureMediaStreamTrack;
    private session: TRtcSession | null = null;
    private recorder: ClipRecorder | null = null;
    private lastFrame = 0;
    private lastLook = 0;
    private rowsQuietUntil = 0;
    private previous: Uint8Array | null = null;
    private spike = new SpikeWatch();
    private rows = new RowWatch();
    private pending: THit | null = null;
    private pendingTimer: ReturnType<typeof setTimeout> | null = null;
    rect: TBoxRect;
    quality: TQuality;
    detect: TDetectMode;
    listen: boolean;
    /** Patch 62f: hide areas (whole-picture 0–1 coordinates), blacked out of the picture, stills and clips. */
    masks: TBoxRect[] = [];
    paused = false;
    /** How much the picture is changing right now (for the meter), 0–100. */
    level = 0;
    onHit: (hit: THit) => void = () => undefined;
    onLost: () => void = () => undefined;
    /** Patch 62e: burned into the picture's corner with the EVE time ("V753 · Daisy → Alpha"), so clips say what and when. */
    label = '';

    private strictness: number;

    constructor(
        source: HTMLVideoElement,
        rect: TBoxRect,
        options: { quality: TQuality; detect: TDetectMode; listen: boolean; audio: MediaStreamTrack | null; strictness?: number },
    ) {
        this.source = source;
        this.rect = rect;
        this.quality = options.quality;
        this.detect = options.detect;
        this.listen = options.listen;
        this.strictness = options.strictness ?? 10;
        this.draw = this.canvas.getContext('2d', { alpha: false }) as CanvasRenderingContext2D;
        this.lookDraw = this.look.getContext('2d', { willReadFrequently: true }) as CanvasRenderingContext2D;
        this.resize();
        this.stream = this.canvas.captureStream(0);
        this.track = this.stream.getVideoTracks()[0] as CanvasCaptureMediaStreamTrack;
        if (options.audio) this.stream.addTrack(options.audio);
        this.paint(0);
    }

    /** Re-measure after the box or quality changed. */
    resize(): void {
        const size = sendSize(this.rect, this.source.videoWidth || 1920, this.source.videoHeight || 1080, this.quality);
        this.canvas.width = size.width;
        this.canvas.height = size.height;
        this.spike = new SpikeWatch({ spreads: spreadsFor(this.strictness) + (this.playing ? 3 : 0) });
        this.rows = new RowWatch(rowsFramesFor(this.strictness) * (this.playing ? 2 : 1));
        this.previous = null;
    }

    /** Patch 62f: a new strictness (1–10) starts the hole watch over with it. */
    setStrictness(strictness: number): void {
        if (strictness === this.strictness) return;
        this.strictness = strictness;
        this.spike = new SpikeWatch({ spreads: spreadsFor(strictness) + (this.playing ? 3 : 0) });
        this.rows.steadyFrames = rowsFramesFor(strictness) * (this.playing ? 2 : 1);
    }

    /**
     * Patch 62h: you're playing this client (the app says it's in front and you just used the
     * mouse or keys). Watching goes on, so a real jump or ship still counts, but it wants
     * stronger proof: your mouse over the overview and D-scan's flash come and go, a ship that
     * lands stays. Rows must hold twice as long, the hole a bit more, and a sound alone
     * (D-scan's) needs the picture to agree.
     */
    private playing = false;
    setPlaying(playing: boolean): void {
        if (playing === this.playing) return;
        this.playing = playing;
        this.rows.steadyFrames = rowsFramesFor(this.strictness) * (playing ? 2 : 1);
        this.spike.spreads = spreadsFor(this.strictness) + (playing ? 3 : 0);
    }

    get isPlaying(): boolean {
        return this.playing;
    }

    /**
     * Patch 62i: only a box that can see an activation or a pilot in local keeps clips (the
     * only hits that send one). Each clip recorder is two video encoders, so ships in / out
     * and probe results boxes skip them: with several windows they were what froze the PC.
     */
    get keepsClips(): boolean {
        return this.detect === 'flash' || this.detect === 'local' || this.listen;
    }

    /** Patch 62f: a watch area isn't streamed, but keeps the last moments for a clip. */
    record(): void {
        this.recorder?.stop();
        this.recorder = this.keepsClips ? new ClipRecorder(this.stream) : null;
    }

    async start(relay: TRelayPass): Promise<void> {
        this.session?.stop();
        const quality = QUALITY[this.quality];
        this.session = await publish(relay, this.stream, { maxBitrate: quality.maxBitrate, maxFramerate: quality.fps }, () => this.onLost());
        // Patch 62m: coming back from standby keeps the clip it was already recording.
        if (!this.recorder) this.record();
    }

    /**
     * Patch 62m: stop streaming but keep watching and recording here (nobody is watching it);
     * a clip still goes up when something happens.
     */
    standby(): void {
        this.session?.stop();
        this.session = null;
        if (!this.recorder) this.record();
    }

    get streaming(): boolean {
        return this.session !== null;
    }

    private crop(): [number, number, number, number] {
        const width = this.source.videoWidth;
        const height = this.source.videoHeight;
        return [this.rect.x * width, this.rect.y * height, Math.max(1, this.rect.w * width), Math.max(1, this.rect.h * height)];
    }

    private paint(now: number): void {
        if (this.source.videoWidth) this.draw.drawImage(this.source, ...this.crop(), 0, 0, this.canvas.width, this.canvas.height);
        this.hide();
        this.stamp();
        this.track.requestFrame();
        this.lastFrame = now;
    }

    /**
     * Every beat: send a frame when it's due, and look for activity. The sound is the whole
     * computer's (every client mixed), so with several boxes listening it can't say which hole:
     * then it only backs up what a box's picture saw ("both"); alone it counts by itself.
     */
    beat(now: number, heard: boolean, soundAlone = true): void {
        if (this.paused || !this.source.videoWidth) return;
        if (now - this.lastFrame >= 1000 / QUALITY[this.quality].fps) this.paint(now);
        if (heard && this.listen && ((soundAlone && !this.playing) || this.pending)) this.found({ kind: 'activation', strength: 70, heardBy: 'sound' });
        if (this.detect === 'off' || now - this.lastLook < 1000 / DETECT.fps) return;
        this.lastLook = now;
        const [, , , cropHeight] = this.crop();
        const width = DETECT.width;
        const height = this.detect === 'flash' ? DETECT.height : Math.max(16, Math.min(256, Math.round(cropHeight / 4)));
        if (this.look.width !== width || this.look.height !== height) {
            this.look.width = width;
            this.look.height = height;
            this.previous = null;
        }
        this.lookDraw.drawImage(this.source, ...this.crop(), 0, 0, width, height);
        const grey = toGrey(this.lookDraw.getImageData(0, 0, width, height).data);
        if (this.detect === 'flash') {
            const change = this.previous ? frameChange(grey, this.previous) : 0;
            this.level = Math.min(100, Math.round(change * 4));
            const strength = this.previous ? this.spike.push(change, now) : 0;
            if (strength) this.found({ kind: 'activation', strength, heardBy: 'picture' });
        } else {
            const change = this.rows.push(countRows(grey, width, height));
            this.level = 0;
            // Patch 62e: one report, then quiet a while (a fleet landing is one event, not ten).
            const [more, fewer]: [THitKind, THitKind] = ROW_KINDS[this.detect] ?? ['appeared', 'vanished'];
            if (change !== 0 && now >= this.rowsQuietUntil) {
                this.rowsQuietUntil = now + DETECT.rowsCooldownMs;
                // strength carries how many rows (25 a row), so the wall can say "+2".
                this.found({ kind: change > 0 ? more : fewer, strength: Math.min(100, Math.abs(change) * 25), heardBy: 'picture' });
            }
        }
        this.previous = grey;
    }

    /**
     * Patch 62d: the desktop app heard the activation sound in this box's own client (one
     * client's sound only, so it counts by itself).
     */
    hear(): void {
        // While you play the client, its sound alone (D-scan) only backs up what the picture saw.
        if (this.listen && !this.paused && (!this.playing || this.pending)) this.found({ kind: 'activation', strength: 75, heardBy: 'sound' });
    }

    /** Patch 62f: hide areas, solid black (blur can still be read). */
    private hide(): void {
        if (!this.masks.length) return;
        this.draw.fillStyle = '#000';
        for (const mask of this.masks) {
            const part = maskInCrop(mask, this.rect);
            if (part) this.draw.fillRect(part.x * this.canvas.width, part.y * this.canvas.height, Math.ceil(part.w * this.canvas.width), Math.ceil(part.h * this.canvas.height));
        }
    }

    /** The label and the EVE time (UTC) in the bottom-left corner, on a dark strip. */
    private stamp(): void {
        const time = `${stampTime(new Date())} EVE`;
        const text = this.label ? `${this.label} · ${time}` : time;
        const size = Math.max(11, Math.min(18, Math.round(this.canvas.height / 26)));
        this.draw.font = `600 ${size}px ui-monospace, Consolas, monospace`;
        const width = this.draw.measureText(text).width;
        const pad = Math.round(size * 0.4);
        this.draw.fillStyle = 'rgba(0, 0, 0, 0.6)';
        this.draw.fillRect(0, this.canvas.height - size - pad * 2, width + pad * 2, size + pad * 2);
        this.draw.fillStyle = '#f5d0fe';
        this.draw.textBaseline = 'bottom';
        this.draw.fillText(text, pad, this.canvas.height - pad);
    }

    /** Picture and sound within a moment of each other are one hit ("both"). */
    private found(hit: Omit<THit, 'at' | 'still'>): void {
        if (this.pending) {
            if (this.pending.kind === hit.kind && this.pending.heardBy !== hit.heardBy) {
                this.pending.heardBy = 'both';
                this.pending.strength = Math.max(this.pending.strength, hit.strength);
            }
            return;
        }
        this.pending = { ...hit, at: new Date(), still: null, playing: this.playing };
        const waiting = this.pending;
        this.canvas.toBlob(
            (blob) => {
                waiting.still = blob;
            },
            'image/jpeg',
            0.8,
        );
        this.pendingTimer = setTimeout(() => {
            const sent = this.pending;
            this.pending = null;
            if (sent) this.onHit(sent);
        }, 1200);
    }

    /** The clip around the last hit (a few seconds after it). */
    clip(): Promise<Blob | null> {
        return this.recorder?.cut() ?? Promise.resolve(null);
    }

    stop(): void {
        if (this.pendingTimer) clearTimeout(this.pendingTimer);
        this.recorder?.stop();
        this.recorder = null;
        this.session?.stop();
        this.session = null;
        this.track.stop();
    }
}
