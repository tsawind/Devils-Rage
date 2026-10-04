import type { EdgeGeometry, EdgeInput, Rect, Vec2 } from '../types';

/** Spacing between connections that leave the same node edge, in base units. */
export const PARALLEL_SPACING = 14;
/** Spacing between the stacked runs of detours sharing a column, in base units. */
export const BEND_SPACING = 16;
/** Clear space kept between a vertical run and the column it routes beside, in base units. */
export const LANE_MARGIN = 40;

export type Endpoints = { from: Vec2; to: Vec2; fromNormal: Vec2; toNormal: Vec2 };

/**
 * Connects the centre of each box's facing edge, leaving perpendicular to it.
 *
 * Prefer the left/right edges whenever the boxes are separated horizontally — then the
 * elbow drops its long vertical run through the clear lane between the columns
 * (down, then right into the node) instead of routing down-over-down with the second
 * vertical run cutting straight through the column of stacked siblings. Top/bottom
 * edges are only used when the boxes share a column, so there is no horizontal lane.
 */
export function edgeCenterConnection(source: Rect, target: Rect): Endpoints {
    const dx = target.centerX - source.centerX;
    const dy = target.centerY - source.centerY;

    const separatedX = target.minX > source.maxX || source.minX > target.maxX;
    const separatedY = target.minY > source.maxY || source.minY > target.maxY;
    const useHorizontal = separatedX || (!separatedY && Math.abs(dx) >= Math.abs(dy));

    if (useHorizontal) {
        const rightward = dx >= 0;
        return {
            from: { x: rightward ? source.maxX : source.minX, y: source.centerY },
            to: { x: rightward ? target.minX : target.maxX, y: target.centerY },
            fromNormal: { x: rightward ? 1 : -1, y: 0 },
            toNormal: { x: rightward ? -1 : 1, y: 0 },
        };
    }

    const downward = dy >= 0;
    return {
        from: { x: source.centerX, y: downward ? source.maxY : source.minY },
        to: { x: target.centerX, y: downward ? target.minY : target.maxY },
        fromNormal: { x: 0, y: downward ? 1 : -1 },
        toNormal: { x: 0, y: downward ? -1 : 1 },
    };
}

/** Both ends out the same side, for two nodes in one column with something between them. */
function detourConnection(source: Rect, target: Rect): Endpoints {
    return {
        from: { x: source.maxX, y: source.centerY },
        to: { x: target.maxX, y: target.centerY },
        fromNormal: { x: 1, y: 0 },
        toNormal: { x: 1, y: 0 },
    };
}

/** Whether a node sits between these two in their shared column, which a run would cross. */
function blockedInColumn(source: Rect, target: Rect, column: Rect[]): boolean {
    const top = Math.min(source.centerY, target.centerY);
    const bottom = Math.max(source.centerY, target.centerY);
    return column.some((other) => other !== source && other !== target && other.minY < bottom && other.maxY > top);
}

type Column = { left: number; right: number; members: Rect[] };

/** Patch 17: a run's last straight stretch into a node is at least this long (no turn right on its corner). */
export const MIN_STUB = 20;

/**
 * A vertical run belongs in the lanes between columns, never inside one: crossing another
 * edge is readable, disappearing behind a node is not. Only columns the run actually
 * passes between count, and the shifted lane stays inside them, so an edge never runs past
 * the node it is heading for and doubles back.
 */
function intoLane(x: number, columns: Column[], from: number, to: number, top: number, bottom: number): number {
    const near = Math.min(from, to);
    const far = Math.max(from, to);
    // Patch 17: keep a straight stub at both ends, so the run never turns right on a node's corner.
    const stub = Math.min(MIN_STUB, (far - near) / 2);
    for (const column of columns) {
        if (column.right <= near || column.left >= far) continue;
        // Patch 17: only nodes the vertical run actually passes count; a column of a lane
        // far below (other bands line up differently) is not in the way.
        if (!column.members.some((member) => member.minY < bottom + 4 && member.maxY > top - 4)) continue;
        if (x > column.left - LANE_MARGIN / 2 && x < column.right + LANE_MARGIN) {
            return clamp(column.right + LANE_MARGIN, near + stub, far - stub);
        }
    }
    // Lane-packed runs are already spaced inside the corridor: leave them where they are.
    return x;
}

