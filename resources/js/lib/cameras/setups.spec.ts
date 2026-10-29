import { clientOfWindowLabel, DEFAULT_STRICTNESS, defaultSetup } from '@/lib/cameras/setups';
import { describe, expect, it } from 'vitest';

describe('camera setups', () => {
    it('gives a new client the whole client, overview, hole, probe scanner and local', () => {
        const setup = defaultSetup();
        expect(setup.map((box) => box.detect)).toEqual(['off', 'rows', 'flash', 'scan', 'local']);
        expect(setup.filter((box) => box.auto).map((box) => box.name)).toEqual(['EVE client', 'Overview', 'Hole', 'Probe scanner']);
        expect(setup.filter((box) => box.listen).map((box) => box.name)).toEqual(['Hole']);
        expect(setup.every((box) => box.strictness === DEFAULT_STRICTNESS)).toBe(true);
    });

    it('puts the hole box small, in the dead centre', () => {
        const hole = defaultSetup().find((box) => box.detect === 'flash') ?? defaultSetup()[0];
        expect(Math.round((hole.x + hole.w / 2) * 100)).toBe(50);
        expect(Math.round((hole.y + hole.h / 2) * 100)).toBe(50);
        expect(hole.w).toBeLessThan(0.1);
    });

    it('keeps every box inside the picture', () => {
        for (const box of defaultSetup()) {
            expect(box.x + box.w).toBeLessThan(1.0001);
            expect(box.y + box.h).toBeLessThan(1.0001);
        }
    });

    it('knows a shared window by its handle', () => {
        const windows = [
            { name: 'Tsawind', handle: 66820 },
            { name: 'LuigiSquirrel', handle: 132456 },
        ];
        expect(clientOfWindowLabel('window:132456:0', windows)).toBe('LuigiSquirrel');
        expect(clientOfWindowLabel('window:999:0', windows)).toBeNull();
        expect(clientOfWindowLabel('screen:0:0', windows)).toBeNull();
    });
});
