<script setup lang="ts">
import { isDark } from '@/composables/useIsDark';
import { combatColorHex } from '@/lib/combat';
import { isFrigateHole, pipeWidth } from '@/lib/massEstimate';
import { isK162Frigate } from '@/lib/k162';
import { SHIP_SIZE_LETTERS } from '@/lib/shipSize';
import { ANCHOR_OFFSET } from '@/map/core/coords';
import { CORNER_RADIUS, elbowCorners, farStretchPoint, roundedElbowPath } from '@/map/core/geometry/paths';
import EdgeBadges from '@/map/components/edges/EdgeBadges.vue';
import { badgeSize, type EdgeIndicator } from '@/map/components/edges/badgeWidth';
import { openPlaceholderDetails } from '@/map/components/overlays/placeholderDetails';
import { copyPlaceholderBookmark } from '@/map/holeBookmark';
import usePermission from '@/composables/usePermission';
import type { TPlaceholder } from '@/lib/placeholders';
import { pillSizes, setPillSize, usePillSpots } from '@/map/store/pillLayout';
import { holeFacts } from '@/map/placeholderFacts';
import { useTreeGeometries } from '@/map/store/treeGeometries';
import useUser from '@/composables/useUser';
import { useMapStore } from '@/map/store/mapStore';
import { show } from '@/routes/maps';
import { Link } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

/**
 * Placeholder systems (patch 12): a dashed system for every wormhole signature
 * nobody has jumped yet, where the real system will appear, with a dashed line
 * from the system it was scanned in. Clicking one opens that system's
 * signature list; right-click has its own menu (see PlaceholderContextMenu).
 */
const store = useMapStore();
const user = useUser();
const routed = useTreeGeometries(store);
const pillSpots = usePillSpots(store);
const { canEdit } = usePermission();

const FULL_WIDTH = 180;
/** Patch 16: wider small boxes in rage lanes, so the note fits inside. */
const COMPACT_WIDTH = 100;
const FULL_HEIGHT = 40;

/** Patch 21: the parts of a dotted pipe's pill: Static, its type, its size (or a guess), mass, EOL. */
function pillParts(placeholder: TPlaceholder, facts: ReturnType<typeof holeFacts>): EdgeIndicator[] {
    const neutral = { fill: 'var(--color-neutral-500)', stroke: 'var(--color-neutral-600)' };
    const parts: EdgeIndicator[] = [];
    if (placeholder.isStatic || placeholder.expected) {
        parts.push({ type: 'static', label: 'Static', fill: 'var(--color-green-700)', stroke: 'var(--color-green-800)' });
    } else if (placeholder.maybeStatic) {
        parts.push({ type: 'static', label: 'Static?', strong: true, fill: 'var(--color-yellow-600)', stroke: 'var(--color-yellow-700)' });
    }
    // Patch 21: stacked: the size with a plain arrow (away = it opened here, toward = the K162 side), then the type.
    if (facts.size) parts.push({ type: 'text', label: facts.size.letter, arrow: facts.size.arrow === 'away' ? 'right' : 'left', ...neutral });
    else if (facts.guess?.size) parts.push({ type: 'text', label: `≈ ${SHIP_SIZE_LETTERS[facts.guess.size]}`, fill: 'var(--color-neutral-400)', stroke: 'var(--color-neutral-500)' });
    else if (facts.guess) parts.push({ type: 'text', label: '≈', fill: 'var(--color-neutral-400)', stroke: 'var(--color-neutral-500)' });
    // Kept short so the pill fits between two columns (the K162's range is in the details).
    const typeName = isK162Frigate(facts.holeTypeInfo) ? 'K162 frig' : placeholder.wormhole ? placeholder.wormhole.toUpperCase() : facts.isK162 ? 'K162' : null;
    if (typeName) parts.push({ type: 'text', role: 'type', label: typeName, ...neutral });
    if (!parts.length) parts.push({ type: 'text', label: '?', fill: 'var(--color-neutral-400)', stroke: 'var(--color-neutral-500)' });
    if (placeholder.massStatus === 'reduced' || placeholder.massStatus === 'critical') {
        const critical = placeholder.massStatus === 'critical';
        parts.push({ type: 'weight', fill: critical ? 'var(--color-red-500)' : 'var(--color-amber-500)', stroke: critical ? 'var(--color-red-600)' : 'var(--color-amber-600)' });
    }
    if (placeholder.lifetime === 'eol' || placeholder.lifetime === 'critical') {
        const critical = placeholder.lifetime === 'critical';
        parts.push({ type: 'eol', label: critical ? 'EOL!' : 'EOL', strong: critical, fill: critical ? 'var(--color-fuchsia-600)' : 'var(--color-purple-700)', stroke: 'var(--color-purple-800)' });
    }
    return parts;
}

