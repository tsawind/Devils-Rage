import { playKillSound, soundOut } from '@/lib/rageSound';

/**
 * Patch 40: kill sounds that say how far away the kill is. Closer to home = higher, faster
 * and more pulses; each jump out goes lower and slower. Up to 3 jumps the sound is full
 * volume, then it gets a little quieter per jump (about two thirds at 12). The main chain
 * and side chains (unconnected chains, rage lanes) each pick their own sound family.
 * Patch 40b defaults: Sonar for home, Pluck for the main chain (one string per jump),
 * Klaxon for side chains (a high tone, then one lower tone per jump), side chains dimmer.
 * Everything is made in the browser (no audio files).
 */
export type TKillSoundFamily = 'alarm' | 'klaxon' | 'chime' | 'sonar' | 'drum' | 'pluck' | 'blip';

export const KILL_SOUND_FAMILIES: { value: TKillSoundFamily; label: string }[] = [
    { value: 'alarm', label: 'Alarm (sharp pulses)' },
    { value: 'klaxon', label: 'Klaxon (one tone per jump)' },
    { value: 'chime', label: 'Chime (bells)' },
    { value: 'sonar', label: 'Sonar (ping and echo)' },
    { value: 'drum', label: 'War drum' },
    { value: 'pluck', label: 'Pluck (one string per jump)' },
    { value: 'blip', label: 'Blip (soft)' },
];

export const MAX_SOUND_JUMPS = 12;

export type TSoundShape = { pitch: number; gap: number; pulses: number; volume: number };

/** Tone (pitch, Hz), tempo (seconds between pulses), pulses and volume for a kill `jumps` from home. */
export function distanceSoundShape(jumps: number): TSoundShape {
    const d = Math.min(Math.max(Math.round(jumps), 0), MAX_SOUND_JUMPS);
    return {
        // Two octaves down over 12 jumps: 880 Hz at home, 220 Hz at 12.
        pitch: Math.round(880 * Math.pow(2, -d / 6)),
        // 0.09 s between pulses at home, 0.35 s at 12.
        gap: Math.round((0.09 + (d / MAX_SOUND_JUMPS) * 0.26) * 1000) / 1000,
        pulses: d <= 1 ? 5 : d <= 3 ? 4 : d <= 6 ? 3 : 2,
        volume: d <= 3 ? 1 : Math.round((1 - (d - 3) * 0.04) * 100) / 100,
    };
}

type TOut = NonNullable<ReturnType<typeof soundOut>>;

function tone(out: TOut, type: OscillatorType, frequency: number, start: number, length: number, peak: number, bendTo?: number): void {
    const { ctx, master } = out;
    const oscillator = ctx.createOscillator();
    const gain = ctx.createGain();
    oscillator.type = type;
    oscillator.frequency.setValueAtTime(frequency, start);
    if (bendTo) oscillator.frequency.exponentialRampToValueAtTime(bendTo, start + length);
    gain.gain.setValueAtTime(0.0001, start);
    gain.gain.exponentialRampToValueAtTime(peak, start + Math.min(0.012, length / 4));
    gain.gain.exponentialRampToValueAtTime(0.0001, start + length);
    oscillator.connect(gain).connect(master);
    oscillator.start(start);
    oscillator.stop(start + length + 0.02);
}

/** Patch 40b: the counted sounds play one string / one second tone per jump (at least one). */
export function countedNotes(jumps: number): number {
    return Math.min(Math.max(Math.round(jumps), 1), MAX_SOUND_JUMPS);
}

