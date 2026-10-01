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
}>();

// ---- Combat chains ------------------------------------------------------------
const chainHex = computed(() => combatColorHex(system.combat_color));
const chainLabel = computed(() => combatColorLabel(system.combat_color));
const isCombatHome = computed(() => Boolean(system.combat_home && chainHex.value));
const isCombatPulsing = computed(() => isCombatHome.value && Boolean(system.combat_active));
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
    const glow = isCombatHome.value ? `drop-shadow(0 0 6px ${hex}) drop-shadow(0 0 2px ${hex})` : `drop-shadow(0 0 4px ${hex}aa)`;
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
        class="relative grid h-[40px] rounded border border-neutral-300 bg-white text-left text-xs ring-offset-2 ring-offset-neutral-50 transition-colors duration-200 ease-in-out select-none hover:bg-white focus:bg-white data-[has-pilots=true]:h-[60px] data-[hovered=true]:outline-2 data-[hovered=true]:outline-yellow-500 data-[is-active=true]:ring-2 data-[is-active=true]:ring-amber-500 data-[selected=true]:bg-amber-100 data-[status=active]:border-active data-[status=empty]:border-empty data-[status=friendly]:border-friendly data-[status=hostile]:border-hostile data-[status=unknown]:border-unknown data-[is-active=false]:data-[threat-level=critical]:ring-2 data-[is-active=false]:data-[threat-level=critical]:ring-threat-critical data-[is-active=false]:data-[threat-level=high]:ring-2 data-[is-active=false]:data-[threat-level=high]:ring-threat-high dark:border-neutral-700 dark:bg-neutral-900 dark:ring-offset-neutral-900 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800 dark:data-[is-active=true]:ring-2 dark:data-[is-active=true]:ring-amber-500 dark:data-[selected=true]:bg-amber-900 dark:data-[status=active]:border-active dark:data-[status=empty]:border-empty dark:data-[status=friendly]:border-friendly dark:data-[status=hostile]:border-hostile dark:data-[status=unscanned]:border-unscanned data-[dead-end=true]:opacity-60 data-[dead-end=true]:saturate-50 data-[dead-end=true]:hover:opacity-100"
        :class="compact ? '!h-[26px] !w-[80px]' : { 'w-[180px]': fixedWidth }"
        @dblclick="openEditor()"
        @drag.prevent
    >
        <!-- Combat chain: a border in the chain's color; the combat home's is thicker and pulses while someone works the chain -->
        <div
            v-if="chainHex"
            class="pointer-events-none absolute rounded-md"
            :class="[isCombatHome ? '-inset-1 border-2' : '-inset-[3px] border', { 'combat-pulse': isCombatPulsing }]"
            :style="{ borderColor: chainHex ?? undefined, '--chain': chainHex ?? undefined }"
        />
        <div
            v-if="isCombatHome"
            class="pointer-events-none absolute -top-2.5 left-2 rounded px-1 text-[9px] leading-4 font-bold tracking-wide text-white uppercase"
            :style="{ backgroundColor: chainHex ?? undefined }"
        >
            ⚔ Rage{{ chainLabel ? ` · ${chainLabel}` : '' }}
        </div>
        <div
            v-else-if="previousChain"
            class="pointer-events-none absolute -top-2 right-2 rounded bg-card px-1 text-[9px] leading-3 opacity-70"
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
            <SolarsystemClass :solarsystem_class="resolvedSolarsystem.class" />
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
            <SolarsystemRegion
                :region="resolvedSolarsystem.region"
                v-if="resolvedSolarsystem.region && !isWormholeClass(resolvedSolarsystem.class)"
            />
            <SolarsystemStatics v-else-if="resolvedSolarsystem.statics" :statics="resolvedSolarsystem.statics" />
        </div>
        <SolarsystemPilots v-if="pilots.length && !compact" :pilots />
    </div>
</template>

<style scoped>
/* The combat home while someone works its chain: a bright glow that swells in and out. */
.combat-pulse {
    animation: combat-pulse 1.4s ease-in-out infinite;
}

@keyframes combat-pulse {
    0%,
    100% {
        box-shadow:
            0 0 0 0 var(--chain),
            0 0 6px 1px var(--chain);
    }
    50% {
        box-shadow:
            0 0 0 4px color-mix(in srgb, var(--chain) 45%, transparent),
            0 0 24px 8px var(--chain);
    }
}
</style>
