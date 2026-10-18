/**
 * Patch 39: what the pop-out's map shows: the systems within a few jumps of the
 * character (by the map's pipes), and the view box centred on the character's system
 * that fits them (it moves one system over with each jump).
 */
export type TPopNode = { id: number; x: number; y: number; w: number; h: number };
export type TPopEdge = { from: number; to: number };

export const DEFAULT_NODE = { w: 120, h: 44 };

/** Ids of the systems within `hops` pipes of `start` (start included). */
export function nearbySystems(start: number, edges: readonly TPopEdge[], hops = 2): Set<number> {
    const seen = new Set<number>([start]);
    let frontier = [start];
    for (let step = 0; step < hops; step++) {
        const next: number[] = [];
        for (const id of frontier) {
            for (const edge of edges) {
                const other = edge.from === id ? edge.to : edge.to === id ? edge.from : null;
                if (other !== null && !seen.has(other)) {
                    seen.add(other);
                    next.push(other);
                }
            }
        }
        frontier = next;
    }
    return seen;
}

/** A view box centred on `centre` that fits every node (with room around them). */
export function viewBoxAround(centre: TPopNode, nodes: readonly TPopNode[], pad = 40): { x: number; y: number; w: number; h: number } {
    const cx = centre.x + centre.w / 2;
    const cy = centre.y + centre.h / 2;
    let halfW = centre.w / 2 + pad;
    let halfH = centre.h / 2 + pad;
    for (const node of nodes) {
        halfW = Math.max(halfW, Math.abs(node.x - cx) + pad, Math.abs(node.x + node.w - cx) + pad);
        halfH = Math.max(halfH, Math.abs(node.y - cy) + pad, Math.abs(node.y + node.h - cy) + pad);
    }
    return { x: cx - halfW, y: cy - halfH, w: halfW * 2, h: halfH * 2 };
}
