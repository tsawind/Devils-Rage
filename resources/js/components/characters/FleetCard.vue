<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import { comesWithUs, setComesWithUs, useFleet, type TFleetMember } from '@/composables/useFleet';
import { mill } from '@/lib/routeSummary';
import { computed } from 'vue';

/**
 * Patch 37: the fleet you're in, in the pilots panel: every member's hull and mass, grouped
 * by hull type, with a "comes with us" tick each (caps start unticked). The total and
 * "Will it hold?" count only the ticked ships.
 */
const { mapSlug } = defineProps<{ mapSlug: string | null }>();

const { state, start, stop, refresh } = useFleet(mapSlug);

function onToggle(event: Event): void {
    if ((event.target as HTMLDetailsElement).open) start();
    else stop();
}

const members = computed(() => (state.value.status === 'ok' ? state.value.members : []));
const coming = computed(() => members.value.filter(comesWithUs));
const mass = computed(() => coming.value.reduce((sum, member) => sum + member.jump_mass, 0));
const where = computed(() => {
    const counts = new Map<string, number>();
    for (const member of members.value) counts.set(member.solarsystem ?? '?', (counts.get(member.solarsystem ?? '?') ?? 0) + 1);
    return [...counts.entries()].sort((a, b) => b[1] - a[1]).map(([name, count]) => `${name} ${count}`).join(' · ');
});
const sorted = computed(() =>
    [...members.value].sort((a, b) => Number(b.capital) - Number(a.capital) || b.mass - a.mass || (a.ship ?? '').localeCompare(b.ship ?? '') || a.name.localeCompare(b.name)),
);
const updated = computed(() => {
    if (state.value.status !== 'ok') return null;
    const seconds = Math.max(0, Math.round((Date.now() - Date.parse(state.value.updated_at)) / 1000));
    return seconds < 60 ? `${seconds} s ago` : `${Math.round(seconds / 60)} min ago`;
});

function toggle(member: TFleetMember, value: boolean | 'indeterminate'): void {
    setComesWithUs(member, value === true);
}
</script>

<template>
    <details class="border-b border-border/50 px-3 py-1.5 text-xs" @toggle="onToggle">
        <summary class="cursor-pointer font-sans text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
            Fleet
            <template v-if="state.status === 'ok'">
                <span class="font-mono text-amber-400">{{ coming.length }}</span
                ><span v-if="coming.length !== members.length" class="font-normal normal-case"> of {{ members.length }}</span>
                <span class="ml-1 font-mono font-normal normal-case">{{ mill(mass) }}</span>
            </template>
        </summary>
        <div class="mt-1 grid gap-1">
            <p v-if="state.status === 'loading' || state.status === 'idle'" class="text-muted-foreground">Reading the fleet…</p>
            <p v-else-if="state.status !== 'ok'" class="text-muted-foreground">
                {{ state.message }}
                <button type="button" class="ml-1 underline" @click="refresh()">Try again</button>
            </p>
            <template v-else>
                <p class="text-muted-foreground">Read with {{ state.boss }} (fleet boss) · {{ updated }} · {{ where }}</p>
                <div class="grid grid-cols-[auto_minmax(0,1fr)_minmax(0,1fr)_auto] items-center gap-x-2 gap-y-0.5">
                    <template v-for="member in sorted" :key="member.character_id">
                        <Checkbox
                            :model-value="comesWithUs(member)"
                            :aria-label="`${member.name} comes with us`"
                            @update:model-value="(value) => toggle(member, value)"
                        />
                        <span class="truncate" :class="{ 'text-muted-foreground': !comesWithUs(member) }">{{ member.name }}</span>
                        <span class="truncate text-muted-foreground"
                            >{{ member.ship ?? '?' }}<template v-if="member.capital && !comesWithUs(member)"> · staying</template></span
                        >
                        <span class="text-right font-mono" :class="comesWithUs(member) ? 'text-foreground' : 'text-muted-foreground line-through'">{{
                            mill(member.jump_mass)
                        }}</span>
                    </template>
                </div>
                <p class="text-muted-foreground">
                    Bare hulls (HICs with entangler on): plates and prop mods add more. Caps start unticked; tick the ones coming with us. Will it hold? uses the
                    ticked ships.
                </p>
            </template>
        </div>
    </details>
</template>