function clamp(value: number, min: number, max: number): number {
    return Math.min(Math.max(value, min), max);
}

/**
 * Positions for the lanes of one corridor honouring `precedes` (lane → lanes that must
 * sit farther out), keeping the original order where the constraints leave a choice.
 * A cycle would mean tails that cannot all be kept apart; the tie is broken in favour
 * of the earlier lane and the rest still honoured.
 */
function laneOrder(precedes: ReadonlySet<number>[]): number[] {
    const indegree = precedes.map(() => 0);
    for (const targets of precedes) {
        for (const target of targets) {
            indegree[target]++;
        }
    }
    const position = precedes.map(() => 0);
    const placed = new Set<number>();
    for (let slot = 0; slot < precedes.length; slot++) {
        let pick = -1;
        for (let lane = 0; lane < precedes.length; lane++) {
            if (placed.has(lane)) continue;
            if (indegree[lane] === 0) {
                pick = lane;
                break;
            }
            if (pick === -1) pick = lane;
        }
        placed.add(pick);
        position[pick] = slot;
        for (const target of precedes[pick]) {
            if (!placed.has(target)) indegree[target]--;
        }
    }
    return position;
}

type Port = { endpoint: Vec2; normal: Vec2; box: Rect; sortKey: number; width?: number };

/**
 * Fans out connections that share a node edge so parallel lines don't overlap:
 * each shared edge spreads its endpoints along itself, ordered by the other end's
 * position so the lines stay untangled.
 */
function spreadSharedEdges(ports: Port[]): void {
    if (ports.length < 2) return;
    const alongY = ports[0].normal.x !== 0;
    const box = ports[0].box;
    const extent = alongY ? box.maxY - box.minY : box.maxX - box.minX;
    ports.sort((a, b) => a.sortKey - b.sortKey);
    // Patch 21: pipes of known width keep apart by half of each plus a gap (squeezed to fit the edge).
    if (ports.some((port) => port.width !== undefined)) {
        const widths = ports.map((port) => port.width ?? 8);
        const steps = widths.slice(1).map((width, i) => Math.max(PARALLEL_SPACING, widths[i] / 2 + EXIT_GAP + width / 2));
        const total = steps.reduce((sum, step) => sum + step, 0);
        const squeeze = Math.min(1, (extent * 0.9) / total);
        let at = -(total * squeeze) / 2;
        ports.forEach((port, i) => {
            if (i > 0) at += steps[i - 1] * squeeze;
            if (alongY) {
                port.endpoint.y += at;
            } else {
                port.endpoint.x += at;
            }
        });
        return;
    }
    const spacing = Math.min(PARALLEL_SPACING, (extent * 0.7) / (ports.length - 1));
    ports.forEach((port, i) => {
        const offset = (i - (ports.length - 1) / 2) * spacing;
        if (alongY) {
            port.endpoint.y += offset;
        } else {
            port.endpoint.x += offset;
        }
    });
}

type RoutedEdge = Extract<EdgeGeometry, { kind: 'elbow' }> & {
    sourceBox: Rect;
    targetBox: Rect;
    /** Both ends leave the same side, to get around the nodes between them. */
    detour: boolean;
    /** Perpendicular distance and signed offset of the far end, for ordering the fan. */
    distance: number;
    signed: number;
    /**
     * Patch 20: which end leaves its box out of the top/bottom edge instead of the side,
     * and whether it then runs straight to the other box ('direct') or steps into the
     * column gap first ('stepped').
     */
    exit?: { at: 'from' | 'to'; mode: 'direct' | 'stepped'; boxEdgeX: number } | null;
    /** Patch 21: the drawn width of the pipe (base units), when known. */
    width?: number;
};

/** Patch 20/21: at most this many pipes leave one box out of its top (and as many out of its bottom). */
export const MAX_EDGE_EXITS = 8;
/** Patch 21: room kept from a box's corner, and between two pipes leaving the same edge (plus half of each pipe). */
export const EXIT_CORNER = 12;
export const EXIT_GAP = 4;
/** Patch 21: the bottom edge keeps clear of the way-back pill in the box's bottom-left corner. */
export const WAY_BACK_CLEAR = 56;
/** Width assumed for a pipe whose width is not given. */
const DEFAULT_WIDTH = 8;

