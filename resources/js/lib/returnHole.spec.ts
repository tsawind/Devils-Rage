import { decideReturnHole, distanceFromScanRow, orderOpenConnections, parseScanDistance } from '@/lib/returnHole';
import { describe, expect, it } from 'vitest';

describe('parseScanDistance', () => {
    it('reads on-grid distances in m and km', () => {
        expect(parseScanDistance('2,600 m')).toEqual({ meters: 2600, onGrid: true, text: '2,600 m' });
        expect(parseScanDistance('505 km')?.meters).toBe(505_000);
        expect(parseScanDistance('5,466 m')?.onGrid).toBe(true);
        expect(parseScanDistance('5.5 km')?.meters).toBe(5500);
        expect(parseScanDistance('2 600 m')?.meters).toBe(2600);
        expect(parseScanDistance('2 600 m')?.meters).toBe(2600);
    });

    it('reads off-grid distances in AU', () => {
        const distance = parseScanDistance('12.56 AU');
        expect(distance?.onGrid).toBe(false);
        expect(Math.round((distance?.meters ?? 0) / 1e9)).toBe(1879);
        expect(parseScanDistance('0.46 AU')?.onGrid).toBe(false);
        expect(parseScanDistance('12,56 AU')?.meters).toBe(parseScanDistance('12.56 AU')?.meters);
    });

    it('reads European formats', () => {
        expect(parseScanDistance('1.234,5 km')?.meters).toBe(1_234_500);
    });

    it('ignores anything that is not a distance', () => {
        expect(parseScanDistance('100.0%')).toBeNull();
        expect(parseScanDistance('-')).toBeNull();
        expect(parseScanDistance('Unstable Wormhole')).toBeNull();
        expect(parseScanDistance('')).toBeNull();
    });
});

describe('distanceFromScanRow', () => {
    it('finds the distance column in a pasted scanner row', () => {
        const row = ['HZD-375', 'Cosmic Signature', 'Wormhole', 'Unstable Wormhole', '100.0%', '2,600 m'];
        expect(distanceFromScanRow(row.slice(4))?.meters).toBe(2600);
        expect(distanceFromScanRow(['0.0%', '12.61 AU'])?.onGrid).toBe(false);
        expect(distanceFromScanRow(['0.0%'])).toBeNull();
    });
});

describe('decideReturnHole', () => {
    const grid = (meters: number) => ({ meters, onGrid: true, text: `${meters} m` });
    const au = (value: number) => ({ meters: value * 1.5e11, onGrid: false, text: `${value} AU` });
    const now = 1_000_000;

    it('links automatically within 30 s of your jump when exactly one wormhole is on grid', () => {
        const decision = decideReturnHole({ candidates: [{ id: 1, distance: au(9) }, { id: 2, distance: grid(2600) }], jumpedAt: now - 20_000, now });
        expect(decision).toEqual({ mode: 'auto', candidateId: 2 });
    });

    it('asks after 30 s, preselecting the on-grid hole', () => {
        const decision = decideReturnHole({ candidates: [{ id: 1, distance: au(9) }, { id: 2, distance: grid(2600) }], jumpedAt: now - 31_000, now });
        expect(decision).toEqual({ mode: 'ask', preselectId: 2, ordered: [2, 1] });
    });

    it('asks when you did not jump here yourself', () => {
        const decision = decideReturnHole({ candidates: [{ id: 2, distance: grid(2600) }], jumpedAt: null, now });
        expect(decision.mode).toBe('ask');
    });

    it('asks, nearest first and nothing preselected, when everything is in AU', () => {
        const decision = decideReturnHole({ candidates: [{ id: 1, distance: au(9) }, { id: 2, distance: au(0.4) }, { id: 3 }], jumpedAt: now - 5000, now });
        expect(decision).toEqual({ mode: 'ask', preselectId: null, ordered: [2, 1, 3] });
    });

    it('asks, preselecting the closest, when more than one wormhole is on grid', () => {
        const decision = decideReturnHole({ candidates: [{ id: 1, distance: grid(8000) }, { id: 2, distance: grid(2600) }], jumpedAt: now - 5000, now });
        expect(decision).toEqual({ mode: 'ask', preselectId: 2, ordered: [2, 1] });
    });

    it('does nothing without wormholes', () => {
        expect(decideReturnHole({ candidates: [], jumpedAt: now, now })).toEqual({ mode: 'none' });
    });
});

describe('orderOpenConnections', () => {
    it('puts the connection back to where you jumped from first, then newest', () => {
        const connections = [
            { id: 1, otherSolarsystemId: 10, createdAt: '2026-09-30T10:00:00Z' },
            { id: 2, otherSolarsystemId: 20, createdAt: '2026-09-30T11:00:00Z' },
            { id: 3, otherSolarsystemId: 30, createdAt: '2026-09-30T09:00:00Z' },
        ];
        expect(orderOpenConnections(connections, 30).map((connection) => connection.id)).toEqual([3, 2, 1]);
        expect(orderOpenConnections(connections, null).map((connection) => connection.id)).toEqual([2, 1, 3]);
    });
});
