<script setup lang="ts">
import { AllianceLogo, CorporationLogo } from '@/components/images';
import type { TIntelGroup, TSystemIntelligence } from '@/types/models';
import { computed } from 'vue';

/**
 * Patch 58: who lives in a system, who hunts there, who passed through, and us apart, with the
 * evidence for each. Shared by the threat analysis panel and the intel window.
 */
const { intel, solarsystemId, showHours = true } = defineProps<{ intel: TSystemIntelligence; solarsystemId: number; showHours?: boolean }>();

const pad = (hour: number) => String(hour).padStart(2, '0');
function ago(iso: string | null): string {
    if (!iso) return '';
    const minutes = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60_000));
    if (minutes < 60) return `${minutes} min ago`;
    if (minutes < 48 * 60) return `${Math.round(minutes / 60)} h ago`;
    return `${Math.round(minutes / 1440)} d ago`;
}
function day(iso: string): string {
    return new Date(iso).toLocaleDateString('en-GB', { month: 'short', day: 'numeric', timeZone: 'UTC' });
}
const zkill = (group: TIntelGroup) => `https://zkillboard.com/${group.type}/${group.id}/system/${solarsystemId}/`;

const TONES: Record<string, string> = {
    red: 'border-red-700 bg-red-950/70 text-red-100',
    amber: 'border-amber-700 bg-amber-950/60 text-amber-100',
    gray: 'border-border bg-muted/40 text-muted-foreground',
};

const hourBars = computed(() => {
    const max = Math.max(1, ...intel.hours);
    const peak = intel.peak;
    const inPeak = (hour: number) => (peak ? (hour - peak.from + 24) % 24 < 3 : false);
    return intel.hours.map((count, hour) => ({ hour, count, height: `${Math.max(2, Math.round((count / max) * 40))}px`, peak: inPeak(hour) }));
});
const whose = computed(() => (intel.basis === 'residents' ? 'Their' : 'Hostile'));
</script>

