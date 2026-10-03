<script setup lang="ts">
import { isDark } from '@/composables/useIsDark';
import { combatColorHex } from '@/lib/combat';
import { isFrigateHole, pipeWidth } from '@/lib/massEstimate';
import { guessHoleMass } from '@/map/holeGuess';
import { getTypesByCategory, signatureCategories, signatureTypeById } from '@/const/signatures';
import { k162ShipSize } from '@/lib/k162';
import { SHIP_SIZE_LETTERS, shipSizeFromJumpMass } from '@/lib/shipSize';
import { wormholeMass } from '@/lib/wormholeMass';
import { ANCHOR_OFFSET } from '@/map/core/coords';
import { CORNER_RADIUS, roundedElbowPath } from '@/map/core/geometry/paths';
import useUser from '@/composables/useUser';
import { useMapStore } from '@/map/store/mapStore';
import { show } from '@/routes/maps';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Placeholder systems (patch 12): a dashed system for every wormhole signature
 * nobody has jumped yet, where the real system will appear, with a dashed line
 * from the system it was scanned in. Clicking one opens that system's
 * signature list; right-click has its own menu (see PlaceholderContextMenu).
 */
const store = useMapStore();
const user = useUser();

const FULL_WIDTH = 180;
/** Patch 16: wider small boxes in rage lanes, so the note fits inside. */
const COMPACT_WIDTH = 100;
const FULL_HEIGHT = 40;

const wormholeTypes = getTypesByCategory(signatureCategories.find((category) => category.code === 'wormhole')?.id ?? 0);

/**
 * Patch 20: the size letter of a dotted pipe when it is known: a typed hole's own
 * size (arrow away: it spawned here), a K162's size worked out from the wormhole data
 * (arrow toward you), a K162 frigate S. Unknown or guessed: no letter.
 */
function knownSize(placeholder: { wormhole: string | null; signatureTypeId?: number | null; targetClass?: string | null }, parentClass: string | null): { letter: string; arrow: string } | null {
    const name = (placeholder.wormhole ?? '').toUpperCase();
    if (!name) return null;
    if (name !== 'K162') {
        const mass = wormholeMass(name);
        const size = mass ? shipSizeFromJumpMass(mass.maxJump) : null;
        return size ? { letter: SHIP_SIZE_LETTERS[size], arrow: '↗' } : null;
    }
    const type =
        (placeholder.signatureTypeId ? signatureTypeById.get(placeholder.signatureTypeId) : null) ??
        ({ id: 0, signature: 'K162', target_class: placeholder.targetClass ?? null, extra: null } as const);
    const size = k162ShipSize(type, parentClass, wormholeTypes);
    return size ? { letter: SHIP_SIZE_LETTERS[size], arrow: '↙' } : null;
}

