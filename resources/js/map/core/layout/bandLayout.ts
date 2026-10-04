import type { Vec2 } from '../types';

/**
 * The Devil's Rage map layout (patch 12): three bands, top to bottom.
 *
 * 1. Main band: Daisy (the map's home) and everything found from it, laid out
 *    left to right like the tree layout always was (statics first: siblings are
 *    ordered by `compareNodes`). A row is kept for Alpha (Daisy's static): when
 *    Alpha is in a combat lane or not mapped, a faint ghost holds its place.
 * 2. Side band: chains not linked to Daisy (e.g. Otela and everything found
 *    from it), each its own block, stacked one under another.
 * 3. Combat lanes: one per combat chain, side by side in the order the chains
 *    were started. The combat home sits at the top of its lane, the chain drops
 *    straight down (first child below its parent), other children start a new
 *    column to the right.
 *
 * A combat home found from the main band leaves a ghost in its main-band spot
 * ("Bravo · in the Blue lane"); main-chain systems found from it before the
 * chain started stay in the band, hanging off that ghost.
 *
 * Systems stay where they were first found: the tree is built from each
 * band's own systems, and every other connection is a loop (drawn dashed).
 * Positions are node anchors in base units, like the tree layout's.
 */

export type BandLayoutNode = {
    id: number;
    alias?: string | null;
    /** Combat chain color: members and the combat home carry it. */
    color?: string | null;
    /** A combat home (top of its lane). */
    home?: boolean | null;
    pinned?: boolean | null;
    /**
     * A placeholder for an unjumped wormhole signature (patch 12c): always a
     * leaf. In a lane it sorts after real systems so a combat chain still runs
     * straight down through its real systems; in the bands it sits by number.
     */
    placeholder?: boolean | null;
    /** Patch 16: an armed placeholder: in a lane it's laid out like the next system (below its parent, full size). */
    armed?: boolean | null;
    /**
     * Patch 20: rows this system keeps for its holes in the bands, even before they are
     * scanned (its expected statics plus one spare), so a new hole fills a row that was
     * already there instead of pushing everything below it down.
     */
    reserve?: number | null;
};

export type BandLayoutInput = {
    nodes: BandLayoutNode[];
    edges: { from: number; to: number }[];
    /** Daisy's map system id, the main band's root. */
    homeId: number | null;
    /** Combat chain colors in the order the chains were started (lane order). */
    laneOrder?: string[];
    /** Orders siblings: statics first, then by number (see compareSystems). */
    compareNodes?: (a: number, b: number) => number;
    /** The alias Daisy's static takes ("A"); a ghost keeps its row free. Null turns the reserved row off. */
    reservedAlias?: string | null;
};

export type BandLayoutOptions = {
    gridSize?: number;
    /** Column distance in the bands (x). */
    levelGap?: number;
    /** Row distance in the bands and the lanes (y). */
    rowGap?: number;
    /** Column distance inside a lane. */
    laneColumnGap?: number;
    /** Row distance inside a lane (patch 13: compact combat lanes). */
    laneRowGap?: number;
    /** A combat lane system's size (patch 13: compact). */
    laneNodeWidth?: number;
    laneNodeHeight?: number;
    /** Space between two lanes, and between bands. */
    laneGap?: number;
    bandGap?: number;
    marginX?: number;
    marginY?: number;
    /** A node's size, for the band and lane outlines. */
    nodeWidth?: number;
    nodeHeight?: number;
    /** Patch 20: empty rows above the main band, so home doesn't sit in the top corner. */
    homeTopRows?: number;
    /**
     * Patch 22: the home layout. Home, the first system of each side chain and every
     * pinned system are hubs: their holes leave in straight lanes up and down, side by
     * side, each further hole one lane left; everything beyond is laid out as before
     * (patch 22b), each hole's tree kept clear of the others.
     */
    homeLayout?: boolean;
};

export type BandGhost = {
    /** Stable key for rendering. */
    key: string;
    position: Vec2;
    /** The alias the ghost holds ("A"; shown as "Alpha"). */
    label: string;
    /** "kept free" for the reserved row; empty for a combat home's spot (the renderer names its lane). */
    note: string;
    /** The combat chain the real system sits in, or null for the reserved row. */
    color: string | null;
};

export type BandRect = { minX: number; minY: number; maxX: number; maxY: number };

export type BandLane = BandRect & {
    color: string;
    homeId: number | null;
    /** Where the lane hangs off (patch 13): the system its home was found from, or null for an unlinked chain. */
    parentId: number | null;
};

export type BandSideChain = { rootId: number; memberIds: number[] };

export type BandLayoutResult = {
    positions: Map<number, Vec2>;
    ghosts: BandGhost[];
    lanes: BandLane[];
    mainBand: BandRect | null;
    sideBand: BandRect | null;
    /** The area for combat chains not linked to anything (patch 13), between the main chain and the side chains. */
    combatBand: BandRect | null;
    /** The connection each system was found through: child → parent (real ids). */
    parentOf: Map<number, number>;
    /** Which band each system is in. */
    bandOf: Map<number, 'main' | 'side' | 'lane'>;
    sideChains: BandSideChain[];
    /** Patch 22: a hub's hole → the hub and the x of its straight lane (base units, the pipe's centre). */
    hubLanes: Map<number, { hubId: number; x: number }>;
    /** Patch 22: the system the map keeps still on screen when the layout grows (home, in the home layout). */
    anchorId: number | null;
};

