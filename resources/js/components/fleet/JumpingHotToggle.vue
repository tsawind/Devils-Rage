<script setup lang="ts">
import { useJumpingHot } from '@/composables/useJumpingHot';
import usePermission from '@/composables/usePermission';

/**
 * Patch 37: the JUMPING HOT / COLD switch. Red pulse when hot (prop on), a slow blue pulse
 * when cold. `size="sm"` for panel headers.
 */
const { size = 'md' } = defineProps<{ size?: 'icon' | 'sm' | 'md' }>();
const { hot, toggle, thread, toggleThread } = useJumpingHot();
const { canEdit } = usePermission();
</script>

<template>
    <span v-if="canEdit" class="inline-flex shrink-0 items-center">
        <button
            type="button"
            class="jumping-switch inline-flex items-center gap-1 rounded-l-full border font-semibold tracking-wide whitespace-nowrap"
            :class="[
                hot ? 'is-hot border-red-500 bg-red-950/60 text-red-200' : 'is-cold border-blue-500 bg-blue-950/30 text-blue-200',
                size === 'icon' ? 'h-6 px-1.5 text-xs' : size === 'sm' ? 'px-2 py-0 text-[10px]' : 'px-3 py-0.5 text-xs',
            ]"
            :aria-pressed="hot"
            :title="hot ? 'Your jumps count HOT (prop on). Click for cold.' : 'Your jumps count COLD (prop off). Click for hot.'"
            @click="toggle"
        >
            <template v-if="size === 'icon'">{{ hot ? '🔥' : '❄' }}</template>
            <template v-else>{{ hot ? '🔥 JUMPING HOT' : '❄ JUMPING COLD' }}</template>
        </button>
        <!-- Patch 37: 🧵 threading: entanglers on (HICs); small, inside the switch -->
        <button
            type="button"
            class="inline-flex items-center rounded-r-full border border-l-0 font-semibold whitespace-nowrap"
            :class="[
                thread ? 'is-thread border-purple-400 bg-purple-900/70 text-purple-100' : 'border-border/70 bg-black/30 text-muted-foreground',
                size === 'icon' ? 'h-6 px-1 text-[10px]' : size === 'sm' ? 'px-1.5 py-0 text-[10px]' : 'px-2 py-0.5 text-[11px]',
            ]"
            :aria-pressed="thread"
            :title="thread ? 'Threading: entanglers on (HICs jump at their smallest). Click to turn off.' : 'Threading off. Click when HICs jump with entanglers on.'"
            @click="toggleThread"
        >
            🧵<template v-if="size !== 'icon' && thread"> THREAD</template>
        </button>
    </span>
</template>

<style scoped>
@keyframes jumping-hot {
    0%,
    100% {
        box-shadow: 0 0 0 0 rgb(239 68 68 / 0);
    }
    50% {
        box-shadow: 0 0 12px 2px rgb(239 68 68 / 0.7);
    }
}
@keyframes jumping-cold {
    0%,
    100% {
        box-shadow: 0 0 0 0 rgb(59 130 246 / 0);
    }
    50% {
        box-shadow: 0 0 9px 1px rgb(59 130 246 / 0.5);
    }
}
.is-hot {
    animation: jumping-hot 1.6s ease-in-out infinite;
}
.is-cold {
    animation: jumping-cold 4s ease-in-out infinite;
}
@keyframes jumping-thread {
    0%,
    100% {
        box-shadow: 0 0 0 0 rgb(168 85 247 / 0);
    }
    50% {
        box-shadow: 0 0 9px 1px rgb(168 85 247 / 0.6);
    }
}
.is-thread {
    animation: jumping-thread 2.4s ease-in-out infinite;
}
@media (prefers-reduced-motion: reduce) {
    .is-thread,
    .jumping-switch {
        animation: none;
    }
}
</style>
