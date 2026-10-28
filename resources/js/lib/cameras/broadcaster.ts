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
export type TDetectMode = 'off' | 'flash' | 'rows';
export type THit = { kind: 'activation' | 'appeared' | 'vanished'; strength: number; heardBy: 'picture' | 'sound' | 'both'; at: Date; still: Blob | null };

const BEAT_MS = 50;

/** One beat for every box on the page (a worker, so a background tab keeps time). */
export function startBeat(onBeat: (now: number) => void): () => void {
    let stop: () => void;
    try {
        const code = `let t=setInterval(()=>postMessage(0),${BEAT_MS});onmessage=()=>{clearInterval(t);close()}`;
        const url = URL.createObjectURL(new Blob([code], { type: 'text/javascript' }));
        const worker = new Worker(url);
        worker.onmessage = () => onBeat(performance.now());
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
    private previous: Uint8Array | null = null;
    private spike = new SpikeWatch();
    private rows = new RowWatch();
    private pending: THit | null = null;
    private pendingTimer: ReturnType<typeof setTimeout> | null = null;
    rect: TBoxRect;
    quality: TQuality;
    detect: TDetectMode;
    listen: boolean;
    paused = false;
    /** How much the picture is changing right now (for the meter), 0–100. */
    level = 0;
    onHit: (hit: THit) => void = () => undefined;
    onLost: () => void = () => undefined;

    constructor(source: HTMLVideoElement, rect: TBoxRect, options: { quality: TQuality; detect: TDetectMode; listen: boolean; audio: MediaStreamTrack | null }) {
        this.source = source;
        this.rect = rect;
        this.quality = options.quality;
        this.detect = options.detect;
        this.listen = options.listen;
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
        this.spike = new SpikeWatch();
        this.rows = new RowWatch();
        this.previous = null;
    }

    async start(relay: TRelayPass): Promise<void> {
        this.session?.stop();
        const quality = QUALITY[this.quality];
        this.session = await publish(relay, this.stream, { maxBitrate: quality.maxBitrate, maxFramerate: quality.fps }, () => this.onLost());
        this.recorder?.stop();
        this.recorder = new ClipRecorder(this.stream);
    }

    private crop(): [number, number, number, number] {
        const width = this.source.videoWidth;
        const height = this.source.videoHeight;
        return [this.rect.x * width, this.rect.y * height, Math.max(1, this.rect.w * width), Math.max(1, this.rect.h * height)];
    }

    private paint(now: number): void {
        if (this.source.videoWidth) this.draw.drawImage(this.source, ...this.crop(), 0, 0, this.canvas.width, this.canvas.height);
        this.track.requestFrame();
        this.lastFrame = now;
    }

    /** Every beat: send a frame when it's due, and look for activity. */
    beat(now: number, heard: boolean): void {
        if (this.paused || !this.source.videoWidth) return;
        if (now - this.lastFrame >= 1000 / QUALITY[this.quality].fps) this.paint(now);
        if (heard && this.listen) this.found({ kind: 'activation', strength: 70, heardBy: 'sound' });
        if (this.detect === 'off' || now - this.lastLook < 1000 / DETECT.fps) return;
        this.lastLook = now;
        const [, , , cropHeight] = this.crop();
        const width = DETECT.width;
        const height = this.detect === 'rows' ? Math.max(16, Math.min(256, Math.round(cropHeight / 4))) : DETECT.height;
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
            if (change > 0) this.found({ kind: 'appeared', strength: Math.min(100, change * 25), heardBy: 'picture' });
            if (change < 0) this.found({ kind: 'vanished', strength: Math.min(100, -change * 25), heardBy: 'picture' });
        }
        this.previous = grey;
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
        this.pending = { ...hit, at: new Date(), still: null };
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
