<script setup lang="ts">
import LockIcon from '@/components/icons/LockIcon.vue';
import SatelliteDish from '@/components/icons/SatelliteDish.vue';
import HasExtraConnections from '@/components/map/HasExtraConnections.vue';
import SolarsystemEffect from '@/components/map/SolarsystemEffect.vue';
import SolarsystemSovereignty from '@/components/map/SolarsystemSovereignty.vue';
import SolarsystemStatusIcon from '@/components/map/SolarsystemStatusIcon.vue';
import SolarsystemClass from '@/components/solarsystem/SolarsystemClass.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { isWormholeClass } from '@/const/solarsystemClasses';
import { displayAlias } from '@/lib/alias';
import { combatColorHex, combatColorLabel } from '@/lib/combat';
import { useKillPulses } from '@/composables/useKillPulses';
import SolarsystemName from '@/map/components/solarsystem/SolarsystemName.vue';
import SolarsystemPilots from '@/map/components/solarsystem/SolarsystemPilots.vue';
import SolarsystemRegion from '@/map/components/solarsystem/SolarsystemRegion.vue';
import SolarsystemStatics from '@/map/components/solarsystem/SolarsystemStatics.vue';
import { TMapSolarsystem } from '@/pages/maps';
import MapSolarsystems from '@/routes/map-solarsystems';
import { TCharacter, TThreatLevel } from '@/types/models';
import { useForm } from '@inertiajs/vue3';
import { Aperture, Flag as FlagIcon, Home as HomeIcon } from 'lucide-vue-next';
import { computed, ref, useTemplateRef } from 'vue';

/**
 * The visual node card. Fully presentational: everything it renders arrives via
 * props, measurement/selection/hover live in MapNode. Only the alias-edit
 * popover (a self-contained form against the card's own record) stays inside.
 */
const {
    system,
    pilots,
    threatLevel = null,
    isDeadEnd = false,
    compact = false,
    wayBack = null,
} = defineProps<{
    system: TMapSolarsystem;
    pilots: TCharacter[];
    isSelected: boolean;
    isHovered: boolean;
    isActive: boolean;
    isHome: boolean;
    isRally: boolean;
    fixedWidth: boolean;
    threatLevel?: TThreatLevel | null;
    /** Fully scanned with no way on: shown faded. */
    isDeadEnd?: boolean;
    /** A combat lane system (patch 13, rage scanning): small, the number big, J-code and class tiny. */
    compact?: boolean;
    /** Patch 20: the way back to the system it was found from: its sig ("XWE"), or null code until pasted. */
    wayBack?: { code: string | null } | null;
}>();

const emit = defineEmits<{ copyWayBack: [] }>();

// ---- Combat chains ------------------------------------------------------------
const chainHex = computed(() => combatColorHex(system.combat_color));
const chainLabel = computed(() => combatColorLabel(system.combat_color));
const isCombatHome = computed(() => Boolean(system.combat_home && chainHex.value));
const isCombatPulsing = computed(() => isCombatHome.value && Boolean(system.combat_active));

// Patch 26: a kill just came in here: the card flashes red for a few seconds.
const { pulses } = useKillPulses();
const killFlash = computed(() => pulses.value.get(system.solarsystem_id) ?? null);
/** Kept when its chain was cleared (patch 12): "was Red". */
const previousChain = computed(() => combatColorLabel(system.combat_previous_color));
const previousHex = computed(() => combatColorHex(system.combat_previous_color));

/**
 * Systems in a combat chain glow in its color, the combat home more strongly.
 * A drop-shadow filter, so the selection / threat rings (box-shadow) still show.
 */
const chainStyle = computed(() => {
    const hex = chainHex.value;
    if (!hex) return undefined;
    // Patch 15: a soft glow only; the home's thicker border carries it (the big glow hid the card).
    const glow = isCombatHome.value ? `drop-shadow(0 0 3px ${hex}99)` : `drop-shadow(0 0 3px ${hex}77)`;
    // The inline filter replaces the dead-end saturate class, so fold it in here.
    return { filter: isDeadEnd ? `${glow} saturate(0.5)` : glow };
});

const form = useForm<{
    alias: string;
    occupier_alias: string;
}>({
    alias: system.alias ?? '',
    occupier_alias: system.occupier_alias ?? '',
});

const resolvedSolarsystem = computed(() => ({
    id: system.solarsystem?.id ?? system.solarsystem_id,
    name: system.solarsystem?.name ?? system.alias ?? '',
    security: system.solarsystem?.security ?? 0,
    class: system.solarsystem?.class ?? 'unknown',
    sovereignty: system.solarsystem?.sovereignty ?? null,
    region: system.solarsystem?.region ?? null,
    statics: system.solarsystem?.statics ?? null,
    effect: system.solarsystem?.effect ?? null,
    is_shattered: system.solarsystem?.is_shattered ?? false,
}));