/** Patch 22: a hub's first hole sits this far right of the hub, each further hole one lane step left. */
export const HUB_FIRST_LANE = 135;
export const HUB_LANE_STEP = 45;
/** Lanes up (and down) before holes start sharing the last one. */
export const HUB_MAX_LANES = 5;

const GHOST_BASE = 1_000_000_000;

/** A pair key for a connection, either direction: "3-7". */
export function edgeKey(a: number, b: number): string {
    return a < b ? `${a}-${b}` : `${b}-${a}`;
}

/**
 * Whether a connection is a loop: not the one either end was found through.
 * Loops are drawn dashed (patch 12).
 */
export function isLoopEdge(parentOf: ReadonlyMap<number, number>, from: number, to: number): boolean {
    return parentOf.get(from) !== to && parentOf.get(to) !== from;
}

export function computeBandLayout(input: BandLayoutInput, options: BandLayoutOptions = {}): BandLayoutResult {
    const gridSize = options.gridSize ?? 20;
    const snap = (value: number): number => Math.round(value / gridSize) * gridSize;
    // Patch 17: rows snap to half a grid cell (the tree layout draws no grid), so 90 apart stays 90.
    // Patch 20: a quarter cell, so 85 apart stays 85 and columns 250 apart stay 250.
    const snapRow = (value: number): number => Math.round(value / (gridSize / 4)) * (gridSize / 4);
    const levelGap = snapRow(options.levelGap ?? 320);
    const rowGap = snapRow(options.rowGap ?? 100);
    const laneColumnGap = snap(options.laneColumnGap ?? 100);
    const laneRowGap = snap(options.laneRowGap ?? 60);
    const laneNodeWidth = options.laneNodeWidth ?? 80;
    const laneNodeHeight = options.laneNodeHeight ?? 26;
    const laneGap = snap(options.laneGap ?? 80);
    const bandGap = snap(options.bandGap ?? 80);
    const marginX = snap(options.marginX ?? 60);
    const marginY = snap(options.marginY ?? 40);
    const nodeWidth = options.nodeWidth ?? 180;
    const nodeHeight = options.nodeHeight ?? 40;
    // Anchors sit this far in from a node's top-left (see ANCHOR_OFFSET).
    const anchorX = 40;
    const anchorY = 20;

    const byId = new Map(input.nodes.map((node) => [node.id, node]));
    const adjacency = new Map<number, number[]>();
    for (const node of input.nodes) adjacency.set(node.id, []);
    for (const edge of input.edges) {
        if (edge.from === edge.to || !adjacency.has(edge.from) || !adjacency.has(edge.to)) continue;
        adjacency.get(edge.from)!.push(edge.to);
        adjacency.get(edge.to)!.push(edge.from);
    }
    for (const [id, neighbours] of adjacency) adjacency.set(id, [...new Set(neighbours)]);

    const colorOf = (id: number): string | null => byId.get(id)?.color || null;
    const isHome = (id: number): boolean => Boolean(byId.get(id)?.home) && colorOf(id) !== null;
    const isMember = (id: number): boolean => colorOf(id) !== null && !isHome(id);
    const isPlaceholder = (id: number): boolean => Boolean(byId.get(id)?.placeholder);
    /** Patch 14: chains with a home; a chain without one is being cleaned up into a band. */
    const colorsWithHome = new Set(input.nodes.filter((node) => node.home && node.color).map((node) => node.color as string));

    const reservedGhostId = GHOST_BASE * 2;
    const ghostInfo = new Map<number, { label: string; note: string; color: string | null; alias: string | null; realId: number | null }>();

    // Sibling order in the bands: the given order (statics first), placeholders
    // mixed in by the number they hold. Ghosts sort by the alias they hold.
    const aliasFor = (id: number): string | null => (ghostInfo.has(id) ? ghostInfo.get(id)!.alias : (byId.get(id)?.alias ?? null));
    const compare = (a: number, b: number): number => {
        const realA = ghostInfo.get(a)?.realId ?? (ghostInfo.has(a) ? null : a);
        const realB = ghostInfo.get(b)?.realId ?? (ghostInfo.has(b) ? null : b);
        if (realA !== null && realB !== null && input.compareNodes) {
            const result = input.compareNodes(realA, realB);
            if (result !== 0) return result;
        }
        const aliasA = aliasFor(a);
        const aliasB = aliasFor(b);
        if (aliasA && !aliasB) return 1;
        if (!aliasA && aliasB) return -1;
        return (aliasA ?? '').localeCompare(aliasB ?? '') || a - b;
    };
    // In a lane, placeholders come after real systems, so the chain runs straight down through what was jumped.
    const compareLane = (a: number, b: number): number => {
        const placeholderA = isPlaceholder(a) ? 1 : 0;
        const placeholderB = isPlaceholder(b) ? 1 : 0;
        return placeholderA - placeholderB || compare(a, b);
    };

    const parentOf = new Map<number, number>();
    const bandOf = new Map<number, 'main' | 'side' | 'lane'>();
    const visited = new Set<number>();

    /** Combat homes found from a band system: their lane sits inside that band, under it (patch 13). */
    const laneLinks = new Map<number, { parent: number; band: 'main' | 'side' }>();
    /** Patch 14: a chain without a home (being cleaned up) hangs off the band system its first member was found from. */
    const laneEntries = new Map<string, { id: number; parent: number; band: 'main' | 'side' }>();

    /**
     * Breadth-first tree of one band from `root`: steps through uncolored
     * systems only. A combat home met on the way is left for its lane, which
     * hangs off the system it was found from; its uncolored neighbours (found
     * before the chain started) stay in the band, under that same system.
     * Branches leading to a lane sort to the bottom, so the lane has room
     * right under them.
     */
    const growBand = (root: number, band: 'main' | 'side'): Map<number, number[]> => {
        const childrenOf = new Map<number, number[]>();
        childrenOf.set(root, []);
        visited.add(root);
        bandOf.set(root, band);
        // Queue entries: [layout id, real id].
        const queue: [number, number][] = [[root, root]];
        while (queue.length > 0) {
            const [layoutId, realId] = queue.shift()!;
            for (const neighbour of adjacency.get(realId) ?? []) {
                if (!visited.has(neighbour) && isMember(neighbour) && !isPlaceholder(neighbour)) {
                    const color = colorOf(neighbour)!;
                    if (!colorsWithHome.has(color)) {
                        if (!laneEntries.has(color)) laneEntries.set(color, { id: neighbour, parent: layoutId, band });
                        // Every member hanging off a band system knows it (the cleanup rows read this).
                        if (!parentOf.has(neighbour)) parentOf.set(neighbour, realId);
                    }
                }
                if (visited.has(neighbour) || isMember(neighbour)) continue;
                visited.add(neighbour);
                parentOf.set(neighbour, realId);
                if (isHome(neighbour)) {
                    // Hangs under the band system it was reached from (also when found through another combat home).
                    laneLinks.set(neighbour, { parent: layoutId, band });
                    queue.push([layoutId, neighbour]);
                    continue;
                }
                bandOf.set(neighbour, band);
                childrenOf.get(layoutId)!.push(neighbour);
                childrenOf.set(neighbour, []);
                queue.push([neighbour, neighbour]);
            }
        }
        // Every system on the way to a lane sorts after its siblings.
        const layoutParent = new Map<number, number>();
        for (const [id, children] of childrenOf) for (const child of children) layoutParent.set(child, id);
        const towardLane = new Set<number>();
        for (const link of [...laneLinks.values(), ...laneEntries.values()]) {
            if (link.band !== band) continue;
            let id: number | undefined = link.parent;
            while (id !== undefined && !towardLane.has(id)) {
                towardLane.add(id);
                id = layoutParent.get(id);
            }
        }
        const compareBand = (a: number, b: number): number => (towardLane.has(a) ? 1 : 0) - (towardLane.has(b) ? 1 : 0) || compare(a, b);
        for (const children of childrenOf.values()) children.sort(compareBand);
        return childrenOf;
    };

    // --- Classify first: the main band, then each side chain -------------
    const homeId = input.homeId !== null && adjacency.has(input.homeId) && !colorOf(input.homeId) ? input.homeId : null;
    const reserved = input.reservedAlias?.trim().toUpperCase() || null;
    let mainTree: Map<number, number[]> | null = null;
    if (homeId !== null) {
        mainTree = growBand(homeId, 'main');
        if (reserved) {
            const daisyChildren = mainTree.get(homeId)!;
            const holdsReserved = daisyChildren.some((id) => (aliasFor(id) ?? '').trim().toUpperCase() === reserved);
            if (!holdsReserved) {
                ghostInfo.set(reservedGhostId, { label: reserved, note: 'kept free', color: null, alias: reserved, realId: null });
                daisyChildren.push(reservedGhostId);
                mainTree.set(reservedGhostId, []);
                daisyChildren.sort(compare);
            }
        }
    }

    const sideChains: BandSideChain[] = [];
    const sideTrees: { root: number; tree: Map<number, number[]> }[] = [];
    const leftovers = input.nodes
        .map((node) => node.id)
        .filter((id) => !visited.has(id) && !isMember(id) && !isHome(id) && !isPlaceholder(id));
    while (leftovers.some((id) => !visited.has(id))) {
        const component = collectComponent(
            leftovers.find((id) => !visited.has(id))!,
            adjacency,
            (id) => !isMember(id) && !isHome(id),
        );
        const root = pickSideRoot(component, byId, adjacency);
        const tree = growBand(root, 'side');
        sideChains.push({ rootId: root, memberIds: [...tree.keys()].filter((id) => !ghostInfo.has(id)) });
        sideTrees.push({ root, tree });
    }

    // --- Lanes: each chain's own tree, from its home through its own systems ---
    const colors = new Set<string>();
    for (const node of input.nodes) if (node.color) colors.add(node.color);
    const laneColors = [...(input.laneOrder ?? []).filter((color) => colors.has(color)), ...[...colors].filter((color) => !(input.laneOrder ?? []).includes(color)).sort()];

    const laneTrees = new Map<string, { homeId: number | null; roots: number[]; childrenOf: Map<number, number[]> }>();
    for (const color of laneColors) {
        const laneIds = input.nodes.filter((node) => node.color === color).map((node) => node.id);
        const laneSet = new Set(laneIds);
        const homeOfLane = laneIds.find((id) => isHome(id)) ?? null;
        const childrenOf = new Map<number, number[]>();
        const roots: number[] = [];
        const placed = new Set<number>();
        const grow = (root: number): void => {
            roots.push(root);
            placed.add(root);
            bandOf.set(root, 'lane');
            childrenOf.set(root, []);
            const queue = [root];
            while (queue.length > 0) {
                const id = queue.shift()!;
                for (const neighbour of adjacency.get(id) ?? []) {
                    if (!laneSet.has(neighbour) || placed.has(neighbour)) continue;
                    placed.add(neighbour);
                    bandOf.set(neighbour, 'lane');
                    parentOf.set(neighbour, id);
                    childrenOf.get(id)!.push(neighbour);
                    childrenOf.set(neighbour, []);
                    queue.push(neighbour);
                }
            }
        };
        if (homeOfLane !== null) grow(homeOfLane);
        else if (laneEntries.has(color)) grow(laneEntries.get(color)!.id);
        for (const id of laneIds.toSorted(compareLane)) {
            if (!placed.has(id) && !isPlaceholder(id)) grow(id);
        }
        // A placeholder whose parent is elsewhere: give it its own spot at the end.
        for (const id of laneIds) {
            if (!placed.has(id)) grow(id);
        }
        for (const children of childrenOf.values()) children.sort(compareLane);
        for (const id of placed) visited.add(id);
        laneTrees.set(color, { homeId: homeOfLane, roots, childrenOf });
    }

    // --- Placement ----------------------------------------------------------
    const positions = new Map<number, Vec2>();
    const ghosts: BandGhost[] = [];
    const lanes: BandLane[] = [];
    let cursorY = marginY;

    let spareId = GHOST_BASE * 3;
    const spares = new Set<number>();
    const placeTree = (root: number, tree: Map<number, number[]>, top: number): { bottom: number; right: number } => {
        // Patch 20: spare (invisible) child slots up to each system's reserve, last in its list.
        const childrenOf = new Map<number, number[]>();
        for (const [id, children] of tree) {
            const reserve = ghostInfo.has(id) ? 0 : (byId.get(id)?.reserve ?? 0);
            const list = [...children];
            while (list.length < reserve) {
                spareId += 1;
                spares.add(spareId);
                childrenOf.set(spareId, []);
                list.push(spareId);
            }
            childrenOf.set(id, list);
        }
        const cross = reingoldTilford(root, childrenOf, rowGap);
        let minCross = Infinity;
        for (const value of cross.values()) minCross = Math.min(minCross, value);
        let bottom = top;
        let right = marginX;
        const depthOf = new Map<number, number>([[root, 0]]);
        const order = [root];
        for (let index = 0; index < order.length; index++) {
            const id = order[index];
            for (const child of childrenOf.get(id) ?? []) {
                depthOf.set(child, depthOf.get(id)! + 1);
                order.push(child);
            }
        }
        for (const id of order) {
            const point = { x: snapRow(marginX + depthOf.get(id)! * levelGap), y: snapRow(top + cross.get(id)! - minCross) };
            if (spares.has(id)) {
                bottom = Math.max(bottom, point.y);
                continue;
            }
            bottom = Math.max(bottom, point.y);
            right = Math.max(right, point.x + nodeWidth);
            const ghost = ghostInfo.get(id);
            if (ghost) {
                ghosts.push({ key: `ghost-${id}`, position: point, label: ghost.label, note: ghost.note, color: ghost.color });
            } else {
                positions.set(id, point);
            }
        }
        return { bottom, right };
    };

    // --- Patch 22: the home layout -------------------------------------------------
    const hubLanes = new Map<number, { hubId: number; x: number }>();
    type HubRect = { x0: number; x1: number; y0: number; y1: number };
    type HubSub = { pos: Map<number, Vec2>; rects: HubRect[]; lanes: [number, number][] };
    const boxGap = rowGap - nodeHeight;
    // Room between a hub and its first hole up or down: the cards are taller than nodeHeight, and the pill sits there.
    const hubGap = nodeHeight + 85;
    const boxRect = (x: number, y: number): HubRect => ({ x0: x, x1: x + nodeWidth, y0: y, y1: y + nodeHeight });
    /**
     * How far (y) to move `sub` from `start`, in direction `d`, so none of its boxes
     * overlaps a box of `placed` it shares any x with (one-sided contour packing).
     */
    const packOffset = (placed: HubRect[], sub: HubRect[], dx: number, start: number, d: 1 | -1): number => {
        let t = start;
        for (const s of sub) {
            for (const a of placed) {
                if (s.x0 + dx >= a.x1 || s.x1 + dx <= a.x0) continue;
                if (d < 0) t = Math.min(t, a.y0 - boxGap - s.y1);
                else t = Math.max(t, a.y1 + boxGap - s.y0);
            }
        }
        return t;
    };
    const mergeSub = (into: HubSub, sub: HubSub, dx: number, dy: number): void => {
        for (const [id, point] of sub.pos) into.pos.set(id, { x: point.x + dx, y: point.y + dy });
        for (const rect of sub.rects) into.rects.push({ x0: rect.x0 + dx, x1: rect.x1 + dx, y0: rect.y0 + dy, y1: rect.y1 + dy });
        into.lanes.push(...sub.lanes);
    };
    const isHub = (id: number, root: number): boolean => id === root || (Boolean(byId.get(id)?.pinned) && !ghostInfo.has(id));
    /** A system and everything found from it, relative to it (it sits at 0,0). Patch 22c: no spare rows, chains pack tight. */
    const layoutSub = (id: number, tree: Map<number, number[]>, root: number): HubSub => {
        const hub = isHub(id, root);
        const sub: HubSub = { pos: new Map([[id, { x: 0, y: 0 }]]), rects: [boxRect(0, 0)], lanes: [] };
        const children = tree.get(id) ?? [];
        if (hub) {
            // Holes alternate up and down (the first, usually the static, goes up), each in its own lane.
            const sides: { d: 1 | -1; list: number[] }[] = [
                { d: -1, list: children.filter((_, index) => index % 2 === 0) },
                { d: 1, list: children.filter((_, index) => index % 2 === 1) },
            ];
            for (const side of sides) {
                // How far this side's trees reach so far: each next hole sits beyond the previous hole's whole tree.
                let reach = side.d < 0 ? 0 : nodeHeight;
                side.list.forEach((child, index) => {
                    const lane = Math.min(index, HUB_MAX_LANES - 1);
                    const dx = HUB_FIRST_LANE - lane * HUB_LANE_STEP;
                    const childSub = layoutSub(child, tree, root);
                    const start =
                        index === 0
                            ? side.d * hubGap
                            : side.d < 0
                              ? reach - boxGap - Math.max(...childSub.rects.map((rect) => rect.y1))
                              : reach + boxGap - Math.min(...childSub.rects.map((rect) => rect.y0));
                    const dy = snapRow(packOffset(sub.rects, childSub.rects, dx, start, side.d));
                    mergeSub(sub, childSub, dx, dy);
                    for (const rect of childSub.rects) reach = side.d < 0 ? Math.min(reach, rect.y0 + dy) : Math.max(reach, rect.y1 + dy);
                    // The lane itself: nothing else may sit on it between the hub and the hole.
                    const laneTop = side.d < 0 ? dy + nodeHeight : nodeHeight;
                    const laneBottom = side.d < 0 ? 0 : dy;
                    sub.rects.push({ x0: dx, x1: dx + 30, y0: Math.min(laneTop, laneBottom), y1: Math.max(laneTop, laneBottom) });
                    if (!ghostInfo.has(child)) sub.lanes.push([child, id]);
                });
            }
            return sub;
        }
        // The usual tree, as the old layout draws it: children stacked top to bottom, each
        // packed against the ones above it, the parent centred on them (patch 22b).
        const placed: { childSub: HubSub; dy: number }[] = [];
        const siblingRects: HubRect[] = [];
        for (const child of children) {
            const childSub = layoutSub(child, tree, root);
            const start = placed.length === 0 ? 0 : placed[placed.length - 1].dy + rowGap;
            const dy = packOffset(siblingRects, childSub.rects, 0, start, 1);
            for (const rect of childSub.rects) siblingRects.push({ ...rect, y0: rect.y0 + dy, y1: rect.y1 + dy });
            placed.push({ childSub, dy });
        }
        if (placed.length > 0) {
            const centre = snapRow((placed[0].dy + placed[placed.length - 1].dy) / 2);
            for (const { childSub, dy } of placed) mergeSub(sub, childSub, levelGap, dy - centre);
        }
        return sub;
    };
    /**
     * Patch 22d: each system's box slides toward the system it came from (its own holes stay put),
     * until it meets another box in its column. A hub's holes stop at the hub gap; any other system
     * with holes goes at most one row past its first (or last) hole. Leaves don't move.
     */
    const slideTowardParents = (root: number, tree: Map<number, number[]>, pos: Map<number, Vec2>): void => {
        const order = [root];
        for (let index = 0; index < order.length; index++) {
            const parent = order[index];
            const parentAt = pos.get(parent)!;
            for (const id of tree.get(parent) ?? []) {
                const at = pos.get(id);
                if (!at) continue;
                order.push(id);
                const kids = (tree.get(id) ?? []).map((kid) => pos.get(kid)?.y).filter((y): y is number => y !== undefined);
                let target: number;
                if (isHub(parent, root)) {
                    target = parentAt.y + (at.y < parentAt.y ? -hubGap : hubGap);
                } else if (kids.length > 0) {
                    target = Math.min(Math.max(parentAt.y, Math.min(...kids) - rowGap), Math.max(...kids) + rowGap);
                } else {
                    continue;
                }
                if (target === at.y) continue;
                const down = target > at.y;
                let y = target;
                for (const [other, point] of pos) {
                    if (other === id || Math.abs(point.x - at.x) >= nodeWidth) continue;
                    if (down && point.y > at.y) y = Math.min(y, point.y - rowGap);
                    if (!down && point.y < at.y) y = Math.max(y, point.y + rowGap);
                }
                if (down ? y > at.y : y < at.y) pos.set(id, { x: at.x, y });
            }
        }
    };
    /** Places one hub tree with its top at `top` (the hub further down when its holes above need the room). */
    const placeHubTree = (root: number, tree: Map<number, number[]>, top: number, hubY: number): { bottom: number; right: number } => {
        const sub = layoutSub(root, tree, root);
        slideTowardParents(root, tree, sub.pos);
        const minY = Math.min(...[...sub.pos.values()].map((point) => point.y));
        const minX = Math.min(...sub.rects.map((rect) => rect.x0));
        const originY = snapRow(Math.max(hubY, top - minY));
        // Room is always kept for the hub's leftmost lane, so a new hole never shifts it right.
        const originX = snapRow(marginX + Math.max((HUB_MAX_LANES - 1) * HUB_LANE_STEP - HUB_FIRST_LANE, -minX));
        let bottom = top;
        let right = marginX;
        for (const [id, point] of sub.pos) {
            const at = { x: originX + point.x, y: originY + point.y };
            bottom = Math.max(bottom, at.y);
            right = Math.max(right, at.x + nodeWidth);
            const ghost = ghostInfo.get(id);
            if (ghost) ghosts.push({ key: `ghost-${id}`, position: at, label: ghost.label, note: ghost.note, color: ghost.color });
            else positions.set(id, at);
        }
        for (const [child, hubId] of sub.lanes) {
            const at = positions.get(child);
            // The pipe runs 15 in from the hole's left edge (anchors sit 40 in from it).
            if (at) hubLanes.set(child, { hubId, x: at.x - anchorX + 15 });
        }
        return { bottom, right };
    };

    /** One lane at (left, top): first child straight down, every other child a new column to the right. */
    const placeLane = (color: string, left: number, top: number, parentId: number | null): { right: number; bottom: number } => {
        const lane = laneTrees.get(color)!;
        let lastColumn = -1;
        let maxRow = 0;
        let maxRight = 0;
        const place = (id: number, column: number, row: number): void => {
            const x = snap(left + column * laneColumnGap);
            const y = snap(top + row * laneRowGap);
            positions.set(id, { x, y });
            maxRow = Math.max(maxRow, row);
            maxRight = Math.max(maxRight, x + laneNodeWidth);
            const children = lane.childrenOf.get(id) ?? [];
            // Patch 15: unjumped holes stack down beside their system instead of taking columns.
            // Patch 16: an armed hole is laid out like the next system: straight below when the
            // system has no jumped child yet, else as the next branch.
            const isArmed = (child: number): boolean => Boolean(byId.get(child)?.armed);
            const holes = children.filter((child) => isPlaceholder(child) && !isArmed(child));
            const armed = children.filter((child) => isPlaceholder(child) && isArmed(child));
            holes.forEach((hole, index) => {
                positions.set(hole, { x: x + laneNodeWidth + LANE_HOLE_GAP, y: y + index * LANE_HOLE_STEP });
                maxRight = Math.max(maxRight, x + laneNodeWidth + LANE_HOLE_GAP + LANE_HOLE_WIDTH);
            });
            // The holes plus the fold chip under them (patch 15) push the next row down when they don't fit.
            const stack = holes.length > 0 ? (holes.length + 1) * LANE_HOLE_STEP : 0;
            const holeRows = stack > laneRowGap ? Math.ceil(stack / laneRowGap) - 1 : 0;
            maxRow = Math.max(maxRow, row + holeRows);
            const jumped = children.filter((child) => !isPlaceholder(child));
            (jumped.length > 0 ? [...jumped, ...armed] : armed)
                .forEach((child, index) => {
                    if (index === 0) {
                        place(child, column, row + 1 + holeRows);
                    } else {
                        lastColumn += 1;
                        place(child, lastColumn, row + 1 + holeRows);
                    }
                });
        };
        for (const root of lane.roots) {
            lastColumn += 1;
            place(root, lastColumn, 0);
        }
        const rect: BandLane = {
            color,
            homeId: lane.homeId,
            parentId,
            minX: left - anchorX - 14,
            minY: top - anchorY - 34,
            maxX: Math.max(left + Math.max(0, lastColumn) * laneColumnGap + laneNodeWidth, maxRight) - anchorX + 14,
            maxY: top + maxRow * laneRowGap - anchorY + laneNodeHeight + 14,
        };
        lanes.push(rect);
        return { right: rect.maxX, bottom: rect.maxY };
    };

    /** The lanes hanging off one band's systems, under the band's own systems (patch 13). */
    const placeLinkedLanes = (band: 'main' | 'side', members: Set<number>, top: number): { right: number; bottom: number } => {
        let laneLeft = -Infinity;
        let right = -Infinity;
        let bottom = top;
        for (const color of laneColors) {
            const lane = laneTrees.get(color)!;
            const link = lane.homeId !== null ? laneLinks.get(lane.homeId) : laneEntries.get(color);
            if (!link || link.band !== band || !members.has(link.parent)) continue;
            const parentPosition = positions.get(link.parent);
            const desired = parentPosition ? parentPosition.x + Math.round(levelGap / 2) : marginX;
            const left = snap(Math.max(desired, laneLeft));
            const placed = placeLane(color, left, top, link.parent);
            laneLeft = placed.right + anchorX + laneGap / 2;
            right = Math.max(right, placed.right);
            bottom = Math.max(bottom, placed.bottom);
        }
        return { right, bottom };
    };

    const bandRect = (top: number, bottom: number, right: number): BandRect => ({
        minX: marginX - anchorX - 20,
        minY: top - anchorY - 20,
        maxX: right - anchorX + 20,
        maxY: bottom + 20,
    });

    // Main band, with the lanes that hang off it.
    let mainBand: BandRect | null = null;
    if (homeId !== null && mainTree) {
        // Patch 20: home starts a few rows down (not further right), with room above it.
        const homeY = cursorY + Math.max(0, options.homeTopRows ?? 0) * rowGap;
        // Patch 22: in the home layout home keeps its spot; holes above it fill the rows above.
        if (!options.homeLayout) cursorY = homeY;
        const { bottom, right } = options.homeLayout ? placeHubTree(homeId, mainTree, cursorY, homeY) : placeTree(homeId, mainTree, cursorY);
        const linked = placeLinkedLanes('main', new Set(mainTree.keys()), bottom + rowGap);
        const bandBottom = Math.max(bottom - anchorY + nodeHeight, linked.bottom);
        mainBand = bandRect(cursorY, bandBottom, Math.max(right, linked.right + anchorX));
        cursorY = bandBottom + bandGap + anchorY + 20;
    }

    // Combat chains not linked to anything: their own area, between the main chain and the side chains.
    let combatBand: BandRect | null = null;
    const unlinked = laneColors.filter((color) => !lanes.some((lane) => lane.color === color));
    const linkedToSide = new Set(
        laneColors.filter((color) => {
            const home = laneTrees.get(color)!.homeId;
            return home !== null ? laneLinks.get(home)?.band === 'side' : laneEntries.get(color)?.band === 'side';
        }),
    );
    const standalone = unlinked.filter((color) => !linkedToSide.has(color));
    if (standalone.length > 0) {
        const top = cursorY + 20;
        let left = marginX;
        let right = marginX;
        let bottom = top;
        for (const color of standalone) {
            const placed = placeLane(color, left, top, null);
            left = snap(placed.right + anchorX + laneGap / 2);
            right = Math.max(right, placed.right);
            bottom = Math.max(bottom, placed.bottom);
        }
        combatBand = { minX: marginX - anchorX - 20, minY: top - anchorY - 40, maxX: right + 20, maxY: bottom + 16 };
        cursorY = bottom + bandGap + anchorY + 20;
    }

    // Side chains, each with the lanes that hang off it.
    let sideBand: BandRect | null = null;
    if (sideTrees.length > 0) {
        const sideTop = cursorY;
        let sideBottom = cursorY;
        let sideRight = marginX;
        for (const { root, tree } of sideTrees) {
            const { bottom, right } = options.homeLayout ? placeHubTree(root, tree, cursorY, cursorY) : placeTree(root, tree, cursorY);
            const linked = placeLinkedLanes('side', new Set(tree.keys()), bottom + rowGap);
            const blockBottom = Math.max(bottom - anchorY + nodeHeight, linked.bottom);
            sideBottom = blockBottom;
            sideRight = Math.max(sideRight, right, linked.right + anchorX);
            cursorY = blockBottom + rowGap + anchorY;
        }
        sideBand = bandRect(sideTop, sideBottom, sideRight);
        cursorY = sideBottom + bandGap + anchorY;
    }

    // A lane that should have hung off a band but found no place there: below everything, on its own.
    let strayLeft = marginX;
    let strayBottom = -Infinity;
    for (const color of laneColors) {
        if (lanes.some((lane) => lane.color === color)) continue;
        const placed = placeLane(color, strayLeft, cursorY + 20, null);
        strayLeft = snap(placed.right + anchorX + laneGap / 2);
        strayBottom = Math.max(strayBottom, placed.bottom);
    }
    if (strayBottom > -Infinity) cursorY = strayBottom + bandGap + anchorY;

    // Anything left (a placeholder without a placed parent): park it below everything.
    let parkX = marginX;
    const parkY = snap(cursorY);
    for (const node of input.nodes) {
        if (positions.has(node.id)) continue;
        positions.set(node.id, { x: snap(parkX), y: parkY });
        parkX += levelGap / 2;
    }

    return { positions, ghosts, lanes, mainBand, sideBand, combatBand, parentOf, bandOf, sideChains, hubLanes, anchorId: options.homeLayout ? homeId : null };
}