<template>
    <div class="flex flex-col gap-3 text-xs">
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="rounded-md border px-2 py-0.5 font-semibold" :class="TONES[intel.status.tone]">{{ intel.status.label }}</span>
            <span v-if="intel.capitals" class="rounded-md bg-violet-950 px-2 py-0.5 font-semibold text-violet-200">Capitals seen</span>
            <span v-if="intel.last_hostile_at" class="rounded-md bg-muted/50 px-2 py-0.5 text-muted-foreground">Last hostile activity {{ ago(intel.last_hostile_at) }}</span>
        </div>

        <div class="grid grid-cols-[repeat(auto-fit,minmax(8.5rem,1fr))] gap-2">
            <div class="flex flex-col gap-0.5 rounded-md border border-border bg-muted/30 p-2">
                <span class="text-[11px] text-muted-foreground">{{ whose }} hours (EVE)</span>
                <span class="font-semibold">{{ intel.peak ? `${pad(intel.peak.from)}:00 – ${pad(intel.peak.to)}:00` : '—' }}</span>
                <span v-if="intel.quiet" class="text-[11px] text-muted-foreground">quiet {{ pad(intel.quiet.from) }} – {{ pad(intel.quiet.to) }}</span>
            </div>
            <div class="flex flex-col gap-0.5 rounded-md border border-border bg-muted/30 p-2">
                <span class="text-[11px] text-muted-foreground">{{ whose }} fleets</span>
                <span class="font-semibold">{{ intel.fleet ? (intel.fleet.low === intel.fleet.high ? `about ${intel.fleet.median}` : `${intel.fleet.low} – ${intel.fleet.high}`) : '—' }}</span>
                <span v-if="intel.fleet" class="text-[11px] text-muted-foreground">largest {{ intel.fleet.max }} ({{ day(intel.fleet.max_at) }})</span>
            </div>
            <div class="flex flex-col gap-0.5 rounded-md border border-border bg-muted/30 p-2">
                <span class="text-[11px] text-muted-foreground">Hostiles, last 7 days</span>
                <span class="font-semibold">{{ intel.week.kills }} kill{{ intel.week.kills === 1 ? '' : 's' }}</span>
                <span class="text-[11px] text-muted-foreground">{{ intel.week.losses }} losses · {{ intel.week.days }} days active</span>
            </div>
        </div>

        <div v-if="showHours && intel.hours.some((count) => count > 0)" class="rounded-md border border-border bg-muted/30 p-2">
            <div class="flex h-10 items-end gap-0.5">
                <div
                    v-for="bar in hourBars"
                    :key="bar.hour"
                    class="flex-1 rounded-sm"
                    :class="bar.peak ? 'bg-red-500' : 'bg-neutral-600'"
                    :style="{ height: bar.height }"
                    :title="`${pad(bar.hour)}:00 EVE · ${bar.count}`"
                />
            </div>
            <div class="mt-1 flex justify-between font-mono text-[10px] text-muted-foreground"><span>00</span><span>06</span><span>12</span><span>18</span><span>23</span></div>
        </div>

        <section class="flex flex-col gap-1.5">
            <h4 class="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">Lives here</h4>
            <p v-if="!intel.residents.length" class="text-muted-foreground">No one else: no hostile group with home signs (structures, miners, haulers, capitals) here.</p>
            <div v-for="group in intel.residents" :key="`${group.type}${group.id}`" class="flex flex-col gap-1 rounded-md border border-border bg-muted/30 p-2">
                <div class="flex flex-wrap items-center gap-2">
                    <AllianceLogo v-if="group.type === 'alliance'" :alliance_id="group.id" :alliance_name="group.name" :size="32" class="size-5 shrink-0 rounded" />
                    <CorporationLogo v-else :corporation_id="group.id" :corporation_name="group.name" :size="32" class="size-5 shrink-0 rounded" />
                    <a :href="zkill(group)" target="_blank" rel="noopener" class="font-semibold text-foreground hover:underline">{{ group.name }}</a>
                    <span v-if="group.role === 'former'" class="rounded bg-neutral-700 px-1.5 text-[11px] font-semibold text-neutral-200">lived here</span>
                    <span v-else class="rounded px-1.5 text-[11px] font-semibold" :class="group.confidence === 'sure' ? 'bg-green-900/70 text-green-200' : 'bg-amber-900/60 text-amber-100'">{{ group.confidence }}</span>
                    <span class="ml-auto text-muted-foreground">seen {{ ago(group.last_seen) }}</span>
                </div>
                <span class="text-muted-foreground">{{ group.evidence }}</span>
            </div>
        </section>

        <section v-if="intel.hunters.length" class="flex flex-col gap-1">
            <h4 class="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">Hunts here</h4>
            <div v-for="group in intel.hunters" :key="`${group.type}${group.id}`" class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                <a :href="zkill(group)" target="_blank" rel="noopener" class="font-semibold text-foreground hover:underline">{{ group.name }}</a>
                <span v-if="group.killed_structures" class="rounded bg-red-950 px-1.5 text-[11px] font-semibold text-red-200">killed structures</span>
                <span class="text-muted-foreground"
                    >{{ group.kills }} kills, {{ group.losses }} losses · {{ group.days }} days<template v-if="group.ships.length"> · {{ group.ships.slice(0, 3).join(', ') }}</template> · last
                    {{ day(group.last_seen) }}</span
                >
            </div>
        </section>

        <section v-if="intel.passers.length" class="flex flex-col gap-1">
            <h4 class="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">Passed through</h4>
            <span class="text-muted-foreground">
                <template v-for="(group, index) in intel.passers" :key="`${group.type}${group.id}`"
                    ><a :href="zkill(group)" target="_blank" rel="noopener" class="hover:underline">{{ group.name }}</a> ({{ group.days }} d<template v-if="group.killed_structures">, killed structures</template>)<template v-if="index < intel.passers.length - 1">, </template></template
                >
            </span>
        </section>

        <p v-if="intel.ships.length" class="text-muted-foreground">{{ whose }} ships: {{ intel.ships.map((ship) => `${ship.name} ×${ship.count}`).join(', ') }}</p>

        <div v-if="intel.us" class="rounded-md border border-blue-900 bg-blue-950/40 px-2 py-1.5 text-blue-100">
            <b>Us here:</b> {{ intel.us.kills }} kills, {{ intel.us.losses }} losses in 90 days · left out of everything above
        </div>
    </div>
</template>