const items = computed(() => {
    const layout = store.bandLayout.value;
    const meta = store.meta.value;
    if (!layout || !meta || !store.isTreeLayout.value) return [];
    const scale = store.scale.value;

    return store.placeholders.value.flatMap((placeholder) => {
        const position = layout.positions.get(placeholder.nodeId);
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
        const typeMass = wormholeMass(placeholder.wormhole);
        const isK162 = (placeholder.wormhole ?? '').toUpperCase().startsWith('K162');
        const parentClass = parent.solarsystem?.class === undefined || parent.solarsystem?.class === null ? null : String(parent.solarsystem.class);
        const guessed =
            typeMass || placeholder.shipSize === 'frigate' || (placeholder.wormhole && !isK162)
                ? null
                : guessHoleMass({ k162Class: isK162 ? parentClass : null, classes: [parentClass, placeholder.targetClass ?? null] });
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
        const tagParts = [guessed ? '≈' : null, eol ? (eolCritical ? 'EOL!' : 'EOL') : null].filter(Boolean);
        const strokeWidth = pipe?.width ?? 1.5;

        // Patch 17: drawn from the box back to its system, so the stripes start whole at the
        // box and stop a few px short of its border; corners curve like jumped connections.
        // Where the last straight stretch is too short for that (small lane boxes sit just
        // 10 px beside their system), the stripes keep square ends and touch the box as before.
        const wantedGap = 3 + strokeWidth / 2;
        let rounded = true;
        let points: { x: number; y: number }[];
        if (left === parentLeft && top > parentTop) {
            // Straight down when it sits under its system.
            const x = (left + (compact ? 30 : 50)) * scale;
            const room = (top - (parentTop + parentSize.height)) * scale;
            rounded = room > wantedGap + 6;
            points = [
                { x, y: top * scale - (rounded ? wantedGap : 0) },
                { x, y: (parentTop + parentSize.height) * scale },
            ];
        } else {
            // Out of the system's right side, from its own spot: the upper part for holes level
            // or above, the lower part for holes below (the jumped pipes use the middle).
            const startX = parentLeft + parentSize.width;
            const parentMid = parentTop + parentSize.height / 2;
            let endY = top + NODE_HEIGHT / 2;
            const startY = endY > parentMid + 1 ? parentTop + parentSize.height * 0.8 : parentTop + parentSize.height * 0.2;
            // Nearly level: keep it a straight line, entering the box a little off its middle.
            if (Math.abs(endY - startY) < NODE_HEIGHT / 2 - 4) endY = startY;
            const middleX = left > startX ? startX + Math.min(40, (left - startX) / 2) : startX + 20;
            rounded = (left - middleX) * scale > wantedGap + 6;
            points = [
                { x: left * scale - (rounded ? wantedGap : 0), y: endY * scale },
                { x: middleX * scale, y: endY * scale },
                { x: middleX * scale, y: startY * scale },
                { x: startX * scale, y: startY * scale },
            ];
        }
        const path = roundedElbowPath(points, Math.max(CORNER_RADIUS, strokeWidth));
        // Patch 20: the size pill sits on the last straight stretch before the box.
        const size = placeholder.expected ? null : knownSize(placeholder, parentClass);
        const badge = size
            ? {
                  text: `${size.letter} ${size.arrow}`,
                  x: (points[0].x + points[1].x) / 2,
                  y: (points[0].y + points[1].y) / 2,
              }
            : null;
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
                badge,
                tag: tagParts.length ? { text: tagParts.join(' '), eol, x: (left - 6) * scale, y: (top + NODE_HEIGHT / 2) * scale } : null,
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
                <g v-if="item.badge">
                    <rect
                        :x="item.badge.x - 15 * item.fontScale"
                        :y="item.badge.y - 7 * item.fontScale"
                        :width="30 * item.fontScale"
                        :height="14 * item.fontScale"
                        :rx="7 * item.fontScale"
                        class="fill-neutral-100 stroke-neutral-400 dark:fill-neutral-900 dark:stroke-neutral-600"
                        stroke-width="1"
                    />
                    <text
                        :x="item.badge.x"
                        :y="item.badge.y"
                        text-anchor="middle"
                        dominant-baseline="central"
                        class="fill-neutral-700 font-mono dark:fill-neutral-200"
                        :font-size="9 * item.fontScale"
                        font-weight="600"
                    >
                        {{ item.badge.text }}
                    </text>
                </g>
                <text
                    v-if="item.tag"
                    :x="item.tag.x"
                    :y="item.tag.y"
                    text-anchor="end"
                    dominant-baseline="middle"
                    :fill="item.tag.eol ? (item.tag.text.endsWith('EOL!') ? '#d946ef' : '#a855f7') : '#a8a29e'"
                    :font-size="10 * item.fontScale"
                    font-weight="600"
                >
                    {{ item.tag.text }}
                </text>
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
                            class="shrink-0 rounded-[3px] bg-green-800 px-1 font-mono leading-tight font-bold text-green-100"
                            :style="{ fontSize: `${12 * item.fontScale}px` }"
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
                    <span class="rounded bg-green-800 px-1.5 leading-tight font-bold text-green-100" :style="{ fontSize: `${13 * item.fontScale}px` }">{{
                        item.sigCode
                    }}</span>
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
