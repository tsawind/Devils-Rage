/** One part of a pipe's pill. */
export type EdgeIndicator = {
    type: 'text' | 'clock' | 'weight' | 'gate' | 'preserve' | 'static' | 'eol';
    label?: string;
    /**
     * Patch 21: text parts: 'type' is the hole's type (second line); anything else
     * is the size line (first line).
     */
    role?: 'size' | 'type';
    /** Size text: which way the hole goes, from where it opened toward its K162 exit. */
    arrow?: 'left' | 'right' | 'up' | 'down' | null;
    fill: string;
    stroke: string;
    /** Patch 16: a guess (likely EOL), drawn faint. */
    faint?: boolean;
    /** Patch 21: a doubtful static ("Static?") or a critical EOL ("EOL!"). */
    strong?: boolean;
};

/**
 * Patch 21: the pill is stacked: the size (with its arrow and the mass / clock /
 * gate icons), then the type, then Static, then EOL. Empty lines are left out.
 */
export function badgeLines(indicators: readonly EdgeIndicator[]): EdgeIndicator[][] {
    const top = indicators.filter((item) => (item.type === 'text' && item.role !== 'type') || ['weight', 'clock', 'gate', 'preserve'].includes(item.type));
    const type = indicators.filter((item) => item.type === 'text' && item.role === 'type');
    const statics = indicators.filter((item) => item.type === 'static');
    const eol = indicators.filter((item) => item.type === 'eol');
    return [top, type, statics, eol].filter((line) => line.length > 0);
}

const LINE_HEIGHT = 14;

function partWidth(item: EdgeIndicator): number {
    if (item.type === 'static' || item.type === 'eol') return (item.label ?? '').length * 6 + 8;
    if (item.type === 'text' && item.role === 'type') return (item.label ?? '').length * 6.7;
    if (item.type === 'text') return (item.label ?? '').length * 7.5 + (item.arrow ? 13 : 0);
    return 13;
}

/** How big the pill is drawn, in screen pixels (0 × 0 = no pill). */
export function badgeSize(indicators: readonly EdgeIndicator[]): { width: number; height: number } {
    const lines = badgeLines(indicators);
    if (lines.length === 0) return { width: 0, height: 0 };
    const widest = Math.max(...lines.map((line) => line.reduce((total, item) => total + partWidth(item), 0) + (line.length - 1) * 2));
    return { width: Math.ceil(Math.max(24, widest + 10)), height: lines.length * LINE_HEIGHT + 6 };
}

/** The pill's width (kept for callers that only need that). */
export function badgeWidth(indicators: readonly EdgeIndicator[]): number {
    return badgeSize(indicators).width;
}
