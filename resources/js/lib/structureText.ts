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

/** Patch 57b: the kind for a structure type name ("Fortizar", "'Moreau' Fortizar", "Amarr Control Tower"). */
export function structureKind(type: string): string | null {
    const lower = type.trim().toLowerCase();
    if (lower.includes('control tower')) return 'pos';
    if (!STRUCTURE_TYPES.has(lower)) return null;
    return lower.replace(/^'[^']+'\s+/, '').replace(/^upwell palatine\s+/, '');
}

export type TCopiedName = { kind: 'name'; name: string; owner: string | null; structureKind: string | null };
export type TCopiedText = { kind: 'dscan'; structures: number } | { kind: 'structures'; structures: number } | TCopiedName | { kind: 'other' };

/**
 * Patch 57b: a structure's name as Show Info shows it: "J170845 - Main Barracks (E85 Holding Services)"
 * (the owner in brackets at the end), maybe with its "Citadel - Fortizar" line under it.
 */
function readName(lines: string[]): TCopiedName | null {
    if (!lines.length || lines.length > 2 || lines.some((line) => line.includes('\t') || line.length > 160 || /^https?:\/\//i.test(line))) return null;
    let structureKindFound: string | null = null;
    let nameLine = lines[0];
    if (lines.length === 2) {
        const typeLine = lines.map((line) => ({ line, kind: structureKind(line.split(' - ').pop() ?? '') })).find((candidate) => candidate.kind !== null);
        if (!typeLine) return null;
        structureKindFound = typeLine.kind;
        nameLine = lines.find((line) => line !== typeLine.line) ?? '';
    }
    const owned = nameLine.match(/^(.*\S)\s*\(([^()]+)\)$/);
    const name = (owned ? owned[1] : nameLine).trim();
    if (name.length < 3 || name.length > 120) return null;
    return { kind: 'name', name, owner: owned ? owned[2].trim() : null, structureKind: structureKindFound };
}

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

    // A structure's name from a chat link or Show Info ("J170845 - Main Barracks (E85 Holding Services)").
    return readName(lines) ?? { kind: 'other' };
}