/** Patch 21: every dotted pipe's pill parts, by placeholder node id (kept apart from the drawing, which moves with zoom). */
const pills = computed(() => {
    const result = new Map<number, EdgeIndicator[]>();
    if (!store.isTreeLayout.value) return result;
    for (const placeholder of store.placeholders.value) {
        const parent = store.systems.get(placeholder.parentId);
        if (parent) result.set(placeholder.nodeId, pillParts(placeholder, holeFacts(placeholder, parent)));
    }
    return result;
});

// Each dotted pipe reports its pill's width, so all pills are placed together (see usePillSpots).
watch(
    pills,
    (current) => {
        const sizes = pillSizes(store);
        for (const id of [...sizes.keys()]) {
            if (id < 0 && !current.has(id)) setPillSize(store, id, null);
        }
        for (const [id, parts] of current) setPillSize(store, id, badgeSize(parts));
    },
    { immediate: true },
);

function openDetails(event: MouseEvent, nodeId: number): void {
    openPlaceholderDetails.value = { nodeId, x: event.clientX, y: event.clientY };
}

/** Patch 21: clicking the green signature ID copies the hole's bookmark (like right-click → Copy bookmark). */
function copyBookmark(placeholder: TPlaceholder): void {
    copyPlaceholderBookmark(store, placeholder, canEdit.value);
}

