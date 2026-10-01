<script setup lang="ts">
import { combatColorHex } from '@/lib/combat';
import { isFrigateHole, pipeWidth } from '@/lib/massEstimate';
import { wormholeMass } from '@/lib/wormholeMass';
import { ANCHOR_OFFSET } from '@/map/core/coords';
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

const NODE_WIDTH = 180;
const NODE_HEIGHT = 40;

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
        const parentSize = store.nodeSizes.get(placeholder.parentId) ?? { width: NODE_WIDTH, height: NODE_HEIGHT };
        const parentLeft = parentPosition.x - ANCHOR_OFFSET.x;
        const parentTop = parentPosition.y - ANCHOR_OFFSET.y;
        const left = position.x - ANCHOR_OFFSET.x;
        const top = position.y - ANCHOR_OFFSET.y;

        // Straight down when it sits under its system, else out of the system's right side.
        let path: string;
        if (left === parentLeft && top > parentTop) {
            const x = (left + 50) * scale;
            path = `M ${x} ${(parentTop + parentSize.height) * scale} V ${top * scale}`;
        } else {
            const startX = parentLeft + parentSize.width;
            const startY = parentTop + parentSize.height / 2;
            const endY = top + NODE_HEIGHT / 2;
            const middleX = left > startX ? startX + Math.min(40, (left - startX) / 2) : startX + 20;
            path = `M ${startX * scale} ${startY * scale} H ${middleX * scale} V ${endY * scale} H ${left * scale}`;
        }

        // Patch 13: a striped pipe sized by the hole's type (full mass, nothing jumped
        // yet), colored by its mass status, with a purple edge when end of life.
        // A K162 or unknown type keeps the thin dotted line.
        const mass = wormholeMass(placeholder.wormhole);
        const pipe = mass
            ? {
                  width: isFrigateHole(mass.maxJump) ? 2 : pipeWidth(mass.total * 1.1) * Math.min(scale, 1.5),
                  color: placeholder.massStatus === 'critical' ? '#ef4444' : placeholder.massStatus === 'reduced' ? '#f59e0b' : '#a3a3a3',
                  eol: placeholder.lifetime === 'eol' || placeholder.lifetime === 'critical',
                  eolCritical: placeholder.lifetime === 'critical',
              }
            : null;

        return [
            {
                ...placeholder,
                hex,
                path,
                pipe,
                tag: pipe?.eol ? { text: pipe.eolCritical ? 'EOL!' : 'EOL', x: (left - 6) * scale, y: (top + NODE_HEIGHT / 2) * scale } : null,
                href: show(meta.slug, { mergeQuery: { solarsystem_id: parent.solarsystem_id } }),
                style: {
                    transform: `translate(${left * scale}px, ${top * scale}px)`,
                    width: `${NODE_WIDTH * scale}px`,
                    height: `${NODE_HEIGHT * scale}px`,
                    ...(hex ? { borderColor: `${hex}b3` } : {}),
                },
                fontScale: scale,
            },
        ];
    });
});
</script>

<template>
    <div v-if="items.length" class="pointer-events-none absolute inset-0">
        <svg class="absolute inset-0 h-full w-full overflow-visible" xmlns="http://www.w3.org/2000/svg">
            <template v-for="item in items" :key="`line-${item.nodeId}`">
                <template v-if="item.pipe">
                    <path
                        v-if="item.pipe.eol"
                        :d="item.path"
                        fill="none"
                        :stroke="item.pipe.eolCritical ? '#d946ef' : '#a855f7'"
                        :stroke-width="item.pipe.width + 4"
                        stroke-opacity="0.85"
                        :stroke-dasharray="item.pipe.width > 4 ? '16,11' : '6,6'"
                    />
                    <path
                        :d="item.path"
                        fill="none"
                        :stroke="item.pipe.color"
                        :stroke-width="item.pipe.width"
                        :stroke-opacity="item.pipe.eol ? 0.75 : 0.45"
                        :stroke-dasharray="item.pipe.width > 4 ? '16,11' : '6,6'"
                    />
                </template>
                <path
                    v-else
                    :d="item.path"
                    fill="none"
                    :stroke="item.hex ?? 'currentColor'"
                    class="text-neutral-400 dark:text-neutral-600"
                    stroke-width="1.5"
                    stroke-dasharray="4,4"
                    stroke-opacity="0.7"
                />
                <text
                    v-if="item.tag"
                    :x="item.tag.x"
                    :y="item.tag.y"
                    text-anchor="end"
                    dominant-baseline="middle"
                    :fill="item.tag.text === 'EOL!' ? '#d946ef' : '#a855f7'"
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
            :data-placeholder-id="item.signatureId"
            :title="item.label ? `${item.label}: not jumped yet (click to see its signature)` : 'Not jumped yet (click to see its signature)'"
            class="pointer-events-auto absolute top-0 left-0 flex flex-col items-center justify-center rounded border border-dashed border-neutral-400 bg-white/40 leading-tight text-neutral-600 transition-colors hover:bg-white/80 dark:border-neutral-600 dark:bg-neutral-900/40 dark:text-neutral-300 dark:hover:bg-neutral-900/80"
            :style="item.style"
        >
            <span class="font-medium" :style="{ fontSize: `${12 * item.fontScale}px` }">{{ item.label || '\u00a0' }}</span>
            <span class="font-mono text-muted-foreground" :style="{ fontSize: `${10 * item.fontScale}px` }">{{ item.detail }}</span>
        </Link>
    </div>
</template>
