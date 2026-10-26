import { readCopiedText } from '@/lib/structureText';
import { describe, expect, it } from 'vitest';

describe('patch 57: text copied from EVE for intel', () => {
    it('knows a D-scan and counts its structures', () => {
        const dscan = "35833\tJ145735 - Old-Daisy\tFortizar\t27.7 AU\n12011\tA Legion\tLegion\t3.1 AU";
        expect(readCopiedText(dscan)).toEqual({ kind: 'dscan', structures: 1 });
    });

    it('takes overview rows only when they hold a structure', () => {
        expect(readCopiedText('27.7 AU\tJ145735 - Old-Daisy\tFortizar\t-\t-\t\t[TDVS]')).toEqual({ kind: 'structures', structures: 1 });
        expect(readCopiedText('3.3 AU\tJ145735 - Star\tSun G5 (Pink)\t-\t-')).toEqual({ kind: 'other' });
        expect(readCopiedText('16.2 AU\tTower\tAmarr Control Tower Large\t-')).toEqual({ kind: 'structures', structures: 1 });
    });

    it('takes a bare name as a structure name that still needs its type', () => {
        expect(readCopiedText('J145735 - Old-Daisy')).toEqual({ kind: 'name', name: 'J145735 - Old-Daisy', owner: null, structureKind: null });
        expect(readCopiedText('https://zkillboard.com/kill/1/')).toEqual({ kind: 'other' });
        expect(readCopiedText('two\nlines')).toEqual({ kind: 'other' });
    });

    it('reads the owner from Show Info, and the type when its line came along', () => {
        expect(readCopiedText('J170845 - Main Barracks (E85 Holding Services)')).toEqual({
            kind: 'name',
            name: 'J170845 - Main Barracks',
            owner: 'E85 Holding Services',
            structureKind: null,
        });
        expect(readCopiedText('J170845 - Main Barracks (E85 Holding Services)\nCitadel - Fortizar')).toEqual({
            kind: 'name',
            name: 'J170845 - Main Barracks',
            owner: 'E85 Holding Services',
            structureKind: 'fortizar',
        });
        expect(readCopiedText("Home (Us)\nCitadel - 'Moreau' Fortizar")).toMatchObject({ structureKind: 'fortizar' });
        expect(readCopiedText('Tower\nControl Tower - Amarr Control Tower Large')).toMatchObject({ name: 'Tower', structureKind: 'pos' });
        expect(readCopiedText('')).toEqual({ kind: 'other' });
    });
});
