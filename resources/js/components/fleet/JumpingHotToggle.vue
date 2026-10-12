<script setup lang="ts">
import { useJumpingHot } from '@/composables/useJumpingHot';
import usePermission from '@/composables/usePermission';

/**
 * Patch 37: the JUMPING HOT / COLD switch. Red pulse when hot (prop on), a slow blue pulse
 * when cold. `size="sm"` for panel headers.
 */
const { size = 'md' } = defineProps<{ size?: 'icon' | 'sm' | 'md' }>();
const { hot, toggle } = useJumpingHot();
const { canEdit } = usePermission();
</script>

<template>
    <button
        v-if="canEdit"
        type="button"
        class="jumping-switch inline-flex shrink-0 items-center gap-1 rounded-full border font-semibold tracking-wide whitespace-nowrap"
        :class="[
            hot ? 'is-hot border-red-500 bg-red-950/60 text-red-200' : 'is-cold border-blue-500 bg-blue-950/30 text-blue-200',
            size === 'icon' ? 'size-6 justify-center p-0 text-xs' : size === 'sm' ? 'px-2 py-0 text-[10px]' : 'px-3 py-0.5 text-xs',
        ]"
        :aria-pressed="hot"
        :title="hot ? 'Your jumps count HOT (prop on). Click for cold.' : 'Your jumps count COLD (prop off). Click for hot.'"
        @click="toggle"
    >
        <template v-if="size === 'icon'">{{ hot ? '🔥' : '❄' }}</template>
        <template v-else>{{ hot ? '🔥 JUMPING HOT' : '❄ JUMPING COLD' }}</template>
    </button>
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
@media (prefers-reduced-motion: reduce) {
    .jumping-switch {
        animation: none;
    }
}
</style>