/**
 * Tree-layout routing, a global pass over all edges: connects facing box edges (or sends
 * both ends out the same side when the straight run would vanish behind a node in the
 * shared column), fans out endpoints that share a node edge, packs the perpendicular
 * runs crossing one corridor onto the fewest lanes that keep them apart, and steers
 * vertical runs out of the columns they only pass. `rects` should cover every measured
 * node — routing steers around whatever is actually in the way, connected or not.
 * Edges whose nodes are not both measured fall back to a straight curve between
 * their anchors (edges missing an anchor entirely are dropped).
 */
export function computeTreeEdgeGeometries(
    edges: EdgeInput[],
    rects: ReadonlyMap<number, Rect>,
    anchors: ReadonlyMap<number, Vec2>,
    /** Patch 20: pipes up/down a column leave out of the top/bottom (off: always the side). */
    options: { exits?: boolean } = {},
): Map<number, EdgeGeometry> {
    const geometries = new Map<number, EdgeGeometry>();
    const routed: RoutedEdge[] = [];

    // One rect per node, shared below so the column members can be compared by identity.
    const byColumn = new Map<number, Rect[]>();
    for (const rect of rects.values()) {
        (byColumn.get(rect.minX) ?? byColumn.set(rect.minX, []).get(rect.minX)!).push(rect);
    }
    const columns: Column[] = [...byColumn.entries()]
        .map(([left, members]) => ({ left, right: Math.max(...members.map((member) => member.maxX)), members }))
        .sort((a, b) => a.left - b.left);
    const columnRight = (left: number): number => columns.find((column) => column.left === left)?.right ?? left;

    for (const edge of edges) {
        const sourceBox = rects.get(edge.sourceId);
        const targetBox = rects.get(edge.targetId);
        if (!sourceBox || !targetBox) {
            const from = anchors.get(edge.sourceId);
            const to = anchors.get(edge.targetId);
            if (from && to) {
                geometries.set(edge.id, { id: edge.id, kind: 'curve', from, to });
            }
            continue;
        }
        const detour = sourceBox.minX === targetBox.minX && blockedInColumn(sourceBox, targetBox, byColumn.get(sourceBox.minX) ?? []);
        const ends = detour ? detourConnection(sourceBox, targetBox) : edgeCenterConnection(sourceBox, targetBox);
        const item: RoutedEdge = {
            id: edge.id,
            kind: 'elbow',
            from: { ...ends.from },
            to: { ...ends.to },
            fromNormal: ends.fromNormal,
            toNormal: ends.toNormal,
            bend: null,
            sourceBox,
            targetBox,
            detour,
            distance: 0,
            signed: 0,
            width: edge.width,
        };
        routed.push(item);
        geometries.set(edge.id, item);
    }

    if (options.exits !== false) planEdgeExits(routed, [...rects.values()]);

    // Fan out the endpoints that share a node edge so parallel lines don't overlap.
    const sharedEdges = new Map<string, Port[]>();
    const register = (endpoint: Vec2, normal: Vec2, box: Rect, other: Rect, width?: number): void => {
        const port: Port = { endpoint, normal, box, sortKey: normal.x !== 0 ? other.centerY : other.centerX, width };
        const key = `${box.centerX},${box.centerY}|${normal.x},${normal.y}`;
        (sharedEdges.get(key) ?? sharedEdges.set(key, []).get(key)!).push(port);
    };
    for (const item of routed) {
        if (item.exit?.at !== 'from') register(item.from, item.fromNormal, item.sourceBox, item.targetBox, item.width);
        if (item.exit?.at !== 'to') register(item.to, item.toNormal, item.targetBox, item.sourceBox, item.width);
    }
    const spreadCount = new Map<Vec2, number>();
    for (const ports of sharedEdges.values()) {
        spreadSharedEdges(ports);
        for (const port of ports) {
            spreadCount.set(port.endpoint, ports.length);
        }
    }

    // Two nodes sitting level would be joined by a straight line, except that spreading the
    // ports on the busier one lifts its end off the other's centre line and leaves a jog of
    // a few pixels. Where the quiet end is this edge's alone, it follows the busy one
    // instead: entering off-centre reads better than a kink that means nothing.
    for (const item of routed) {
        if (item.detour || item.exit) continue;
        const horizontal = item.fromNormal.x !== 0;
        const level = horizontal
            ? Math.abs(item.sourceBox.centerY - item.targetBox.centerY) < 0.5
            : Math.abs(item.sourceBox.centerX - item.targetBox.centerX) < 0.5;
        if (!level) continue;
        const fromShared = spreadCount.get(item.from) ?? 1;
        const toShared = spreadCount.get(item.to) ?? 1;
        if (fromShared === toShared) continue;
        const [follow, lead] = fromShared < toShared ? [item.from, item.to] : [item.to, item.from];
        if (horizontal) {
            follow.y = lead.y;
        } else {
            follow.x = lead.x;
        }
    }

    // Grouped by the corridor the run crosses, not by the node it leaves: two runs at the
    // same offset in the same corridor are the same line, whichever nodes they belong to,
    // so they all have to be packed together or they lie on top of each other.
    const fans = new Map<string, RoutedEdge[]>();
    for (const item of routed) {
        if (item.detour || item.exit?.mode === 'direct') continue;
        const horizontal = item.fromNormal.x !== 0;
        const sourceFirst = horizontal ? item.sourceBox.centerX <= item.targetBox.centerX : item.sourceBox.centerY <= item.targetBox.centerY;
        const primary = sourceFirst ? item.sourceBox : item.targetBox;
        const other = sourceFirst ? item.targetBox : item.sourceBox;
        const along = horizontal ? other.centerY - primary.centerY : other.centerX - primary.centerX;
        item.distance = Math.abs(along);
        item.signed = along;
        const across = horizontal ? edgeXs(item) : [item.from.y, item.to.y];
        const key = `${horizontal ? 'h' : 'v'}|${Math.min(...across)},${Math.max(...across)}`;
        (fans.get(key) ?? fans.set(key, []).get(key)!).push(item);
    }
    // Runs crossing one corridor only need separate lines where they would overlap and hide
    // each other: a run up and a run down can sit on the same line and still be told apart,
    // because each keeps its own colour. So pack them onto as few lines as possible, then
    // space those across the corridor.
    for (const group of fans.values()) {
        if (group.length < 2) continue;
        const horizontal = group[0].fromNormal.x !== 0;
        // How far the run reaches along the node edge it leaves from.
        const reach = (item: RoutedEdge): [number, number] =>
            horizontal
                ? [Math.min(item.from.y, item.to.y), Math.max(item.from.y, item.to.y)]
                : [Math.min(item.from.x, item.to.x), Math.max(item.from.x, item.to.x)];

        const lanes: RoutedEdge[][] = [];
        const laneOf = new Map<number, number>();
        const ordered = [...group].sort((a, b) => b.distance - a.distance || a.signed - b.signed);
        for (const item of ordered) {
            const span = reach(item);
            const fits = (lane: number): boolean =>
                lanes[lane].every((other) => {
                    const [start, end] = reach(other);
                    return span[0] >= end || span[1] <= start;
                });
            // Overlap is the only thing a lane cannot have: two runs on one line hide each
            // other, where two runs that cross stay readable. Taken longest first, first fit,
            // that lands on the fewest lanes the corridor can be drawn with.
            let lane = lanes.findIndex((_, i) => fits(i));
            if (lane === -1) {
                lane = lanes.push([]) - 1;
            }
            lanes[lane].push(item);
            laneOf.set(item.id, lane);
        }

        // One corridor, so one set of lines: measured from its near edge, not from each
        // run's own direction, or a run drawn leftward would count its lanes backwards.
        const ends = group.flatMap((item) => (horizontal ? edgeXs(item) : [item.from.y, item.to.y]));
        const near = Math.min(...ends);
        const far = Math.max(...ends);

        // Separate lanes only keep the runs themselves apart. Each run also has two tails
        // tying its lane to a corridor face, and the tails of a leftward and a rightward
        // edge that end level lie on one line: they stay apart only when the lane of the
        // edge entering the near face sits nearer than the lane of the edge entering the
        // far face. Renumber the lanes to honour those orderings, so a run never doubles
        // back over the tail of a level neighbour approaching from the other side.
        const tailsOf = (item: RoutedEdge): { at: number; fromNear: boolean }[] => {
            const middle = (near + far) / 2;
            return horizontal
                ? [
                      { at: item.from.y, fromNear: item.from.x < middle },
                      { at: item.to.y, fromNear: item.to.x < middle },
                  ]
                : [
                      { at: item.from.x, fromNear: item.from.y < middle },
                      { at: item.to.x, fromNear: item.to.y < middle },
                  ];
        };
        const precedes = lanes.map(() => new Set<number>());
        for (let i = 0; i < group.length; i++) {
            for (let j = i + 1; j < group.length; j++) {
                const laneA = laneOf.get(group[i].id)!;
                const laneB = laneOf.get(group[j].id)!;
                if (laneA === laneB) continue;
                for (const tailA of tailsOf(group[i])) {
                    for (const tailB of tailsOf(group[j])) {
                        if (Math.abs(tailA.at - tailB.at) >= 0.5 || tailA.fromNear === tailB.fromNear) continue;
                        const [nearLane, farLane] = tailA.fromNear ? [laneA, laneB] : [laneB, laneA];
                        precedes[nearLane].add(farLane);
                    }
                }
            }
        }
        const position = laneOrder(precedes);

        // Patch 21: with pipe widths known, lanes sit side by side by their widths (a gap
        // between the widest pipe of each), centred in the corridor, so thick trunks never
        // lie on each other; squeezed evenly when the corridor is too narrow for all of them.
        if (group.some((item) => item.width !== undefined)) {
            const laneWidth = lanes.map((members) => Math.max(...members.map((member) => member.width ?? DEFAULT_WIDTH)));
            const bySlot = [...laneWidth.keys()].sort((a, b) => position[a] - position[b]);
            const needed = laneWidth.reduce((total, width) => total + width, 0) + EXIT_GAP * (lanes.length - 1);
            const squeeze = Math.min(1, (far - near - 8) / needed);
            let cursor = (near + far) / 2 - (needed * squeeze) / 2;
            const centre = new Map<number, number>();
            for (const lane of bySlot) {
                centre.set(lane, cursor + (laneWidth[lane] * squeeze) / 2);
                cursor += (laneWidth[lane] + EXIT_GAP) * squeeze;
            }
            for (const item of group) {
                item.bend = centre.get(laneOf.get(item.id)!)!;
            }
        } else {
            for (const item of group) {
                item.bend = near + ((far - near) * (position[laneOf.get(item.id)!] + 1)) / (lanes.length + 1);
            }
        }
    }

    // Detours stack outwards so several between the same roots stay apart.
    const detourGroups = new Map<number, RoutedEdge[]>();
    for (const item of routed) {
        if (!item.detour) continue;
        const key = item.sourceBox.minX;
        (detourGroups.get(key) ?? detourGroups.set(key, []).get(key)!).push(item);
    }
    for (const [columnLeft, group] of detourGroups) {
        group.sort((a, b) => a.from.y - b.from.y);
        group.forEach((item, i) => {
            item.bend = columnRight(columnLeft) + LANE_MARGIN + i * BEND_SPACING;
        });
    }

    // The midpoint between two columns two apart lands exactly on the column between them.
    for (const item of routed) {
        if (item.detour || item.fromNormal.x === 0 || item.exit?.mode === 'direct') continue;
        const [fromX, toX] = edgeXs(item);
        item.bend = intoLane(item.bend ?? (fromX + toX) / 2, columns, fromX, toX, Math.min(item.from.y, item.to.y), Math.max(item.from.y, item.to.y));
    }

    return geometries;
}

