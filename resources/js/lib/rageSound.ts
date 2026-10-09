/**
 * Patch 35: the "target hit" sound, made in the browser (no audio file): an
 * original, devilish "down the pipe" drop. Three quick falling blips a tritone
 * apart (the devil's interval), a distorted growl sliding down an octave, and a
 * low tolling bell. Browsers only allow sound after someone has clicked the page,
 * so the audio is unlocked on the first click or key press.
 */

let context: AudioContext | null = null;

function audio(): AudioContext | null {
    if (typeof window === 'undefined') return null;
    const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
    if (!Ctor) return null;
    context ??= new Ctor();
    return context;
}

/** Call once on the map page: the first click / key press unlocks audio for the hit sound. */
export function unlockRageSound(): () => void {
    if (typeof window === 'undefined') return () => undefined;
    const unlock = () => {
        audio()
            ?.resume()
            .catch(() => undefined);
    };
    window.addEventListener('pointerdown', unlock, { once: true, capture: true });
    window.addEventListener('keydown', unlock, { once: true, capture: true });
    return () => {
        window.removeEventListener('pointerdown', unlock, { capture: true });
        window.removeEventListener('keydown', unlock, { capture: true });
    };
}

function distortionCurve(amount: number): Float32Array<ArrayBuffer> {
    const samples = 1024;
    const curve = new Float32Array(new ArrayBuffer(samples * 4));
    for (let index = 0; index < samples; index++) {
        const x = (index * 2) / samples - 1;
        curve[index] = ((3 + amount) * x * 20 * (Math.PI / 180)) / (Math.PI + amount * Math.abs(x));
    }
    return curve;
}

/** Play the hit sound now (does nothing if the browser has no audio or it is still locked). */
export function playRageHitSound(volume = 0.5): void {
    const ctx = audio();
    if (!ctx) return;
    if (ctx.state === 'suspended') {
        ctx.resume().catch(() => undefined);
    }

    const t = ctx.currentTime + 0.02;
    const master = ctx.createGain();
    master.gain.value = Math.min(Math.max(volume, 0), 1);
    master.connect(ctx.destination);

    // 1. Three falling blips, each a tritone below the last, each bending down as it plays.
    const blips = [622.25, 440, 311.13];
    blips.forEach((frequency, index) => {
        const start = t + index * 0.11;
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();
        oscillator.type = 'square';
        oscillator.frequency.setValueAtTime(frequency, start);
        oscillator.frequency.exponentialRampToValueAtTime(frequency * 0.7, start + 0.1);
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(0.18, start + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.1);
        oscillator.connect(gain).connect(master);
        oscillator.start(start);
        oscillator.stop(start + 0.12);
    });

    // 2. The drop: a distorted sawtooth growl sliding down an octave into the pipe.
    const dropStart = t + 0.36;
    const growl = ctx.createOscillator();
    const growlLow = ctx.createOscillator();
    const shaper = ctx.createWaveShaper();
    const filter = ctx.createBiquadFilter();
    const growlGain = ctx.createGain();
    growl.type = 'sawtooth';
    growlLow.type = 'sawtooth';
    growl.frequency.setValueAtTime(220, dropStart);
    growl.frequency.exponentialRampToValueAtTime(55, dropStart + 0.9);
    growlLow.frequency.setValueAtTime(155.56, dropStart);
    growlLow.frequency.exponentialRampToValueAtTime(38.89, dropStart + 0.9);
    shaper.curve = distortionCurve(60);
    filter.type = 'lowpass';
    filter.frequency.setValueAtTime(2400, dropStart);
    filter.frequency.exponentialRampToValueAtTime(260, dropStart + 0.9);
    growlGain.gain.setValueAtTime(0.0001, dropStart);
    growlGain.gain.exponentialRampToValueAtTime(0.22, dropStart + 0.05);
    growlGain.gain.exponentialRampToValueAtTime(0.0001, dropStart + 1.0);
    growl.connect(shaper);
    growlLow.connect(shaper);
    shaper.connect(filter).connect(growlGain).connect(master);
    growl.start(dropStart);
    growlLow.start(dropStart);
    growl.stop(dropStart + 1.05);
    growlLow.stop(dropStart + 1.05);

    // 3. A low bell tolling a tritone (A2 and D#3), fading out.
    const bellStart = dropStart + 0.85;
    [110, 155.56, 220.5].forEach((frequency, index) => {
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.value = frequency;
        gain.gain.setValueAtTime(0.0001, bellStart);
        gain.gain.exponentialRampToValueAtTime(index === 0 ? 0.3 : 0.12, bellStart + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, bellStart + 2.2);
        oscillator.connect(gain).connect(master);
        oscillator.start(bellStart);
        oscillator.stop(bellStart + 2.3);
    });
}

