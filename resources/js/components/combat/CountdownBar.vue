<script setup lang="ts">
/** A combat-mode popup countdown: "Unknown in 42 s" with a shrinking bar. */
defineProps<{
    remaining: number | null;
    fraction: number;
    /** What happens when it runs out, e.g. "Unknown". */
    action: string;
}>();
</script>

<template>
    <div v-if="remaining !== null" class="grid gap-1 border-b border-red-500/30 bg-red-500/10 px-6 py-2 text-xs text-red-300">
        <div class="flex items-center justify-between">
            <span>⚔ Combat: {{ action }} in</span>
            <span class="font-mono font-medium">{{ remaining }}s</span>
        </div>
        <div class="h-1 overflow-hidden rounded bg-red-500/20">
            <div class="h-full bg-red-400 transition-[width] duration-300 ease-linear" :style="{ width: `${Math.round(fraction * 100)}%` }" />
        </div>
    </div>
</template>