/** Patch 15: unjumped holes beside a rage-lane system (compact 100×26, stacked down; patch 16: 10 px clear of the next column). */
const LANE_HOLE_GAP = 10;
const LANE_HOLE_WIDTH = 100;
const LANE_HOLE_STEP = 34;

/** Every system reachable from `start` through systems that pass `allowed`. */
function collectComponent(start: number, adjacency: Map<number, number[]>, allowed: (id: number) => boolean): number[] {
    const seen = new Set<number>([start]);
    const queue = [start];
    while (queue.length > 0) {
        const id = queue.shift()!;
        for (const neighbour of adjacency.get(id) ?? []) {
            if (seen.has(neighbour) || !allowed(neighbour)) continue;
            seen.add(neighbour);
            queue.push(neighbour);
        }
    }
    return [...seen];
}

/**
 * Where a side chain starts: a pinned system, else a system without an alias
 * (the chain's start: its holes are numbered from it), else the shortest alias.
 */
function pickSideRoot(component: number[], byId: Map<number, BandLayoutNode>, adjacency: Map<number, number[]>): number {
    const real = component.filter((id) => !byId.get(id)?.placeholder);
    const candidates = real.length > 0 ? real : component;
    const pinned = candidates.filter((id) => byId.get(id)?.pinned);
    if (pinned.length > 0) return Math.min(...pinned);
    const score = (id: number): [number, number, number] => {
        const alias = (byId.get(id)?.alias ?? '').trim();
        return [alias ? 1 : 0, alias.length, -(adjacency.get(id)?.length ?? 0)];
    };
    return candidates.toSorted((a, b) => {
        const [a1, a2, a3] = score(a);
        const [b1, b2, b3] = score(b);
        return a1 - b1 || a2 - b2 || a3 - b3 || a - b;
    })[0];
}