const items = computed(() => {
    const layout = store.bandLayout.value;
    const meta = store.meta.value;
    if (!layout || !meta || !store.isTreeLayout.value) return [];
    const scale = store.scale.value;

    return store.placeholders.value.flatMap((placeholder) => {
        const position = store.renderPosition(placeholder.nodeId);
        const parentPosition = store.renderPosition(placeholder.parentId);
        const parent = store.systems.get(placeholder.parentId);
        if (!position || !parentPosition || !parent) return [];

        const hex = combatColorHex(placeholder.color);
        // Small beside a rage-lane system; an armed one is full size, right below (patch 16).
        const compact = layout.bandOf.get(placeholder.nodeId) === 'lane' && !placeholder.armedBy;
        const NODE_WIDTH = compact ? COMPACT_WIDTH : FULL_WIDTH;
        const NODE_HEIGHT = compact ? 26 : FULL_HEIGHT;
        const parentSize = store.nodeSizes.get(placeholder.parentId) ?? { width: FULL_WIDTH, height: FULL_HEIGHT };
        const parentLeft = parentPosition.x - ANCHOR_OFFSET.x;
        const parentTop = parentPosition.y - ANCHOR_OFFSET.y;
        const left = position.x - ANCHOR_OFFSET.x;
        const top = position.y - ANCHOR_OFFSET.y;

        // Patch 13: a striped pipe sized by the hole's type (full mass, nothing jumped
        // yet), colored by its mass status, with a purple edge when end of life.
        // Patch 18: a K162 or an untyped hole gets a guessed size ("≈"); only a hole with
        // no known class at all keeps the thin dotted line, which now shows mass and EOL too.
        const facts = holeFacts(placeholder, parent);
        const typeMass = facts.typeMass;
        const guessed = facts.guess?.total ?? null;
        const mass = typeMass ?? (guessed ? { total: guessed, maxJump: Number.POSITIVE_INFINITY } : null);
        const massColor = placeholder.massStatus === 'critical' ? '#ef4444' : placeholder.massStatus === 'reduced' ? '#f59e0b' : null;
        const eol = placeholder.lifetime === 'eol' || placeholder.lifetime === 'critical';
        const eolCritical = placeholder.lifetime === 'critical';
        const pipe = mass
            ? {
                  width: isFrigateHole(mass.maxJump) ? 2 : pipeWidth(mass.total * 1.1) * Math.min(scale, 1.5) * (compact ? 0.5 : 1),
                  color: massColor ?? (isDark.value ? '#a3a3a3' : '#57534e'),
                  eol,
                  eolCritical,
              }
            : null;
        const strokeWidth = pipe?.width ?? 1.5;

        // Patch 20: routed with the jumped pipes (same exits out of the top / right / bottom,
        // same column gaps), then drawn from the box back to its system so the stripes start
        // whole at the box and stop a few px short of its border (patch 17).
        const wantedGap = 3 + strokeWidth / 2;
        let rounded = true;
        let points: { x: number; y: number }[];
        const geometry = routed.value?.get(placeholder.nodeId);
        if (geometry && geometry.kind === 'elbow') {
            const from = { x: geometry.from.x * scale, y: geometry.from.y * scale };
            const to = { x: geometry.to.x * scale, y: geometry.to.y * scale };
            const corners = elbowCorners({ ...geometry, from, to, bend: geometry.bend === null ? null : geometry.bend * scale });
            const scaled = (point: { x: number; y: number }) => ({ x: point.x * scale, y: point.y * scale });
            points = [
                ...(geometry.start ? [scaled(geometry.start.point)] : []),
                from,
                corners[0],
                corners[1],
                to,
                ...(geometry.end ? [scaled(geometry.end.point)] : []),
            ].filter((point, i, all) => i === 0 || Math.hypot(point.x - all[i - 1].x, point.y - all[i - 1].y) > 0.01);
            // The router goes system → box: draw box → system.
            points.reverse();
        } else {
            points = [
                { x: left * scale, y: (top + NODE_HEIGHT / 2) * scale },
                { x: (parentLeft + parentSize.width) * scale, y: (parentTop + parentSize.height / 2) * scale },
            ];
        }
        if (points.length >= 2) {
            const [first, second] = points;
            const length = Math.hypot(second.x - first.x, second.y - first.y);
            rounded = length > wantedGap + 6;
            if (rounded) {
                points[0] = { x: first.x + ((second.x - first.x) / length) * wantedGap, y: first.y + ((second.y - first.y) / length) * wantedGap };
            }
        }
        const path = roundedElbowPath(points, Math.max(CORNER_RADIUS, strokeWidth));
        // Patch 21: every dotted pipe has a pill, placed with all the others (clear of bends, boxes, pills).
        const spot = pillSpots.value?.get(placeholder.nodeId);
        const fallback = points.length >= 2 ? farStretchPoint(points) : null;
        const pillAt = spot ?? (fallback ? { ...fallback, dot: false } : null);
        const pill = pillAt ? { parts: pills.value.get(placeholder.nodeId) ?? [], center: { x: pillAt.x, y: pillAt.y }, dot: pillAt.dot } : null;
        // Round stripe ends add half the width at each end: the dash is shortened by the width,
        // and wide pipes get longer stripes so they stay stripes, not dots.
        const [dash, gap] = pipe ? (pipe.width > 4 ? [16, 11] : [6, 6]) : [4, 4];
        const dashArray = pipe && rounded ? `${Math.max(dash - strokeWidth, strokeWidth * 0.5)},${gap + strokeWidth}` : `${dash},${gap}`;
        const lineCap: 'round' | 'butt' = pipe && rounded ? 'round' : 'butt';

        return [
            {
                ...placeholder,
                hex,
                path,
                pipe,
                dashArray,
                lineCap,
                pill,
                // Patch 18: the thin dotted line (no class known) shows mass and EOL as well.
                thin: { color: massColor, eol, eolCritical },
                href: show(meta.slug, { mergeQuery: { solarsystem_id: parent.solarsystem_id } }),
                style: {
                    transform: `translate(${left * scale}px, ${top * scale}px)`,
                    width: `${NODE_WIDTH * scale}px`,
                    height: `${NODE_HEIGHT * scale}px`,
                    ...(hex ? { borderColor: `${hex}b3` } : {}),
                    // Patch 18: a static nobody has identified yet: faint green, like the signature pills.
                    ...(placeholder.expected ? { borderStyle: 'dotted', borderColor: 'rgba(34, 197, 94, 0.55)', backgroundColor: 'rgba(12, 26, 16, 0.7)', color: '#dcfce7' } : {}),
                    // Patch 13: armed as someone's next jump.
                    ...(placeholder.armedBy ? { borderColor: '#ef4444', borderWidth: '2px', borderStyle: 'dashed' } : {}),
                },
                fontScale: scale * (compact ? 0.8 : 1),
                armed: placeholder.armedBy
                    ? placeholder.armedBy === user.value?.id
                        ? 'armed · you'
                        : `armed · ${placeholder.armedByName ?? '?'}`
                    : null,
                title: placeholder.expected
                    ? `${placeholder.label || 'Static'}: this static isn't scanned yet${placeholder.note ? ' (it may be the hole you came in by)' : ''}`
                    : placeholder.label
                      ? `${placeholder.label}: not jumped yet (click to see its signature)`
                      : 'Not jumped yet (click to see its signature)',
                compact,
            },
        ];
    });
});

