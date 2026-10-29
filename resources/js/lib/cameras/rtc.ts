/**
 * Patch 62: talking to the video relay with plain browser WebRTC. Publish is WHIP, watch is
 * WHEP: one POST of an SDP offer, the answer comes back, and video flows. The mapper hands
 * out the short pass; the relay asks the mapper whether it's good.
 */
export type TRelayPass = { url: string; pass: string };

export type TRtcSession = {
    pc: RTCPeerConnection;
    stop: () => void;
};

/** Waits for the browser to find its network addresses (or 2 s, whichever is first). */
function gathered(pc: RTCPeerConnection, timeoutMs = 2000): Promise<void> {
    if (pc.iceGatheringState === 'complete') return Promise.resolve();
    return new Promise((resolve) => {
        const done = () => {
            pc.removeEventListener('icegatheringstatechange', check);
            clearTimeout(timer);
            resolve();
        };
        const check = () => {
            if (pc.iceGatheringState === 'complete') done();
        };
        const timer = setTimeout(done, timeoutMs);
        pc.addEventListener('icegatheringstatechange', check);
    });
}

async function exchange(pc: RTCPeerConnection, relay: TRelayPass): Promise<void> {
    await pc.setLocalDescription(await pc.createOffer());
    await gathered(pc);
    const url = `${relay.url}${relay.url.includes('?') ? '&' : '?'}pass=${encodeURIComponent(relay.pass)}`;
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/sdp', Authorization: `Bearer ${relay.pass}` },
        body: pc.localDescription?.sdp ?? '',
    });
    if (!response.ok) throw new Error(response.status === 401 ? 'The relay refused the pass' : `The relay answered ${response.status}`);
    await pc.setRemoteDescription({ type: 'answer', sdp: await response.text() });
}

/** Calls back once when the link drops for good (failed, or disconnected for 8 s). */
function onLost(pc: RTCPeerConnection, lost: () => void): void {
    let timer: ReturnType<typeof setTimeout> | null = null;
    let told = false;
    const tell = () => {
        if (told) return;
        told = true;
        lost();
    };
    pc.addEventListener('connectionstatechange', () => {
        if (timer) clearTimeout(timer);
        timer = null;
        if (pc.connectionState === 'failed') tell();
        else if (pc.connectionState === 'disconnected') timer = setTimeout(tell, 8000);
    });
}

/** Sends a stream to the relay. */
export async function publish(relay: TRelayPass, stream: MediaStream, options: { maxBitrate: number; maxFramerate: number }, lost: () => void): Promise<TRtcSession> {
    const pc = new RTCPeerConnection();
    for (const track of stream.getTracks()) {
        const encodings = track.kind === 'video' ? [{ maxBitrate: options.maxBitrate, maxFramerate: options.maxFramerate }] : undefined;
        pc.addTransceiver(track, { direction: 'sendonly', streams: [stream], sendEncodings: encodings });
    }
    onLost(pc, lost);
    try {
        await exchange(pc, relay);
    } catch (error) {
        pc.close();
        throw error;
    }
    return { pc, stop: () => pc.close() };
}

/** Receives a camera from the relay. */
export async function watch(relay: TRelayPass, onStream: (stream: MediaStream) => void, lost: () => void): Promise<TRtcSession> {
    const pc = new RTCPeerConnection();
    pc.addTransceiver('video', { direction: 'recvonly' });
    pc.addTransceiver('audio', { direction: 'recvonly' });
    const stream = new MediaStream();
    pc.addEventListener('track', (event) => {
        stream.addTrack(event.track);
        onStream(stream);
    });
    onLost(pc, lost);
    try {
        await exchange(pc, relay);
    } catch (error) {
        pc.close();
        throw error;
    }
    return { pc, stop: () => pc.close() };
}

/** Bits per second and frames per second for each quality (kept low: EVE is mostly still). */
export const QUALITY = {
    // Patch 62m: the lightest, for a whole client you only glance at (~0.25 Mbit/s).
    '480p2': { width: 854, height: 480, fps: 2, maxBitrate: 250_000, label: '480p · 2 fps (lightest)' },
    '720p5': { width: 1280, height: 720, fps: 5, maxBitrate: 700_000, label: '720p · 5 fps (light)' },
    '1080p10': { width: 1920, height: 1080, fps: 10, maxBitrate: 1_800_000, label: '1080p · 10 fps' },
    '1080p30': { width: 1920, height: 1080, fps: 30, maxBitrate: 4_000_000, label: '1080p · 30 fps (heavy)' },
} as const;

export type TQuality = keyof typeof QUALITY;