type RTNode = {
    id: number;
    parent: RTNode | null;
    children: RTNode[];
    siblingIndex: number;
    prelim: number;
    mod: number;
    change: number;
    shift: number;
    thread: RTNode | null;
    ancestor: RTNode;
    cross: number;
};

/**
 * Reingold–Tilford (Buchheim et al.) cross-axis placement of one tree: each
 * subtree is packed against its siblings' contours, `gap` apart, parents
 * centred on their children. Returns id → cross (y) offset.
 */
export function reingoldTilford(rootId: number, childrenOf: Map<number, number[]>, gap: number): Map<number, number> {
    const nodes = new Map<number, RTNode>();
    const make = (id: number): RTNode => {
        const node: RTNode = { id, parent: null, children: [], siblingIndex: 1, prelim: 0, mod: 0, change: 0, shift: 0, thread: null, ancestor: null as unknown as RTNode, cross: 0 };
        node.ancestor = node;
        nodes.set(id, node);
        return node;
    };
    const build = (id: number): RTNode => {
        const node = make(id);
        (childrenOf.get(id) ?? []).forEach((childId, index) => {
            const child = build(childId);
            child.parent = node;
            child.siblingIndex = index + 1;
            node.children.push(child);
        });
        return node;
    };
    const root = build(rootId);

    const nextLeft = (node: RTNode): RTNode | null => node.children[0] ?? node.thread;
    const nextRight = (node: RTNode): RTNode | null => node.children[node.children.length - 1] ?? node.thread;
    const leftSiblingOf = (node: RTNode): RTNode | null => (node.parent && node.siblingIndex > 1 ? node.parent.children[node.siblingIndex - 2] : null);

    const moveSubtree = (left: RTNode, right: RTNode, distance: number): void => {
        const subtrees = right.siblingIndex - left.siblingIndex;
        right.change -= distance / subtrees;
        right.shift += distance;
        left.change += distance / subtrees;
        right.prelim += distance;
        right.mod += distance;
    };
    const ancestorFor = (vInnerMinus: RTNode, node: RTNode, defaultAncestor: RTNode): RTNode =>
        vInnerMinus.ancestor.parent === node.parent ? vInnerMinus.ancestor : defaultAncestor;
    const executeShifts = (node: RTNode): void => {
        let shift = 0;
        let change = 0;
        for (let index = node.children.length - 1; index >= 0; index--) {
            const child = node.children[index];
            child.prelim += shift;
            child.mod += shift;
            change += child.change;
            shift += child.shift + change;
        }
    };
    const apportion = (node: RTNode, defaultAncestor: RTNode): RTNode => {
        const leftSibling = leftSiblingOf(node);
        if (!leftSibling) return defaultAncestor;
        let vInnerPlus = node;
        let vOuterPlus = node;
        let vInnerMinus = leftSibling;
        let vOuterMinus = node.parent!.children[0];
        let sInnerPlus = vInnerPlus.mod;
        let sOuterPlus = vOuterPlus.mod;
        let sInnerMinus = vInnerMinus.mod;
        let sOuterMinus = vOuterMinus.mod;
        while (nextRight(vInnerMinus) && nextLeft(vInnerPlus)) {
            vInnerMinus = nextRight(vInnerMinus)!;
            vInnerPlus = nextLeft(vInnerPlus)!;
            vOuterMinus = nextLeft(vOuterMinus)!;
            vOuterPlus = nextRight(vOuterPlus)!;
            vOuterPlus.ancestor = node;
            const shift = vInnerMinus.prelim + sInnerMinus - (vInnerPlus.prelim + sInnerPlus) + gap;
            if (shift > 0) {
                moveSubtree(ancestorFor(vInnerMinus, node, defaultAncestor), node, shift);
                sInnerPlus += shift;
                sOuterPlus += shift;
            }
            sInnerMinus += vInnerMinus.mod;
            sInnerPlus += vInnerPlus.mod;
            sOuterMinus += vOuterMinus.mod;
            sOuterPlus += vOuterPlus.mod;
        }
        if (nextRight(vInnerMinus) && !nextRight(vOuterPlus)) {
            vOuterPlus.thread = nextRight(vInnerMinus);
            vOuterPlus.mod += sInnerMinus - sOuterPlus;
        } else if (nextLeft(vInnerPlus) && !nextLeft(vOuterMinus)) {
            vOuterMinus.thread = nextLeft(vInnerPlus);
            vOuterMinus.mod += sInnerPlus - sOuterMinus;
            defaultAncestor = node;
        }
        return defaultAncestor;
    };
    const firstWalk = (node: RTNode): void => {
        if (node.children.length === 0) {
            const leftSibling = leftSiblingOf(node);
            node.prelim = leftSibling ? leftSibling.prelim + gap : 0;
            return;
        }
        let defaultAncestor = node.children[0];
        for (const child of node.children) {
            firstWalk(child);
            defaultAncestor = apportion(child, defaultAncestor);
        }
        executeShifts(node);
        const midpoint = (node.children[0].prelim + node.children[node.children.length - 1].prelim) / 2;
        const leftSibling = leftSiblingOf(node);
        if (leftSibling) {
            node.prelim = leftSibling.prelim + gap;
            node.mod = node.prelim - midpoint;
        } else {
            node.prelim = midpoint;
        }
    };
    const secondWalk = (node: RTNode, modSum: number): void => {
        node.cross = node.prelim + modSum;
        for (const child of node.children) secondWalk(child, modSum + node.mod);
    };
    firstWalk(root);
    secondWalk(root, 0);

    const result = new Map<number, number>();
    for (const [id, node] of nodes) result.set(id, node.cross);
    return result;
}