const open = ref(false);

/**
 * The form snapshots the record at mount, but the alias can change afterwards
 * (tracker dialog, another user's edit) — reseed it whenever the editor opens.
 */
function openEditor() {
    form.defaults({ alias: system.alias ?? '', occupier_alias: system.occupier_alias ?? '' });
    form.reset();
    open.value = true;
}

/** Exposed so MapNode can observe the card's border-box size for the store. */
const root = useTemplateRef<HTMLElement>('root');
defineExpose({ root });

const hasUncategorizedSignatures = computed(() => {
    return (system.uncategorized_signatures_count ?? 0) > 0;
});

const signatureTooltipText = computed(() => {
    const total = system.signatures_count;
    const uncategorized = system.uncategorized_signatures_count ?? 0;
    const signatureLabel = total === 1 ? 'signature' : 'signatures';

    return `${total} ${signatureLabel}${uncategorized ? ` ${uncategorized} uncategorized` : ''}`;
});

const signatureIconClass = computed(() => {
    return hasUncategorizedSignatures.value ? 'size-[14px] text-rose-500' : 'size-[14px] text-amber-500';
});

const extra_connections_count = computed(() => {
    const connections_count = system.wormhole_signatures_count ?? 0;
    const mapped_connections_count = system.map_connections_count ?? 0;
    return Math.max(0, connections_count - mapped_connections_count);
});

function handleSubmit() {
    form.put(MapSolarsystems.update(system.id).url, {
        onSuccess: () => {
            open.value = false;
        },
        preserveScroll: true,
        preserveState: true,
        only: ['map', 'selected_map_solarsystem'],
    });
}
</script>

