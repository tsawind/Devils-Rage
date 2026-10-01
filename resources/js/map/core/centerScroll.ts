/**
 * Patch 16: where Center scrolls the map. The map only moves when your system
 * gets within a fifth of the view from an edge (or leaves it); then it puts
 * you a third in from the left, at middle height while mapping, or in the
 * upper-left third while rage scanning so the chain has room to grow
 * down and right.
 */

/** How close to an edge (share of the view) your system may get before the map moves. */
export const CENTER_EDGE_SHARE = 1 / 5;

export type TCenterView = {
    scrollLeft: number;
    scrollTop: number;
    width: number;
    height: number;
};

/** Where your system lands after a re-center, as a share of the view. */
export function centerAnchor(rage: boolean): { x: number; y: number } {
    return rage ? { x: 1 / 3, y: 1 / 3 } : { x: 1 / 3, y: 1 / 2 };
}

/** Is a point (screen px within the canvas) comfortably inside the view? */
export function insideComfortZone(view: TCenterView, point: { x: number; y: number }): boolean {
    const marginX = view.width * CENTER_EDGE_SHARE;
    const marginY = view.height * CENTER_EDGE_SHARE;
    return (
        point.x >= view.scrollLeft + marginX &&
        point.x <= view.scrollLeft + view.width - marginX &&
        point.y >= view.scrollTop + marginY &&
        point.y <= view.scrollTop + view.height - marginY
    );
}

/**
 * The scroll to go to for a Center request, or null to leave the map where it
 * is. `force` (page load, Center switched on, rage scanning switched) always
 * re-centers.
 */
export function centerScroll(view: TCenterView, point: { x: number; y: number }, options: { rage: boolean; force: boolean }): { left: number; top: number } | null {
    if (!options.force && insideComfortZone(view, point)) return null;
    const anchor = centerAnchor(options.rage);
    return {
        left: Math.max(0, Math.round(point.x - view.width * anchor.x)),
        top: Math.max(0, Math.round(point.y - view.height * anchor.y)),
    };
}

/** Patch 16: empty room kept down and right of the last system while rage scanning (in screens). */
export const RAGE_ROOM_SCREENS = 1.5;
