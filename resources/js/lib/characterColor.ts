/**
 * Patch 41: one colour per character, used everywhere for it: its tab, its pin on the map
 * cards, its ALT WINDOW strip and its pop-out. Its owner picks it (everyone sees it); until
 * then one is handed out by its id, so each character keeps the same one.
 */
export const CHARACTER_COLORS = ['#38bdf8', '#22c55e', '#f59e0b', '#ec4899', '#a78bfa', '#ef4444', '#14b8a6', '#eab308', '#f97316', '#6366f1'] as const;

/** Edits a character's colour in the page right away (before the server answers). */
const picked = new Map<number, string | null>();

export function rememberColor(characterId: number, color: string | null): void {
    picked.set(characterId, color);
}

export function characterColor(character: { id: number; map_color?: string | null } | null | undefined): string {
    if (!character) return CHARACTER_COLORS[0];
    const chosen = picked.has(character.id) ? picked.get(character.id) : character.map_color;
    if (chosen && /^#[0-9a-f]{6}$/i.test(chosen)) return chosen.toLowerCase();
    return CHARACTER_COLORS[Math.abs(character.id) % CHARACTER_COLORS.length];
}

/** How many tabs fit in `width` px when each takes `tab` px and the "+N" button `more` px. */
export function tabsThatFit(count: number, width: number, tab: number, more = 46): number {
    if (count <= 0) return 0;
    if (count * tab <= width) return count;
    return Math.max(1, Math.floor((width - more) / tab));
}