/** The x of each end as the corridor sees it: an end that leaves out of the top/bottom counts as its box's side. */
function edgeXs(item: RoutedEdge): [number, number] {
    return [item.exit?.at === 'from' ? item.exit.boxEdgeX : item.from.x, item.exit?.at === 'to' ? item.exit.boxEdgeX : item.to.x];
}

/**
 * Patch 20: a pipe to a system in the next column up or down leaves its box out
 * of the top or bottom edge (the left side is for the pipe coming in), so pipes
 * no longer all crowd out of the right middle. The nearest system's pipe leaves
 * closest to the right corner; further ones further left, so they nest without
 * crossing. When the way up/down is clear it runs straight to the other system;
 * when a box sits right above/below, it steps out into the gap and joins the
 * column gap like before. No room: the right side, as before.
 *
 * Patch 21: pipes keep apart by their width (half of each plus a gap), may use the
 * whole edge up to the left corner, and the bottom edge keeps clear of the way-back
 * pill. Stepped runs stack by their widths too.
 */
function planEdgeExits(routed: RoutedEdge[], rects: Rect[]): void {
    type Entry = { item: RoutedEdge; left: Rect; right: Rect; up: boolean; at: 'from' | 'to'; dy: number };
    const groups = new Map<string, Entry[]>();
    for (const item of routed) {
        if (item.detour || item.fromNormal.x === 0) continue;
        const reversed = item.sourceBox.centerX > item.targetBox.centerX;
        const left = reversed ? item.targetBox : item.sourceBox;
        const right = reversed ? item.sourceBox : item.targetBox;
        // Only across a real column gap (not the small boxes right beside a rage-lane system).
        if (!(right.minX - left.maxX >= 30)) continue;
        const dy = right.centerY - left.centerY;
        // Level (or nearly): straight out of the right side.
        if (Math.abs(dy) < (left.maxY - left.minY) / 2 + 4) continue;
        const up = dy < 0;
        const key = `${left.minX},${left.minY}|${up ? 'up' : 'down'}`;
        (groups.get(key) ?? groups.set(key, []).get(key)!).push({ item, left, right, up, at: reversed ? 'to' : 'from', dy: Math.abs(dy) });
    }

    const crossesV = (x: number, half: number, y1: number, y2: number, skip: Rect[]): boolean =>
        rects.some((rect) => !skip.includes(rect) && rect.minX - 3 - half < x && rect.maxX + 3 + half > x && rect.minY < Math.max(y1, y2) && rect.maxY > Math.min(y1, y2));
    const crossesH = (y: number, half: number, x1: number, x2: number, skip: Rect[]): boolean =>
        rects.some((rect) => !skip.includes(rect) && rect.minY - 3 - half < y && rect.maxY + 3 + half > y && rect.minX < Math.max(x1, x2) && rect.maxX > Math.min(x1, x2));

    for (const entries of groups.values()) {
        entries.sort((a, b) => a.dy - b.dy);
        const { left, up } = entries[0];
        // The right edge of the next pipe out of this edge, and the leftmost it may reach.
        let cursor = left.maxX - EXIT_CORNER;
        const leftmost = left.minX + (up ? EXIT_CORNER : WAY_BACK_CLEAR);
        // How far into the gap the next stepped run goes (its near side).
        let depth = 6;
        let placed = 0;
        for (const entry of entries) {
            const { item, right, at } = entry;
            if (placed >= MAX_EDGE_EXITS) break;
            const width = item.width ?? DEFAULT_WIDTH;
            const half = width / 2;
            const x = cursor - half;
            if (x - half < leftmost) break;
            const edgeY = up ? left.minY : left.maxY;
            const direction = up ? -1 : 1;
            const targetY = right.centerY;
            const skip = [left, right];

            let joinY: number;
            let mode: 'direct' | 'stepped';
            if (!crossesV(x, half, edgeY, targetY, skip) && !crossesH(targetY, half, x, right.minX, skip)) {
                joinY = targetY;
                mode = 'direct';
            } else {
                // The room before the next box above/below, at this x.
                let room = Infinity;
                for (const rect of rects) {
                    if (skip.includes(rect) || rect.minX - 3 - half >= x || rect.maxX + 3 + half <= x) continue;
                    const distance = up ? edgeY - rect.maxY : rect.minY - edgeY;
                    if (distance >= 0) room = Math.min(room, distance);
                }
                const offset = depth + half;
                if (offset + half + 4 > room) continue;
                joinY = edgeY + direction * offset;
                if (crossesH(joinY, half, x, left.maxX, skip)) continue;
                mode = 'stepped';
                depth += width + EXIT_GAP;
            }

            const point = { x, y: joinY };
            const exitEnd = { point: { x, y: edgeY }, normal: { x: 0, y: direction } };
            if (at === 'from') {
                item.from = point;
                item.fromNormal = { x: 1, y: 0 };
                item.start = exitEnd;
            } else {
                item.to = point;
                item.toNormal = { x: 1, y: 0 };
                item.end = exitEnd;
            }
            item.exit = { at, mode, boxEdgeX: left.maxX };
            if (mode === 'direct') item.bend = (item.from.x + item.to.x) / 2;
            cursor = x - half - EXIT_GAP;
            placed++;
        }
    }
}
