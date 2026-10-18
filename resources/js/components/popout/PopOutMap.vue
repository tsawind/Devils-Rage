<script setup lang="ts">
import { classMeta } from '@/const/solarsystemClasses';
import { useStaticSolarsystems } from '@/composables/useStaticSolarsystems';
import { displayAlias } from '@/lib/alias';
import { DEFAULT_NODE, nearbySystems, viewBoxAround, type TPopNode } from '@/lib/popOutMap';
import { ANCHOR_OFFSET } from '@/map/core/coords';
import { tryUseMapStore } from '@/map/store/mapStore';
import type { TStringedSolarsystemClass } from '@/types/models';
import { computed } from 'vue';

/**
 * Patch 39: the pop-out's map: the map's own systems and pipes within two jumps of the
 * character, laid out as on the main map, centred on the character's system (a red dot),
 * so each jump moves the view one system over. Pipes show mass (amber reduced, red
 * critical) and end of life (dashed).
 */
const { mapSolarsystemId, color } = defineProps<{ mapSolarsystemId: number | null; color: string }>();
const emit = defineEmits<{ select: [solarsystemId: number] }>();

const store = computed(() => tryUseMapStore());
const { resolveSolarsystem } = useStaticSolarsystems();

const edges = computed(() => [...(store.value?.connections.values() ?? [])].map((connection) => ({ from: connection.from_map_solarsystem_id, to: connection.to_map_solarsystem_id, connection })));

/** Where the main map draws a system (tree layout included), as a box from its top-left corner. */
function nodeOf(id: number): TPopNode | null {
    const anchor = store.value?.renderPosition(id);
    if (!anchor) return null;
    const size = store.value?.nodeSizes.get(id);
    return { id, x: anchor.x - ANCHOR_OFFSET.x, y: anchor.y - ANCHOR_OFFSET.y, w: size?.width ?? DEFAULT_NODE.w, h: size?.height ?? DEFAULT_NODE.h };
}

const shown = computed(() => {
    if (!mapSolarsystemId || !store.value) return null;
    const centre = nodeOf(mapSolarsystemId);
    if (!centre) return null;
    const ids = nearbySystems(mapSolarsystemId, edges.value, 2);
    const nodes = [...ids].map(nodeOf).filter((node): node is TPopNode => node !== null);
    const box = viewBoxAround(centre, nodes);
    return {
        centre,
        box,
        nodes: nodes.map((node) => {
            const system = store.value!.systems.get(node.id);
            const resolved = system ? resolveSolarsystem(system.solarsystem_id) : null;
            const klass = (resolved?.class ?? null) as TStringedSolarsystemClass | null;
            const meta = classMeta(klass);
            return {
                ...node,
                alias: displayAlias(system?.alias) || resolved?.name || '?',
                name: resolved?.name ?? '',
                solarsystemId: system?.solarsystem_id ?? 0,
                classLabel: meta.short_label,
                classColor: `var(--color-${meta.color_token})`,
            };
        }),
        pipes: edges.value
            .filter((edge) => ids.has(edge.from) && ids.has(edge.to))
            .map((edge) => {
                const a = nodeOf(edge.from);
                const b = nodeOf(edge.to);
                if (!a || !b) return null;
                const mass = edge.connection.mass_status;
                return {
                    id: edge.connection.id,
                    x1: a.x + a.w / 2,
                    y1: a.y + a.h / 2,
                    x2: b.x + b.w / 2,
                    y2: b.y + b.h / 2,
                    stroke: mass === 'critical' ? '#ef4444' : mass === 'reduced' ? '#f59e0b' : '#737373',
                    eol: edge.connection.lifetime_status === 'eol',
                };
            })
            .filter((pipe): pipe is NonNullable<typeof pipe> => pipe !== null),
    };
});
</script>

<template>
    <div class="relative h-full w-full bg-black">
        <svg v-if="shown" class="h-full w-full" :viewBox="`${shown.box.x} ${shown.box.y} ${shown.box.w} ${shown.box.h}`" preserveAspectRatio="xMidYMid meet">
            <line
                v-for="pipe in shown.pipes"
                :key="pipe.id"
                :x1="pipe.x1"
                :y1="pipe.y1"
                :x2="pipe.x2"
                :y2="pipe.y2"
                :stroke="pipe.stroke"
                stroke-width="4"
                :stroke-dasharray="pipe.eol ? '10 8' : undefined"
            />
            <g v-for="node in shown.nodes" :key="node.id" class="cursor-pointer" @click="emit('select', node.solarsystemId)">
                <title>{{ node.alias }} · {{ node.name }} · {{ node.classLabel }}</title>
                <rect
                    :x="node.x"
                    :y="node.y"
                    :width="node.w"
                    :height="node.h"
                    rx="6"
                    fill="#171717"
                    :stroke="node.id === mapSolarsystemId ? color : '#404040'"
                    :stroke-width="node.id === mapSolarsystemId ? 4 : 2"
                />
                <text :x="node.x + 8" :y="node.y + node.h / 2 - 2" fill="#fafafa" font-size="16" font-weight="700">{{ node.alias }}</text>
                <text :x="node.x + 8" :y="node.y + node.h / 2 + 14" font-size="11" :fill="node.classColor">
                    {{ node.classLabel }} <tspan fill="#a3a3a3">{{ node.name }}</tspan>
                </text>
                <circle v-if="node.id === mapSolarsystemId" :cx="node.x + node.w - 10" :cy="node.y + 10" r="5" fill="#ef4444" />
            </g>
        </svg>
        <div v-else class="flex h-full items-center justify-center px-3 text-center text-xs text-neutral-500">Not on the map yet</div>
    </div>
</template>
