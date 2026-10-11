<script setup lang="ts">
import type { TRouteDiagram, TRouteDiagramLink, TRouteDiagramNode } from '@/lib/routeSummary';
import { computed } from 'vue';

/**
 * Patch 37: the small picture next to each route on the route page: systems top to bottom
 * (map systems as circles with their class, k-space as orange boxes with security), holes
 * coloured by life and mass (thin for frigate holes, ⚠ on chokes), gates as one dashed line.
 */
const { diagram } = defineProps<{ diagram: TRouteDiagram }>();

const STEP = 64;
const TOP = 16;
const height = computed(() => TOP * 2 + Math.max(0, diagram.nodes.length - 1) * STEP + 8);
const y = (index: number) => TOP + index * STEP;

function nodeTag(node: TRouteDiagramNode): string {
    if (node.kspace && node.security !== null) return node.security.toFixed(1);
    const cls = String(node.cls ?? '');
    return /^\d+$/.test(cls) ? `C${cls}` : cls.toUpperCase();
}

function linkColour(link: TRouteDiagramLink): string {
    if (link.kind === 'gates') return '#737373';
    if (link.kind === 'evescout') return '#22d3ee';
    if (link.life === 'imminent' || link.life === 'critical' || link.mass === 'critical') return '#ef4444';
    if (link.life === 'eol' || link.mass === 'reduced') return '#f59e0b';
    return '#22c55e';
}

function linkLabel(link: TRouteDiagramLink): string {
    if (link.kind === 'gates') return `${link.count} ${link.count === 1 ? 'gate' : 'gates'}`;
    const parts = [link.kind === 'evescout' ? `EVE-Scout${link.type ? ` ${link.type}` : ''}` : (link.type ?? '?')];
    if (link.life === 'imminent') parts.push('closing');
    else if (link.life === 'critical') parts.push('EOL!');
    else if (link.life === 'eol') parts.push('EOL');
    if (link.mass === 'critical') parts.push('Crit');
    else if (link.mass === 'reduced') parts.push('Reduced');
    return parts.join(' · ') + (link.choke ? ' ⚠' : '');
}

function linkNote(link: TRouteDiagramLink): string | null {
    if (link.frigate) return 'frigate only';
    if (link.choke) return 'choke';
    if (link.unchecked) return 'not checked lately';
    return null;
}
</script>

<template>
    <svg :viewBox="`0 0 240 ${height}`" width="240" :height="height" class="font-mono text-[11px]" role="img" aria-label="Route picture">
        <template v-for="(link, index) in diagram.links" :key="`l${index}`">
            <line
                x1="20"
                :y1="y(index) + 11"
                x2="20"
                :y2="y(index + 1) - 11"
                :stroke="linkColour(link)"
                :stroke-width="link.kind === 'gates' ? 2 : link.frigate ? 1.5 : 4"
                :stroke-dasharray="link.kind === 'gates' ? '4 4' : link.kind === 'evescout' ? '6 3' : undefined"
            />
            <text x="36" :y="y(index) + STEP / 2 + 1" :fill="linkColour(link) === '#22c55e' ? '#a3a3a3' : linkColour(link)">{{ linkLabel(link) }}</text>
            <text v-if="linkNote(link)" x="36" :y="y(index) + STEP / 2 + 15" fill="#737373">{{ linkNote(link) }}</text>
        </template>
        <template v-for="(node, index) in diagram.nodes" :key="`n${index}`">
            <rect v-if="node.kspace" x="8" :y="y(index) - 10" width="24" height="20" rx="4" fill="#451a03" stroke="#d97706" />
            <circle v-else cx="20" :cy="y(index)" r="10" fill="#262626" stroke="#a3a3a3" />
            <text x="40" :y="y(index) + 4" :fill="node.kspace ? '#fcd34d' : '#e5e5e5'">
                {{ node.name }} <tspan fill="#737373">{{ nodeTag(node) }}</tspan>
            </text>
        </template>
    </svg>
</template>
