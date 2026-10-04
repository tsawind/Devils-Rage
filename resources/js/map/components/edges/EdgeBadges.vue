<script lang="ts">
export type { EdgeIndicator } from '@/map/components/edges/badgeWidth';
</script>

<script setup lang="ts">
import { badgeWidth, type EdgeIndicator } from '@/map/components/edges/badgeWidth';
import type { Vec2 } from '@/map/core/types';
import { ArrowRight, Clock, Heart, Orbit, Weight } from 'lucide-vue-next';
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
};

const { indicators, center, dot = false, clickable = false, title } = defineProps<Props>();

const emit = defineEmits<{ (e: 'open', event: MouseEvent): void }>();

const totalWidth = computed(() => badgeWidth(indicators));

/** The dot's colour: Static green, else EOL purple, else the first part's. */
const dotColor = computed(() => (indicators.find((item) => item.type === 'static') ?? indicators.find((item) => item.type === 'eol') ?? indicators[0])?.fill ?? 'var(--color-neutral-500)');
</script>

<template>
    <g v-if="indicators.length && dot" :class="clickable ? 'pointer-events-auto cursor-pointer' : 'pointer-events-none'" @click.stop="(event) => emit('open', event)" @pointerdown.stop>
        <title v-if="title">{{ title }}</title>
        <circle :cx="center.x" :cy="center.y" r="6" :fill="dotColor" class="stroke-white dark:stroke-neutral-900" stroke-width="1.5" />
    </g>
    <foreignObject
        v-else-if="indicators.length"
        :x="center.x - totalWidth / 2"
        :y="center.y - 10"
        :width="totalWidth"
        height="20"
        :class="clickable ? 'pointer-events-auto cursor-pointer' : 'pointer-events-none'"
        @click.stop="(event) => emit('open', event)"
        @pointerdown.stop
    >
        <div
            :title="title"
            class="flex h-full items-center justify-center gap-0.5 rounded-full border border-neutral-300 bg-white px-1 hover:border-neutral-500 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-neutral-500"
        >
            <template v-for="(indicator, i) in indicators" :key="i">
                <!-- Patch 21: Static (green) and EOL (purple) sit inside the pill -->
                <span
                    v-if="indicator.type === 'static' || indicator.type === 'eol'"
                    class="rounded-full px-1.5 text-[10px] leading-[14px] font-bold whitespace-nowrap"
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
                <span v-else-if="indicator.type === 'text'" class="flex items-center text-[13px] leading-none font-bold whitespace-nowrap" :style="{ color: indicator.fill }">
                    {{ indicator.label }}
                    <ArrowRight
                        v-if="indicator.arrowAngle != null"
                        class="ml-px size-3"
                        :style="{ transform: `rotate(${indicator.arrowAngle}deg)` }"
                        aria-label="Which way the hole goes: from where it opened toward its K162 exit"
                    />
                </span>
                <Weight v-else-if="indicator.type === 'weight'" class="size-3.5" :style="{ color: indicator.fill }" />
                <Clock
                    v-else-if="indicator.type === 'clock'"
                    class="size-3.5"
                    :style="{ color: indicator.fill, opacity: indicator.faint ? 0.45 : 1 }"
                    :aria-label="indicator.faint ? 'Likely end of life by its age' : undefined"
                />
                <Orbit v-else-if="indicator.type === 'gate'" class="size-3.5" :style="{ color: indicator.fill }" />
                <Heart v-else-if="indicator.type === 'preserve'" class="size-3.5" :style="{ color: indicator.fill }" />
            </template>
        </div>
    </foreignObject>
</template>
