/**
 * Patch 57: what text copied from EVE holds, for the intel window and the app's clipboard:
 * a D-scan (type id first), overview rows with structures, or a bare structure name (a chat
 * link or show-info name) that still needs its type. The server reads the rows themselves
 * (StructureScan); this only decides where the text goes.
 */
const STRUCTURE_TYPES = new Set([
    'astrahus',
    'fortizar',
    "'moreau' fortizar",
    "'draccous' fortizar",
    "'horizon' fortizar",
    "'marginis' fortizar",
    "'prometheus' fortizar",
    'keepstar',
    'upwell palatine keepstar',
    'raitaru',
    'azbel',
    'sotiyo',
    'athanor',
    'tatara',
]);

export function isStructureType(column: string): boolean {
    const lower = column.trim().toLowerCase();
    return STRUCTURE_TYPES.has(lower) || lower.includes('control tower');
}

export type TCopiedText = { kind: 'dscan'; structures: number } | { kind: 'structures'; structures: number } | { kind: 'name'; name: string } | { kind: 'other' };

export function readCopiedText(text: string): TCopiedText {
    const lines = text
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter(Boolean);
    if (!lines.length) return { kind: 'other' };

    const rows = lines.map((line) => line.split('\t').map((column) => column.trim()));
    const tabbed = rows.filter((columns) => columns.length >= 3);
    const structures = tabbed.filter((columns) => columns.some(isStructureType)).length;
    if (tabbed.length && tabbed.length * 10 >= lines.length * 8) {
        const dscan = tabbed.every((columns) => /^\d+$/.test(columns[0]) && columns.length >= 4);
        if (dscan) return { kind: 'dscan', structures };
        if (structures) return { kind: 'structures', structures };
        return { kind: 'other' };
    }

    // One line, no tabs: a structure's name from a chat link or show info ("J145735 - Old-Daisy").
    if (lines.length === 1 && !lines[0].includes('\t') && lines[0].length >= 3 && lines[0].length <= 120 && !/^https?:\/\//i.test(lines[0])) {
        return { kind: 'name', name: lines[0] };
    }
    return { kind: 'other' };
}