/** Patch 15: one chip per rage-lane system with folded holes, and a fold chip under opened ones. */
const chips = computed(() => {
    if (!store.isTreeLayout.value) return [];
    const scale = store.scale.value;
    const visibleCount = new Map<number, number>();
    // Patch 16: armed holes sit below their system, not in the stack beside it.
    for (const placeholder of store.placeholders.value) if (!placeholder.armedBy) visibleCount.set(placeholder.parentId, (visibleCount.get(placeholder.parentId) ?? 0) + 1);
    const result: { parentId: number; label: string; open: boolean; style: Record<string, string> }[] = [];
    const place = (parentId: number, label: string, open: boolean) => {
        const position = store.renderPosition(parentId);
        if (!position) return;
        const left = position.x - ANCHOR_OFFSET.x + FULL_WIDTH + 10;
        const top = position.y - ANCHOR_OFFSET.y + (visibleCount.get(parentId) ?? 0) * 34 + 4;
        result.push({
            parentId,
            label,
            open,
            style: { transform: `translate(${left * scale}px, ${top * scale}px)`, fontSize: `${10 * Math.min(scale, 1.5)}px` },
        });
    };
    for (const [parentId, count] of store.foldedHoles.value) place(parentId, `+${count} hole${count === 1 ? '' : 's'} ▸`, false);
    for (const parentId of store.openedHoleParents.value) {
        if (!store.foldedHoles.value.has(parentId) && (visibleCount.get(parentId) ?? 0) > 0 && store.systems.get(parentId)?.combat_color) place(parentId, 'fold ▴', true);
    }
    return result;
});
</script>

