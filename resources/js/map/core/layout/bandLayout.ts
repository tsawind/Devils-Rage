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
};

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
    const levelGap = snap(options.levelGap ?? 320);
    const rowGap = snap(options.rowGap ?? 100);
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
                if (visited.has(neighbour) || isMember(neighbour)) continue;
                visited.add(neighbour);
                parentOf.set(neighbour, realId);
                if (isHome(neighbour)) {
                    laneLinks.set(neighbour, { parent: realId, band });
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
        for (const link of laneLinks.values()) {
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

    const placeTree = (root: number, childrenOf: Map<number, number[]>, top: number): { bottom: number; right: number } => {
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
            const point = { x: snap(marginX + depthOf.get(id)! * levelGap), y: snap(top + cross.get(id)! - minCross) };
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

    /** One lane at (left, top): first child straight down, every other child a new column to the right. */
    const placeLane = (color: string, left: number, top: number, parentId: number | null): { right: number; bottom: number } => {
        const lane = laneTrees.get(color)!;
        let lastColumn = -1;
        let maxRow = 0;
        const place = (id: number, column: number, row: number): void => {
            positions.set(id, { x: snap(left + column * laneColumnGap), y: snap(top + row * laneRowGap) });
            maxRow = Math.max(maxRow, row);
            (lane.childrenOf.get(id) ?? []).forEach((child, index) => {
                if (index === 0) {
                    place(child, column, row + 1);
                } else {
                    lastColumn += 1;
                    place(child, lastColumn, row + 1);
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
            minY: top - anchorY - 26,
            maxX: left + Math.max(0, lastColumn) * laneColumnGap - anchorX + laneNodeWidth + 14,
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
            const link = lane.homeId !== null ? laneLinks.get(lane.homeId) : undefined;
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
        const { bottom, right } = placeTree(homeId, mainTree, cursorY);
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
            return home !== null && laneLinks.get(home)?.band === 'side';
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
            const { bottom, right } = placeTree(root, tree, cursorY);
            const linked = placeLinkedLanes('side', new Set(tree.keys()), bottom + rowGap);
            const blockBottom = Math.max(bottom - anchorY + nodeHeight, linked.bottom);
            sideBottom = blockBottom;
            sideRight = Math.max(sideRight, right, linked.right + anchorX);
            cursorY = blockBottom + rowGap + anchorY;
        }
        sideBand = bandRect(sideTop, sideBottom, sideRight);
        cursorY = sideBottom + bandGap + anchorY;
    }

    // Anything left (a placeholder without a placed parent): park it below everything.
    let parkX = marginX;
    const parkY = snap(cursorY);
    for (const node of input.nodes) {
        if (positions.has(node.id)) continue;
        positions.set(node.id, { x: snap(parkX), y: parkY });
        parkX += levelGap / 2;
    }

    return { positions, ghosts, lanes, mainBand, sideBand, combatBand, parentOf, bandOf, sideChains };
}

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
