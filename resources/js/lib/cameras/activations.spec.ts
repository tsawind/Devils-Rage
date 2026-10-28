import { activityByConnection, activityBySignature, activityLabel, addActivation, HOLD_CLOAK_SECONDS, liveActivations, secondsLeft, type TActivation } from '@/lib/cameras/activations';
import { describe, expect, it } from 'vitest';

const now = Date.parse('2026-10-28T20:00:00Z');
const at = (secondsAgo: number, extra: Partial<TActivation> = {}): TActivation => ({
    eventId: Math.round(secondsAgo * 1000) + (extra.connectionId ?? 7),
    cameraId: 1,
    cameraName: 'Daisy hole',
    connectionId: 7,
    solarsystemId: null,
    kind: 'activation',
    at: now - secondsAgo * 1000,
    heardBy: 'picture',
    ...extra,
});

describe('hole activations on the map', () => {
    it('counts the cloak down from 60 s', () => {
        expect(secondsLeft(now, now)).toBe(HOLD_CLOAK_SECONDS);
        expect(secondsLeft(now - 17_500, now)).toBe(43);
        expect(secondsLeft(now - 61_000, now)).toBe(0);
    });

    it('keeps only activations inside their minute, newest first', () => {
        const list = [at(70), at(5), at(30), at(10, { kind: 'appeared' })];
        expect(liveActivations(list, now).map((item) => item.at)).toEqual([now - 5000, now - 30_000]);
    });

    it('stacks several on one pipe and runs the cloak from the newest', () => {
        const pipes = activityByConnection([at(40), at(12, { cameraName: 'Alt 2' }), at(3, { connectionId: 9 })], now);
        const pipe = pipes.get(7)!;
        expect(pipe.count).toBe(2);
        expect(pipe.secondsLeft).toBe(48);
        expect(pipe.cameras).toEqual(['Alt 2', 'Daisy hole']);
        expect(activityLabel(pipe)).toBe('Activation ×2 · cloak 48s');
        expect(activityLabel(pipes.get(9)!)).toBe('Activation · cloak 57s');
    });

    it('a camera clock a little ahead counts as now', () => {
        expect(activityByConnection([at(-5)], now).get(7)?.secondsLeft).toBe(60);
    });

    it('a hole nobody has jumped yet gets its own pill, by signature', () => {
        const list = [at(4, { connectionId: null, signatureId: 55 })];
        expect(activityBySignature(list, now).get(55)?.count).toBe(1);
        expect(activityByConnection(list, now).size).toBe(0);
    });

    it('ignores the same event twice and cameras not on a pipe', () => {
        const one = at(2);
        expect(addActivation([one], one, now)).toHaveLength(1);
        expect(activityByConnection([at(2, { connectionId: null })], now).size).toBe(0);
    });
});
