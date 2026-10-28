/**
 * Patch 62: the last ~10–20 s of a camera, kept in the browser so a hole event comes with a
 * short clip. Two recorders take turns (each restarts every 20 s, half a turn apart); when
 * something happens the older one is stopped a few seconds later, so the clip holds the
 * moments before and after. Nothing is kept unless something happens.
 */
export const CLIP = {
    segmentMs: 20_000,
    /** Keep recording this long after the event before cutting the clip. */
    afterMs: 5_000,
    bitsPerSecond: 600_000,
} as const;

type TRecorderSlot = { recorder: MediaRecorder; started: number; chunks: Blob[]; restart: ReturnType<typeof setTimeout> | null };

function mimeType(): string {
    for (const type of ['video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm']) {
        if (typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported(type)) return type;
    }
    return '';
}

/** Which slot to cut: the one that has been recording longest. */
export function oldestSlot<T extends { started: number }>(slots: readonly T[]): T | null {
    return slots.reduce<T | null>((best, slot) => (best === null || slot.started < best.started ? slot : best), null);
}

export class ClipRecorder {
    private readonly stream: MediaStream;
    private readonly slots: TRecorderSlot[] = [];
    private stopped = false;

    constructor(stream: MediaStream) {
        this.stream = stream;
        if (typeof MediaRecorder === 'undefined' || !mimeType()) return;
        this.slots.push(this.startSlot(), this.startSlot());
        // The second one turns over half a turn later.
        const second = this.slots[1];
        if (second.restart) clearTimeout(second.restart);
        second.restart = setTimeout(() => this.turnOver(second), CLIP.segmentMs / 2);
    }

    get available(): boolean {
        return this.slots.length > 0;
    }

    private startSlot(): TRecorderSlot {
        const recorder = new MediaRecorder(this.stream, { mimeType: mimeType(), videoBitsPerSecond: CLIP.bitsPerSecond });
        const slot: TRecorderSlot = { recorder, started: Date.now(), chunks: [], restart: null };
        recorder.addEventListener('dataavailable', (event) => {
            if (event.data.size) slot.chunks.push(event.data);
        });
        recorder.start();
        slot.restart = setTimeout(() => this.turnOver(slot), CLIP.segmentMs);
        return slot;
    }

    private replace(slot: TRecorderSlot): void {
        const index = this.slots.indexOf(slot);
        if (index === -1 || this.stopped) return;
        this.slots[index] = this.startSlot();
    }

    /** Throw the old recording away and start again. */
    private turnOver(slot: TRecorderSlot): void {
        if (slot.restart) clearTimeout(slot.restart);
        slot.recorder.addEventListener('stop', () => (slot.chunks = []), { once: true });
        if (slot.recorder.state !== 'inactive') slot.recorder.stop();
        this.replace(slot);
    }

    /** The clip around now: resolves a few seconds later (null when recording isn't possible). */
    cut(): Promise<Blob | null> {
        if (!this.slots.length) return Promise.resolve(null);
        return new Promise((resolve) => {
            setTimeout(() => {
                const slot = oldestSlot(this.slots);
                if (!slot || slot.recorder.state === 'inactive') {
                    resolve(null);
                    return;
                }
                if (slot.restart) clearTimeout(slot.restart);
                slot.recorder.addEventListener(
                    'stop',
                    () => resolve(slot.chunks.length ? new Blob(slot.chunks, { type: 'video/webm' }) : null),
                    { once: true },
                );
                slot.recorder.stop();
                this.replace(slot);
            }, CLIP.afterMs);
        });
    }

    stop(): void {
        this.stopped = true;
        for (const slot of this.slots) {
            if (slot.restart) clearTimeout(slot.restart);
            if (slot.recorder.state !== 'inactive') slot.recorder.stop();
        }
        this.slots.length = 0;
    }
}