/**
 * Patch 37: a kill on a map system: a sharp crack, a burst of noise like debris, and a
 * short low boom; a capital adds a deep, longer rumble under it. Made in the browser.
 */
export function playKillSound(heavy = false, volume = 0.45): void {
    const ctx = audio();
    if (!ctx) return;
    if (ctx.state === 'suspended') {
        ctx.resume().catch(() => undefined);
    }
    const t = ctx.currentTime + 0.02;
    const master = ctx.createGain();
    master.gain.value = Math.min(Math.max(volume, 0), 1);
    master.connect(ctx.destination);

    // 1. The crack and debris: a short burst of filtered noise.
    const seconds = heavy ? 1.6 : 0.9;
    const buffer = ctx.createBuffer(1, Math.floor(ctx.sampleRate * seconds), ctx.sampleRate);
    const samples = buffer.getChannelData(0);
    for (let i = 0; i < samples.length; i++) samples[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / samples.length, heavy ? 2 : 3);
    const noise = ctx.createBufferSource();
    noise.buffer = buffer;
    const filter = ctx.createBiquadFilter();
    filter.type = 'lowpass';
    filter.frequency.setValueAtTime(heavy ? 2400 : 3600, t);
    filter.frequency.exponentialRampToValueAtTime(heavy ? 120 : 300, t + seconds);
    const noiseGain = ctx.createGain();
    noiseGain.gain.setValueAtTime(0.9, t);
    noiseGain.gain.exponentialRampToValueAtTime(0.001, t + seconds);
    noise.connect(filter).connect(noiseGain).connect(master);
    noise.start(t);
    noise.stop(t + seconds);

    // 2. The boom: a sine dropping fast (lower and longer for a capital).
    const boom = ctx.createOscillator();
    boom.type = 'sine';
    boom.frequency.setValueAtTime(heavy ? 90 : 140, t);
    boom.frequency.exponentialRampToValueAtTime(heavy ? 28 : 45, t + (heavy ? 1.4 : 0.5));
    const boomGain = ctx.createGain();
    boomGain.gain.setValueAtTime(heavy ? 1 : 0.8, t);
    boomGain.gain.exponentialRampToValueAtTime(0.001, t + (heavy ? 1.8 : 0.7));
    boom.connect(boomGain).connect(master);
    boom.start(t);
    boom.stop(t + (heavy ? 1.9 : 0.8));

    // 3. Capitals: a second, later rumble a tritone below, through distortion.
    if (heavy) {
        const rumble = ctx.createOscillator();
        rumble.type = 'sawtooth';
        rumble.frequency.setValueAtTime(55, t + 0.25);
        rumble.frequency.exponentialRampToValueAtTime(38.9, t + 2.2);
        const shaper = ctx.createWaveShaper();
        shaper.curve = distortionCurve(40);
        const rumbleFilter = ctx.createBiquadFilter();
        rumbleFilter.type = 'lowpass';
        rumbleFilter.frequency.value = 220;
        const rumbleGain = ctx.createGain();
        rumbleGain.gain.setValueAtTime(0.0001, t + 0.25);
        rumbleGain.gain.exponentialRampToValueAtTime(0.5, t + 0.45);
        rumbleGain.gain.exponentialRampToValueAtTime(0.001, t + 2.4);
        rumble.connect(shaper).connect(rumbleFilter).connect(rumbleGain).connect(master);
        rumble.start(t + 0.25);
        rumble.stop(t + 2.5);
    }
}