<template>
    <div
        ref="root"
        :data-solarsystem-id="system.solarsystem_id"
        :data-selected="isSelected"
        :data-hovered="isHovered"
        :data-status="system.status"
        :data-has-pilots="pilots.length > 0 && !compact"
        :data-is-active="isActive"
        :data-threat-level="threatLevel"
        :data-dead-end="isDeadEnd"
        :title="isDeadEnd ? 'Dead end: fully scanned, no other holes' : undefined"
        :style="chainStyle"
        class="map-node-card relative grid h-[40px] rounded border border-stone-400 bg-[#fffdf8] text-left text-xs ring-offset-2 ring-offset-neutral-50 transition-colors duration-200 ease-in-out select-none hover:bg-white focus:bg-white data-[has-pilots=true]:h-[60px] data-[hovered=true]:outline-2 data-[hovered=true]:outline-yellow-500 data-[is-active=true]:ring-2 data-[is-active=true]:ring-amber-500 data-[selected=true]:bg-amber-100 data-[status=active]:border-active data-[status=empty]:border-empty data-[status=friendly]:border-friendly data-[status=hostile]:border-hostile data-[status=unknown]:border-unknown data-[is-active=false]:data-[threat-level=critical]:ring-2 data-[is-active=false]:data-[threat-level=critical]:ring-threat-critical data-[is-active=false]:data-[threat-level=high]:ring-2 data-[is-active=false]:data-[threat-level=high]:ring-threat-high dark:border-neutral-700 dark:bg-neutral-900 dark:ring-offset-neutral-900 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800 dark:data-[is-active=true]:ring-2 dark:data-[is-active=true]:ring-amber-500 dark:data-[selected=true]:bg-amber-900 dark:data-[status=active]:border-active dark:data-[status=empty]:border-empty dark:data-[status=friendly]:border-friendly dark:data-[status=hostile]:border-hostile dark:data-[status=unscanned]:border-unscanned data-[dead-end=true]:opacity-60 data-[dead-end=true]:saturate-50 data-[dead-end=true]:hover:opacity-100"
        :class="compact ? '!h-[26px] !w-[80px]' : { 'w-[180px]': fixedWidth }"
        @dblclick="openEditor()"
        @drag.prevent
    >
        <!-- Patch 26: the rally point: a bright pulsing pink ring and a tag, easy to spot -->
        <template v-if="isRally">
            <div class="rally-pulse pointer-events-none absolute -inset-1.5 rounded-lg border-2 border-pink-500" />
            <div class="pointer-events-none absolute -top-2.5 right-2 rounded bg-pink-600 px-1 font-display text-[11px] leading-4 font-bold tracking-wide text-white uppercase">⚑ Rally</div>
        </template>
        <!-- Patch 26: a kill just came in here: red flash and shock rings -->
        <template v-if="killFlash">
            <div :key="`kill-a-${killFlash}`" class="kill-ring pointer-events-none absolute -inset-1 rounded-lg border-2 border-red-500" />
            <div :key="`kill-b-${killFlash}`" class="kill-ring kill-ring-late pointer-events-none absolute -inset-1 rounded-lg border-2 border-orange-400" />
            <div :key="`kill-c-${killFlash}`" class="kill-flash pointer-events-none absolute inset-0 rounded bg-red-500/40" />
        </template>
        <!-- Combat chain: a border in the chain's color; the combat home's is thicker and pulses while someone works the chain -->
        <div
            v-if="chainHex"
            class="pointer-events-none absolute rounded-md"
            :class="[isCombatHome ? '-inset-1 border-2' : '-inset-[3px] border', { 'combat-pulse': isCombatPulsing }]"
            :style="{ borderColor: chainHex ?? undefined, '--chain': chainHex ?? undefined }"
        />
        <div
            v-if="isCombatHome"
            class="pointer-events-none absolute -top-2.5 left-2 rounded px-1 font-display text-[11px] leading-4 font-bold tracking-wide text-white uppercase"
            :style="{ backgroundColor: chainHex ?? undefined }"
        >
            ⚔ Rage{{ chainLabel ? ` · ${chainLabel}` : '' }}
        </div>
        <div
            v-else-if="previousChain"
            class="pointer-events-none absolute -top-2 right-2 rounded bg-card px-1 text-[11px] leading-3 opacity-70"
            :style="{ color: previousHex ?? undefined }"
            :title="`Kept when the ${previousChain} chain was cleared`"
        >
            was {{ previousChain }}
        </div>
        <!-- Compact (combat lane): the number big, J-code and class tiny on the right; the rest on hover -->
        <div
            v-if="compact"
            class="flex h-full items-center justify-between gap-1 overflow-hidden px-1.5"
            :title="`${displayAlias(system.alias) || resolvedSolarsystem.name} · ${resolvedSolarsystem.name}${pilots.length ? ` · ${pilots.length} pilot${pilots.length === 1 ? '' : 's'}` : ''}`"
        >
            <span class="truncate text-[15px] leading-none font-bold">{{ displayAlias(system.alias) || '·' }}</span>
            <span class="flex shrink-0 flex-col items-end leading-[9px]">
                <span class="text-[7.5px] text-muted-foreground">{{ resolvedSolarsystem.name }}</span>
                <span class="flex items-center gap-0.5 text-[8px] font-semibold">
                    <span v-if="pilots.length" class="rounded-full bg-sky-500/80 px-[3px] text-[7px] text-white">{{ pilots.length }}</span>
                    <SolarsystemClass :solarsystem_class="resolvedSolarsystem.class" class="!text-[8px]" />
                </span>
            </span>
        </div>
        <div v-else class="row-start-1 grid grid-cols-[auto_1fr_auto] items-center justify-center gap-x-1 px-2">
            <SolarsystemClass :solarsystem_class="resolvedSolarsystem.class" class="font-mono font-semibold" />
            <Popover :open="open" @update:open="(value) => open && (open = value)">
                <PopoverAnchor class="col-start-2 row-start-1 min-w-0">
                    <SolarsystemName :map_solarsystem="system" :truncate="fixedWidth" />
                </PopoverAnchor>
                <PopoverContent>
                    <form @submit.prevent="handleSubmit" class="grid gap-2">
                        <Input v-model="form.alias" type="text" placeholder="Alias" class="w-full" />
                        <Input v-model="form.occupier_alias" type="text" placeholder="Occupier Alias" class="w-full" />
                        <Button type="submit"> Save</Button>
                    </form>
                </PopoverContent>
            </Popover>
            <div class="col-start-3 row-start-1 flex items-center gap-1">
                <Tooltip v-if="system.status && system.status !== 'unknown'" :delay-duration="500">
                    <TooltipTrigger>
                        <SolarsystemStatusIcon :status="system.status" />
                    </TooltipTrigger>
                    <TooltipContent>{{ system.status.charAt(0).toUpperCase() + system.status.slice(1) }}</TooltipContent>
                </Tooltip>
                <Tooltip v-if="isHome" :delay-duration="500">
                    <TooltipTrigger>
                        <HomeIcon class="size-[14px] text-amber-400" />
                    </TooltipTrigger>
                    <TooltipContent> Home system </TooltipContent>
                </Tooltip>
                <Tooltip v-if="isRally" :delay-duration="500">
                    <TooltipTrigger>
                        <FlagIcon class="size-[14px] text-red-400" />
                    </TooltipTrigger>
                    <TooltipContent> Rally point </TooltipContent>
                </Tooltip>
                <Tooltip v-if="system.pinned" :delay-duration="500">
                    <TooltipTrigger>
                        <LockIcon class="size-[14px] text-muted-foreground" />
                    </TooltipTrigger>
                    <TooltipContent> Pinned in place </TooltipContent>
                </Tooltip>
                <Tooltip v-if="system.signatures_count" :delay-duration="500">
                    <TooltipTrigger>
                        <SatelliteDish :class="signatureIconClass" />
                    </TooltipTrigger>
                    <TooltipContent>{{ signatureTooltipText }}</TooltipContent>
                </Tooltip>
                <HasExtraConnections v-if="extra_connections_count" :extra_connections_count="extra_connections_count" />
                <Tooltip v-if="resolvedSolarsystem.is_shattered" :delay-duration="500">
                    <TooltipTrigger>
                        <Aperture class="size-3 text-amber-500/90" />
                    </TooltipTrigger>
                    <TooltipContent>Shattered system</TooltipContent>
                </Tooltip>
                <SolarsystemSovereignty :sovereignty="resolvedSolarsystem.sovereignty" :solarsystem-id="resolvedSolarsystem.id">
                    <template #fallback>
                        <SolarsystemEffect :effect="resolvedSolarsystem.effect" v-if="resolvedSolarsystem.effect" />
                    </template>
                </SolarsystemSovereignty>
            </div>
            <!-- Patch 20: the way back's sig bottom left (green pill; faint *??? until pasted); k-space's region follows it -->
            <div v-if="wayBack" class="col-span-3 row-start-2 flex min-w-0 items-center gap-1">
                <button
                    type="button"
                    class="shrink-0 rounded-[3px] px-1 font-mono text-[11px] leading-[13px] font-bold"
                    :class="wayBack.code ? 'bg-green-800 text-green-100 hover:bg-green-700' : 'border border-dashed border-green-700/70 text-green-600/70'"
                    :title="wayBack.code ? `Way back ${wayBack.code}: click to copy its bookmark` : 'Way back not pasted yet'"
                    @click.prevent.stop="wayBack.code && emit('copyWayBack')"
                >
                    *{{ wayBack.code ?? '???' }}
                </button>
                <span
                    v-if="resolvedSolarsystem.region && !isWormholeClass(resolvedSolarsystem.class)"
                    class="min-w-0 truncate text-xs text-muted-foreground"
                    >{{ resolvedSolarsystem.region.name }}</span
                >
                <SolarsystemStatics
                    v-else-if="resolvedSolarsystem.statics"
                    :statics="resolvedSolarsystem.statics"
                    class="ml-auto !col-span-1 font-mono font-semibold"
                />
            </div>
            <template v-else>
                <SolarsystemRegion
                    :region="resolvedSolarsystem.region"
                    v-if="resolvedSolarsystem.region && !isWormholeClass(resolvedSolarsystem.class)"
                />
                <SolarsystemStatics v-else-if="resolvedSolarsystem.statics" :statics="resolvedSolarsystem.statics" class="font-mono font-semibold" />
            </template>
        </div>
        <SolarsystemPilots v-if="pilots.length && !compact" :pilots />
    </div>
