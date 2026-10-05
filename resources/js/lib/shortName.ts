/**
 * Patch 25: a map name squeezed into the signature list's Name column (5 characters):
 * names up to 5 characters show whole; longer ones keep the first character and the last
 * three ("A112-112-131-1" → "A…1-1", "Daisy" stays, "Charlie" → "C…lie").
 */
export function shortName(name: string): string {
    const chars = [...name];
    if (chars.length <= 5) return name;
    return `${chars[0]}…${chars.slice(-3).join('')}`;
}
