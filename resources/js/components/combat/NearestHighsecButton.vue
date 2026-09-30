<script setup lang="ts">
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useClosestSystemsCalculator } from '@/composables/useClosestSystemsCalculator';
import { useShowMap } from '@/composables/useShowMap';
import { useStaticSolarsystems } from '@/composables/useStaticSolarsystems';
import { describeRoute, type TRouteHop } from '@/lib/combat';
import { useMapSolarsystems, useMapStore } from '@/map/api';
import type { TMap } from '@/pages/maps';
import { DoorOpen } from 'lucide-vue-next';
import { computed } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Combat mode: copy the way from your location to the nearest highsec, using
 * the same engine as the Find tab (mapped wormholes, gates, EVE Scout), e.g.
 * "Nearest highsec: 1121 → Tama (lowsec) → 3 gates → Hirri — 4 jumps".
 */
const { map, fromSolarsystemId } = defineProps<{
    map: TMap;
    fromSolarsystemId: number | null;
}>();

const page = useShowMap();
const { map_solarsystems } = useMapSolarsystems();
const { resolveSolarsystem } = useStaticSolarsystems();

// The live connections from the map store (the page prop can lag behind realtime updates).
const connections = computed(() => {
    try {
        return [...useMapStore().connections.values()];
    } catch {
        return map.map_connections ?? [];
    }
});

const { results, isLoading } = useClosestSystemsCalculator({
    fromId: () => fromSolarsystemId,
    condition: 'highsec',
    limit: 1,
    ignoredSystems: () => page.props.ignored_systems ?? [],
    mapConnections: () => connections.value,
    mapSolarsystems: () => map_solarsystems.value,
});

const routeText = computed(() => {
    const nearest = results.value[0];
    if (!nearest) return null;

    const aliases = new Map(map_solarsystems.value.map((system) => [system.solarsystem_id, system.alias]));
    const hops: TRouteHop[] = nearest.route.map((step) => {
        const solarsystem = resolveSolarsystem(step.id);
        return { name: solarsystem.name, alias: aliases.get(step.id) ?? null, class: solarsystem.class, via: step.via };
    });

    return describeRoute(hops, 'Nearest highsec');
});

function copyRoute(): void {
    if (!routeText.value) {
        toast.error(isLoading.value ? 'Still working out the route…' : 'No route to highsec found from here.');
        return;
    }

    navigator.clipboard.writeText(routeText.value).catch(() => undefined);
    toast.success('Copied nearest highsec', { description: routeText.value });
}
</script>

<template>
    <Tooltip>
        <TooltipTrigger as-child>
            <button
                type="button"
                class="flex items-center gap-1.5 rounded bg-muted px-1.5 py-1 text-xs text-muted-foreground transition-colors hover:bg-muted/80 hover:text-foreground sm:px-2"
                :disabled="!fromSolarsystemId"
                @click="copyRoute"
            >
                <DoorOpen class="size-3.5" />
                <span class="hidden md:inline">Highsec</span>
            </button>
        </TooltipTrigger>
        <TooltipContent side="bottom">
            <p class="text-xs font-medium">Copy nearest highsec</p>
            <p class="max-w-xs text-xs text-muted-foreground">{{ routeText ?? 'Working out the route from your location…' }}</p>
        </TooltipContent>
    </Tooltip>
</template>
