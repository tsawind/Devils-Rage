/**
 * Patch 62: hole activity a camera saw, as the map shows it. An activation pops a purple
 * "Activation" pill on its pipe for a minute: everyone can hold a jump cloak for 60 s, so
 * the countdown runs from the newest activation. Several in that minute stack as ×N.
 */
export const HOLD_CLOAK_SECONDS = 60;

export type TCameraActivityKind = 'activation' | 'appeared' | 'vanished';

export type TActivation = {
    eventId: number;
    cameraId: number;
    cameraName: string;
    connectionId: number | null;
    /** Patch 62b: a hole nobody has jumped yet (its signature; the dotted pipe). */
    signatureId?: number | null;
    solarsystemId: number | null;
    kind: TCameraActivityKind;
    /** When it happened (ms since epoch, the camera's clock as the mapper stored it). */
    at: number;
    heardBy: 'picture' | 'sound' | 'both';
};

export type TPipeActivity = {
    /** Activations in the last minute. */
    count: number;
    newest: TActivation;
    /** Whole seconds left on the jump cloak of the newest activation. */
    secondsLeft: number;
    /** The cameras that saw them (names, no repeats). */
    cameras: string[];
};

export function secondsLeft(at: number, now: number): number {
    return Math.max(0, Math.ceil(HOLD_CLOAK_SECONDS - (now - at) / 1000));
}

/** Activations still inside their minute (newest first). Future times (clock skew) count as now. */
export function liveActivations(list: readonly TActivation[], now: number): TActivation[] {
    return list
        .filter((item) => item.kind === 'activation' && now - Math.min(item.at, now) < HOLD_CLOAK_SECONDS * 1000)
        .sort((a, b) => b.at - a.at);
}

/** The purple pill per pipe: how many, the newest, and the cloak countdown. */
export function activityByConnection(list: readonly TActivation[], now: number): Map<number, TPipeActivity> {
    return activityBy(list, now, (item) => item.connectionId);
}

/** Patch 62b: the same for dotted pipes (holes nobody has jumped yet), by signature. */
export function activityBySignature(list: readonly TActivation[], now: number): Map<number, TPipeActivity> {
    return activityBy(list, now, (item) => (item.connectionId === null ? (item.signatureId ?? null) : null));
}

function activityBy(list: readonly TActivation[], now: number, keyOf: (item: TActivation) => number | null): Map<number, TPipeActivity> {
    const result = new Map<number, TPipeActivity>();
    for (const item of liveActivations(list, now)) {
        const key = keyOf(item);
        if (key === null) continue;
        const known = result.get(key);
        if (known) {
            known.count++;
            if (!known.cameras.includes(item.cameraName)) known.cameras.push(item.cameraName);
            continue;
        }
        result.set(key, { count: 1, newest: item, secondsLeft: secondsLeft(Math.min(item.at, now), now), cameras: [item.cameraName] });
    }
    return result;
}

/** Adds an activation, ignoring a repeat of the same event, and drops ones older than a day. */
export function addActivation(list: readonly TActivation[], item: TActivation, now: number): TActivation[] {
    if (list.some((known) => known.eventId === item.eventId)) return [...list];
    return [...list.filter((known) => now - known.at < 86_400_000), item];
}

/** "Activation ×3 · cloak 42s" */
export function activityLabel(activity: TPipeActivity): string {
    const count = activity.count > 1 ? ` ×${activity.count}` : '';
    return activity.secondsLeft > 0 ? `Activation${count} · cloak ${activity.secondsLeft}s` : `Activation${count}`;
}
