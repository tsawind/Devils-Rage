/** One part of a pipe's pill. */
export type EdgeIndicator = {
    type: 'text' | 'clock' | 'weight' | 'gate' | 'preserve' | 'static' | 'eol';
    label?: string;
    /** Text badges: an arrow after the label pointing this way (screen degrees), e.g. which way a hole goes. */
    arrowAngle?: number | null;
    fill: string;
    stroke: string;
    /** Patch 16: a guess (likely EOL), drawn faint. */
    faint?: boolean;
    /** Patch 21: a doubtful static ("Static?") or a critical EOL ("EOL!"). */
    strong?: boolean;
};

/** How wide the pill is drawn, in screen pixels (0 = no pill). */
export function badgeWidth(indicators: readonly EdgeIndicator[]): number {
    if (indicators.length === 0) return 0;
    return (
        indicators.reduce((total, indicator) => {
            if (indicator.type === 'static' || indicator.type === 'eol') return total + (indicator.label ?? '').length * 7 + 12;
            if (indicator.type !== 'text') return total + 18;
            const label = (indicator.label ?? '').length;
            return total + Math.max(18, label * 9 + 4) + (indicator.arrowAngle != null ? 12 : 0);
        }, 0) + 8
    );
}
