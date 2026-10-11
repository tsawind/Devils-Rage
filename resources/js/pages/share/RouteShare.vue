<script setup lang="ts">
import RouteDiagram from '@/components/share/RouteDiagram.vue';
import type { TRouteReport } from '@/composables/useRouteCopy';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Patch 37: the page behind a mailed route report's link (24 h, no login): the routes as
 * they were when the mail was sent. Real systems in orange, map names in grey; between
 * them how the route goes on (gates, a hole, an EVE-Scout hole).
 */
const { report, expires_at } = defineProps<{ report: TRouteReport | null; expires_at: string | null }>();

const sent = computed(() => (report?.sent_at ? new Date(report.sent_at) : null));
const eve = (date: Date) => `${String(date.getUTCHours()).padStart(2, '0')}:${String(date.getUTCMinutes()).padStart(2, '0')} EVE`;
const ends = computed(() => (expires_at ? new Date(expires_at) : null));
</script>

<template>
    <Head :title="report?.title ?? 'Route'" />
    <div class="min-h-screen bg-neutral-950 px-4 py-6 text-neutral-200">
        <div class="mx-auto max-w-4xl space-y-5">
            <header class="space-y-1">
                <h1 class="text-xl font-semibold text-white">{{ report?.title ?? 'Route' }}</h1>
                <p v-if="report?.note" class="text-neutral-300">{{ report.note }}</p>
                <p class="text-xs text-neutral-500">
                    As it was when sent<template v-if="sent"> ({{ eve(sent) }})</template>: holes change, check before you jump.<template v-if="ends">
                        This page works until {{ eve(ends) }}.</template
                    >
                </p>
            </header>
            <p v-if="!report || !report.routes.length" class="text-neutral-400">No routes in this report.</p>
            <section
                v-for="(route, index) in report?.routes ?? []"
                :key="index"
                class="flex flex-col gap-4 rounded-lg border border-neutral-800 bg-neutral-900 p-4 sm:flex-row"
            >
                <div class="min-w-0 flex-1">
                <h2 class="mb-3 font-mono text-sm font-semibold text-white">{{ route.title }}</h2>
                <div class="flex flex-wrap items-center gap-y-2">
                    <template v-for="(stop, position) in route.stops" :key="position">
                        <span
                            class="rounded-md border px-2 py-1 font-mono text-sm"
                            :class="stop.kspace ? 'border-amber-600/60 bg-amber-950/40 text-amber-300' : 'border-neutral-700 bg-neutral-800 text-neutral-200'"
                            >{{ stop.name }}</span
                        >
                        <span v-if="stop.leave" class="mx-1.5 flex items-center gap-1 text-xs" :class="stop.leave === 'EVE-Scout hole' ? 'text-cyan-300' : 'text-neutral-400'">
                            <span>{{ stop.leave === 'EVE-Scout hole' ? '⇢' : '→' }}</span>
                            <span v-if="stop.leave !== 'hole'">{{ stop.leave }}</span>
                            <span>{{ stop.leave === 'EVE-Scout hole' ? '⇢' : '→' }}</span>
                        </span>
                    </template>
                </div>
                <pre class="mt-3 font-mono text-xs leading-relaxed whitespace-pre-wrap text-neutral-400">{{ route.lines.join('\n') }}</pre>
                </div>
                <!-- Patch 37: a small picture of the route on the right -->
                <div v-if="route.diagram?.nodes.length" class="shrink-0 border-neutral-800 sm:border-l sm:pl-4">
                    <RouteDiagram :diagram="route.diagram" />
                </div>
            </section>
        </div>
    </div>
</template>
