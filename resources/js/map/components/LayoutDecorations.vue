<script setup lang="ts">
import { displayAlias } from '@/lib/alias';
import { combatColorHex, combatColorLabel } from '@/lib/combat';
import { ANCHOR_OFFSET } from '@/map/core/coords';
import type { BandRect } from '@/map/core/layout/bandLayout';
import { useMapStore } from '@/map/store/mapStore';
import { computed } from 'vue';

/**
 * Patch 12 layout chrome behind the systems: faint outlines for the main band,
 * the side chains and each combat lane (in its chain color), and ghosts that
 * hold a spot (Alpha's row, a combat home's place in the main chain).
 * Tree layout only.
 */
const store = useMapStore();

const layout = computed(() => (store.isTreeLayout.value ? store.bandLayout.value : null));
const scale = computed(() => store.scale.value);

function boxStyle(rect: BandRect): Record<string, string> {
    const s = scale.value;
    return {
        left: `${rect.minX * s}px`,
        top: `${rect.minY * s}px`,
        width: `${(rect.maxX - rect.minX) * s}px`,
        height: `${(rect.maxY - rect.minY) * s}px`,
    };
}

const bands = computed(() => {
    const current = layout.value;
    if (!current) return [];
    const result: { key: string; label: string; style: Record<string, string> }[] = [];
    // The main band only gets an outline once there is something else to set it apart from.
    const others = Boolean(current.sideBand) || current.lanes.length > 0;
    if (current.mainBand && others) result.push({ key: 'main', label: 'Main chain', style: boxStyle(current.mainBand) });
    if (current.combatBand) result.push({ key: 'combat', label: 'Rage chains (not linked)', style: boxStyle(current.combatBand) });
    if (current.sideBand) result.push({ key: 'side', label: 'Side chains', style: boxStyle(current.sideBand) });
    return result;
});

const lanes = computed(() =>
    (layout.value?.lanes ?? []).map((lane) => {
        const hex = combatColorHex(lane.color) ?? '#888888';
        const home = lane.homeId !== null ? store.systems.get(lane.homeId) : undefined;
        const workers = home?.combat_workers ?? [];
        return {
            key: lane.color,
            hex,
            label: `Rage · ${combatColorLabel(lane.color) ?? lane.color}${workers.length ? ` · ${workers.join(', ')}` : ''}`,
            style: { ...boxStyle(lane), borderColor: `${hex}80`, backgroundColor: `${hex}0f` },
        };
    }),
);

const ghosts = computed(() =>
    (layout.value?.ghosts ?? []).map((ghost) => {
        const s = scale.value;
        const hex = ghost.color ? combatColorHex(ghost.color) : null;
        return {
            key: ghost.key,
            label: displayAlias(ghost.label) || '—',
            note: ghost.color ? `in the ${combatColorLabel(ghost.color) ?? ghost.color} lane` : ghost.note,
            hex,
            style: {
                transform: `translate(${(ghost.position.x - ANCHOR_OFFSET.x) * s}px, ${(ghost.position.y - ANCHOR_OFFSET.y) * s}px)`,
                width: `${180 * s}px`,
                height: `${40 * s}px`,
                fontSize: `${11 * s}px`,
                ...(hex ? { borderColor: `${hex}99` } : {}),
            },
        };
    }),
);
</script>

<template>
    <div v-if="layout" class="pointer-events-none absolute inset-0">
        <div v-for="band in bands" :key="band.key" class="absolute rounded-xl border border-border/40 bg-muted/10" :style="band.style">
            <span class="absolute top-1 left-2 font-sans font-semibold text-[11px] tracking-wider text-muted-foreground/70 uppercase">{{ band.label }}</span>
        </div>
        <div v-for="lane in lanes" :key="lane.key" class="absolute rounded-xl border" :style="lane.style">
            <span class="absolute top-1 left-2 font-sans font-semibold text-[11px] tracking-wider uppercase" :style="{ color: lane.hex }">{{ lane.label }}</span>
        </div>
        <div
            v-for="ghost in ghosts"
            :key="ghost.key"
            class="absolute top-0 left-0 flex flex-col items-center justify-center rounded-md border border-dashed border-muted-foreground/40 leading-tight text-muted-foreground/70"
            :style="ghost.style"
        >
            <span class="font-medium">{{ ghost.label }}</span>
            <span :style="ghost.hex ? { color: ghost.hex } : undefined">{{ ghost.note }}</span>
        </div>
    </div>
</template>
