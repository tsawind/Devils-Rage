import { scanCharacter, type TAppScan } from '@/lib/desktopApp';
import { describe, expect, it } from 'vitest';

const characters = [
    { id: 1, name: 'Tsawind' },
    { id: 2, name: 'LuigiSquirrel' },
];
const scan = (character_id: number | null, character_name: string): TAppScan => ({ character_id, character_name, text: '' });

describe('scanCharacter (patch 52)', () => {
    it('finds the character by id when the app knows it', () => {
        expect(scanCharacter(scan(1, 'Tsawind'), characters)?.id).toBe(1);
    });

    it('finds the character by name when chat logs are off (no id)', () => {
        expect(scanCharacter(scan(null, 'luigisquirrel '), characters)?.id).toBe(2);
    });

    it('finds nobody for a character not on this map', () => {
        expect(scanCharacter(scan(null, 'Someone Else'), characters)).toBeUndefined();
        expect(scanCharacter(scan(null, 'Tsawind'), null)).toBeUndefined();
    });
});