<template>
    <div v-if="items.length" class="pointer-events-none absolute inset-0">
        <svg class="absolute inset-0 h-full w-full overflow-visible" xmlns="http://www.w3.org/2000/svg">
            <template v-for="item in items" :key="`line-${item.nodeId}`">
                <template v-if="item.pipe">
                    <!-- Patch 18: a static nobody has identified yet: a soft green band under the stripes -->
                    <path
                        v-if="item.expected"
                        :d="item.path"
                        fill="none"
                        stroke="#16a34a"
                        :stroke-width="item.pipe.width + 8"
                        stroke-opacity="0.22"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                    <path
                        v-if="item.pipe.eol"
                        :d="item.path"
                        fill="none"
                        :stroke="item.pipe.eolCritical ? '#d946ef' : '#a855f7'"
                        :stroke-width="item.pipe.width + 4"
                        stroke-opacity="0.85"
                        :stroke-dasharray="item.dashArray"
                        :stroke-linecap="item.lineCap"
                        stroke-linejoin="round"
                    />
                    <path
                        :d="item.path"
                        fill="none"
                        :stroke="item.pipe.color"
                        :stroke-width="item.pipe.width"
                        :stroke-opacity="item.pipe.eol ? 0.75 : 0.45"
                        :stroke-dasharray="item.dashArray"
                        :stroke-linecap="item.lineCap"
                        stroke-linejoin="round"
                    />
                </template>
                <template v-else>
                    <!-- Patch 18: the thin line shows EOL (purple edge) and mass (amber / red) too -->
                    <path
                        v-if="item.thin.eol"
                        :d="item.path"
                        fill="none"
                        :stroke="item.thin.eolCritical ? '#d946ef' : '#a855f7'"
                        stroke-width="5"
                        stroke-dasharray="4,4"
                        stroke-opacity="0.85"
                    />
                    <path
                        :d="item.path"
                        fill="none"
                        :stroke="item.thin.color ?? item.hex ?? 'currentColor'"
                        class="text-stone-600 dark:text-neutral-600"
                        :stroke-width="item.thin.color ? 2.5 : 1.5"
                        stroke-dasharray="4,4"
                        :stroke-opacity="item.thin.color ? 1 : 0.7"
                    />
                </template>
                <!-- Patch 21: every dotted pipe's pill (Static, type, size, mass, EOL); click for details -->
                <EdgeBadges
                    v-if="item.pill && item.pill.parts.length"
                    :indicators="item.pill.parts"
                    :center="item.pill.center"
                    :dot="item.pill.dot"
                    :scale="store.scale.value"
                    clickable
                    title="Click for details"
                    @open="(event) => openDetails(event, item.nodeId)"
                />
            </template>
        </svg>
        <Link
            v-for="item in items"
            :key="item.nodeId"
            :href="item.href"
            preserve-state
            preserve-scroll
            :only="['map', 'selected_map_solarsystem', 'map_navigation', 'map_characters', 'eve_scout_connections', 'threat_analysis']"
            :data-placeholder-id="item.signatureId > 0 ? item.signatureId : undefined"
            :title="item.title"
            class="pointer-events-auto absolute top-0 left-0 flex flex-col items-center justify-center rounded border border-dashed border-stone-500 bg-[#fbf7ef] leading-tight text-stone-800 transition-colors hover:bg-white dark:border-neutral-600 dark:bg-neutral-900/40 dark:text-neutral-300 dark:hover:bg-neutral-900/80"
            :style="item.style"
        >
            <template v-if="item.compact">
                <span class="flex w-full min-w-0 items-center justify-between gap-1 px-1.5">
                    <span class="flex min-w-0 items-center gap-1">
                        <span v-if="item.label" class="truncate font-display font-semibold" :style="{ fontSize: `${13 * item.fontScale}px` }">{{ item.label }}</span>
                        <!-- Patch 17: the signature ID, bigger, on green: a hole you can warp to -->
                        <span
                            v-if="item.sigCode"
                            role="button"
                            title="Copy bookmark"
                            class="shrink-0 cursor-copy rounded-[3px] bg-green-800 px-1 font-mono leading-tight font-bold text-green-100 hover:bg-green-700"
                            :style="{ fontSize: `${12 * item.fontScale}px` }"
                            @click.stop.prevent="copyBookmark(item)"
                            >{{ item.sigCode }}</span
                        >
                    </span>
                    <!-- Patch 16: the note sits inside the small box (it overlapped the box above) -->
                    <span v-if="item.note" class="truncate font-medium text-amber-600 dark:text-amber-400" :style="{ fontSize: `${10 * item.fontScale}px` }">
                        {{ item.note.replace(/^maybe /, '').replace(/^\*/, '') }}
                    </span>
                    <span v-else class="truncate font-mono text-muted-foreground" :style="{ fontSize: `${10 * item.fontScale}px` }">{{
                        item.sigCode ? item.destination : item.detail
                    }}</span>
                </span>
            </template>
            <template v-else>
                <span class="font-display font-semibold" :style="{ fontSize: `${13 * item.fontScale}px` }">{{ item.label || '\u00a0' }}</span>
                <span v-if="item.sigCode" class="flex items-center gap-1 font-mono text-muted-foreground" :style="{ fontSize: `${11 * item.fontScale}px` }">
                    <span
                        role="button"
                        title="Copy bookmark"
                        class="cursor-copy rounded bg-green-800 px-1.5 leading-tight font-bold text-green-100 hover:bg-green-700"
                        :style="{ fontSize: `${13 * item.fontScale}px` }"
                        @click.stop.prevent="copyBookmark(item)"
                        >{{ item.sigCode }}</span
                    >
                    · {{ item.destination }}
                </span>
                <span v-else class="font-mono text-muted-foreground" :style="{ fontSize: `${11 * item.fontScale}px` }">{{ item.detail }}</span>
            </template>
            <span
                v-if="item.armed"
                class="absolute -top-2 left-1 rounded-full bg-red-500 px-1.5 leading-tight font-medium text-white"
                :style="{ fontSize: `${9 * item.fontScale}px` }"
            >
                {{ item.armed }}
            </span>
            <span
                v-if="item.note && !item.compact"
                class="absolute -top-2 right-1 rounded-full border border-amber-500/60 bg-amber-100 px-1.5 leading-tight font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-300"
                :style="{ fontSize: `${9 * item.fontScale}px` }"
            >
                {{ item.note }}
            </span>
        </Link>
    </div>
    <!-- Patch 15: folded holes of a rage-lane system ("+3 holes ▸"), and the fold button of an opened one -->
    <div v-if="chips.length" class="pointer-events-none absolute inset-0">
        <button
            v-for="chip in chips"
            :key="`chip-${chip.parentId}`"
            type="button"
            class="pointer-events-auto absolute top-0 left-0 rounded-full border border-neutral-400/60 bg-neutral-100/90 px-2 leading-tight font-medium text-neutral-700 hover:bg-white dark:border-neutral-600 dark:bg-neutral-900/90 dark:text-neutral-300 dark:hover:bg-neutral-800"
            :style="chip.style"
            :title="chip.open ? 'Fold these unjumped holes back into one chip' : 'Show the unjumped holes of this system'"
            @click.stop="store.toggleHoleParent(chip.parentId)"
        >
            {{ chip.label }}
        </button>
    </div>
</template>
