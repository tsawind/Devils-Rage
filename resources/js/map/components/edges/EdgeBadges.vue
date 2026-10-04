<script lang="ts">
export type { EdgeIndicator } from '@/map/components/edges/badgeWidth';
</script>

<script setup lang="ts">
import { badgeLines, badgeSize, type EdgeIndicator } from '@/map/components/edges/badgeWidth';
import type { Vec2 } from '@/map/core/types';
import { ArrowLeft, ArrowRight, Clock, Heart, Orbit, Weight } from 'lucide-vue-next';
import { computed } from 'vue';

type Props = {
    indicators: EdgeIndicator[];
    /** Centre of the pill in screen pixels (already scaled). */
    center: Vec2;
    /** Patch 21: no room for the whole pill: a small dot in its main colour. */
    dot?: boolean;
    /** Patch 21: the pill opens the pipe's details. */
    clickable?: boolean;
    title?: string;
    /** Patch 21b: the pill grows and shrinks with the map's zoom. */
    scale?: number;
};

const { indicators, center, dot = false, clickable = false, title, scale = 1 } = defineProps<Props>();

const emit = defineEmits<{ (e: 'open', event: MouseEvent): void }>();

const size = computed(() => badgeSize(indicators));
const lines = computed(() => badgeLines(indicators));

/** The dot's colour: Static green, else EOL purple, else the first part's. */
const dotColor = computed(() => (indicators.find((item) => item.type === 'static') ?? indicators.find((item) => item.type === 'eol') ?? indicators[0])?.fill ?? 'var(--color-neutral-500)');
</script>

<template>
    <g v-if="indicators.length && dot" :class="clickable ? 'pointer-events-auto cursor-pointer' : 'pointer-events-none'" @click.stop="(event) => emit('open', event)" @pointerdown.stop>
        <title v-if="title">{{ title }}</title>
        <circle :cx="center.x" :cy="center.y" :r="6 * scale" :fill="dotColor" class="stroke-white dark:stroke-neutral-900" stroke-width="1.5" />
    </g>
    <foreignObject
        v-else-if="indicators.length"
        :x="center.x - (size.width * scale) / 2"
        :y="center.y - (size.height * scale) / 2"
        :width="size.width * scale"
        :height="size.height * scale"
        :class="clickable ? 'pointer-events-auto cursor-pointer' : 'pointer-events-none'"
        @click.stop="(event) => emit('open', event)"
        @pointerdown.stop
    >
        <!-- Patch 21: stacked: size + arrow (and icons), type, Static, EOL -->
        <div
            :title="title"
            :style="{ width: `${size.width}px`, height: `${size.height}px`, transform: `scale(${scale})`, transformOrigin: '0 0' }"
            class="flex flex-col items-center justify-center rounded-[9px] border border-neutral-300 bg-white px-1 hover:border-neutral-500 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-neutral-500"
        >
            <div v-for="(line, row) in lines" :key="row" class="flex h-[14px] items-center justify-center gap-0.5 whitespace-nowrap">
                <template v-for="(indicator, i) in line" :key="i">
                    <span
                        v-if="indicator.type === 'static' || indicator.type === 'eol'"
                        class="rounded-full px-1 text-[10px] leading-[12px] font-bold"
                        :class="
                            indicator.type === 'static'
                                ? indicator.strong
                                    ? 'bg-yellow-700 text-yellow-100'
                                    : 'bg-green-800 text-green-100'
                                : indicator.strong
                                  ? 'bg-fuchsia-600 text-white'
                                  : 'bg-purple-700 text-purple-100'
                        "
                        >{{ indicator.label }}</span
                    >
                    <span
                        v-else-if="indicator.type === 'text'"
                        class="flex items-center leading-none font-bold"
                        :class="indicator.role === 'type' ? 'font-mono text-[11px]' : 'text-[12px]'"
                        :style="{ color: indicator.fill }"
                    >
                        {{ indicator.label }}
                        <ArrowRight v-if="indicator.arrow === 'right'" class="ml-px size-3" aria-label="Opened on this side: it goes toward its K162 exit" />
                        <ArrowLeft v-else-if="indicator.arrow === 'left'" class="ml-px size-3" aria-label="The K162 side: it opened on the far side" />
                    </span>
                    <Weight v-else-if="indicator.type === 'weight'" class="size-3" :style="{ color: indicator.fill }" />
                    <Clock
                        v-else-if="indicator.type === 'clock'"
                        class="size-3"
                        :style="{ color: indicator.fill, opacity: indicator.faint ? 0.45 : 1 }"
                        :aria-label="indicator.faint ? 'Likely end of life by its age' : undefined"
                    />
                    <Orbit v-else-if="indicator.type === 'gate'" class="size-3" :style="{ color: indicator.fill }" />
                    <Heart v-else-if="indicator.type === 'preserve'" class="size-3" :style="{ color: indicator.fill }" />
                </template>
            </div>
        </div>
    </foreignObject>
</template>