const FAMILIES: Record<TKillSoundFamily, (out: TOut, shape: TSoundShape, jumps: number) => void> = {
    // Sharp square pulses bending down a little: urgent close to home.
    alarm: (out, { pitch, gap, pulses }) => {
        for (let i = 0; i < pulses; i++) tone(out, 'square', pitch, out.t + i * gap, Math.min(0.08, gap * 0.8), 0.14, pitch * 0.85);
    },
    // Patch 40b: one high tone, then one lower tone per jump (counted), evenly spaced.
    klaxon: (out, { pitch }, jumps) => {
        tone(out, 'sawtooth', pitch, out.t, 0.28, 0.09);
        const count = jumps > 0 ? countedNotes(jumps) : 0;
        for (let i = 0; i < count; i++) tone(out, 'sawtooth', pitch * 0.84, out.t + 0.36 + i * 0.24, 0.18, 0.08);
    },
    // Bells: a pure tone with a ringing partial, stepping up a third each strike.
    chime: (out, { pitch, gap, pulses }) => {
        for (let i = 0; i < pulses; i++) {
            const start = out.t + i * gap * 1.5;
            const frequency = pitch * Math.pow(1.26, i % 3);
            tone(out, 'sine', frequency, start, 0.9, 0.22);
            tone(out, 'sine', frequency * 2.76, start, 0.35, 0.05);
        }
    },
    // A ping with fading echoes (the gap sets how fast the echoes come back).
    sonar: (out, { pitch, gap, pulses }) => {
        for (let i = 0; i < pulses; i++) tone(out, 'sine', pitch, out.t + i * gap * 2, 0.5, 0.3 * Math.pow(0.55, i), pitch * 0.97);
    },
    // Low tom thumps, pitch falling: tempo is the drum roll's speed.
    drum: (out, { pitch, gap, pulses }) => {
        for (let i = 0; i < pulses; i++) tone(out, 'sine', pitch / 3, out.t + i * gap * 1.2, 0.3, i === 0 ? 0.6 : 0.45, pitch / 8);
    },
    // Patch 40b: one string per jump (counted), evenly spaced, a light strum between two notes.
    pluck: (out, { pitch }, jumps) => {
        const count = countedNotes(jumps);
        const gap = 0.2 + Math.min(jumps, MAX_SOUND_JUMPS) * 0.006;
        for (let i = 0; i < count; i++) tone(out, 'triangle', i % 2 ? pitch * 1.122 : pitch, out.t + i * gap, 0.26, 0.26);
    },
    // Short soft sine blips: the quiet option.
    blip: (out, { pitch, gap, pulses }) => {
        for (let i = 0; i < pulses; i++) tone(out, 'sine', pitch, out.t + i * gap, 0.06, 0.18);
    },
};

/** Play a kill `jumps` from home in a sound family; a capital adds the heavy boom under it. `dim` turns it down (side chains). */
export function playDistanceKill(jumps: number, family: TKillSoundFamily, capital = false, dim = 1): void {
    const shape = distanceSoundShape(jumps);
    const volume = shape.volume * dim;
    const out = soundOut(volume * 0.8);
    if (!out) return;
    FAMILIES[family](out, shape, jumps);
    // The kill itself, quieter, under the pattern (full boom for a capital).
    playKillSound(capital, (capital ? 0.4 : 0.2) * volume);
}

/** Patch 40b: side chains sound a little dimmer than the main chain. */
export const SIDE_CHAIN_DIM = 0.75;

export type TKillSoundChoice = { home: TKillSoundFamily; main: TKillSoundFamily; side: TKillSoundFamily };

/** The sound for a kill: home (0 jumps, main chain), the main chain, or a side chain (dimmer). */
export function killSoundFor(place: { jumps: number; side: boolean }, choice: TKillSoundChoice): { family: TKillSoundFamily; dim: number } {
    if (place.side) return { family: choice.side, dim: SIDE_CHAIN_DIM };
    return { family: place.jumps === 0 ? choice.home : choice.main, dim: 1 };
}

export function playKillAt(place: { jumps: number; side: boolean }, choice: TKillSoundChoice, capital = false): void {
    const { family, dim } = killSoundFor(place, choice);
    playDistanceKill(place.jumps, family, capital, dim);
}
