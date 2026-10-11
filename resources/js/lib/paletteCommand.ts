/**
 * Patch 37: short commands in the search: "dest Amamake", "route Jita", "blops Amamake",
 * "cap Amamake" (or "carrier"). The command picks which actions come first; the rest is the
 * system to search for.
 */
export type TPaletteCommand = 'dest' | 'route' | 'blops' | 'carrier' | null;

const COMMANDS: Record<string, Exclude<TPaletteCommand, null>> = {
    dest: 'dest',
    destination: 'dest',
    route: 'route',
    blops: 'blops',
    bridge: 'blops',
    cap: 'carrier',
    carrier: 'carrier',
    conduit: 'carrier',
};

export function parsePaletteCommand(text: string): { command: TPaletteCommand; query: string } {
    const match = /^\s*([a-z]+)\s+(.+)$/i.exec(text);
    const command = match ? (COMMANDS[match[1].toLowerCase()] ?? null) : null;
    return command && match ? { command, query: match[2].trim() } : { command: null, query: text };
}

/** "5.1 LY" / "12 LY". */
export function lightyears(value: number): string {
    return `${value < 10 ? value.toFixed(1) : Math.round(value)} LY`;
}