</template>

<style scoped>
/* The combat home while someone works its chain: a bright glow that swells in and out. */
.combat-pulse {
    animation: combat-pulse 2.4s ease-in-out infinite;
}
/* Patch 16: a gentle border pulse; the big halo hid the card. */
@keyframes combat-pulse {
    0%,
    100% {
        box-shadow: 0 0 0 0 color-mix(in srgb, var(--chain) 0%, transparent);
    }
    50% {
        box-shadow: 0 0 0 2px color-mix(in srgb, var(--chain) 45%, transparent);
    }
}
/* Patch 26: the rally point: a pink glow that swells in and out, always on. */
.rally-pulse {
    animation: rally-pulse 1.6s ease-in-out infinite;
}
@keyframes rally-pulse {
    0%,
    100% {
        box-shadow: 0 0 0 0 rgb(236 72 153 / 0.15);
        opacity: 0.75;
    }
    50% {
        box-shadow: 0 0 14px 4px rgb(236 72 153 / 0.6);
        opacity: 1;
    }
}
/* Patch 26: a new kill: rings that burst outward and fade, and a short red flash. */
.kill-ring {
    animation: kill-ring 1.4s ease-out 3;
}
.kill-ring-late {
    animation-delay: 0.45s;
}
@keyframes kill-ring {
    0% {
        transform: scale(1);
        opacity: 1;
    }
    100% {
        transform: scale(1.6);
        opacity: 0;
    }
}
.kill-flash {
    animation: kill-flash 0.6s ease-out 4;
}
@keyframes kill-flash {
    0% {
        opacity: 1;
    }
    100% {
        opacity: 0;
    }
}
</style>
