<script setup lang="ts">
import { ContextMenuItem, ContextMenuLabel, ContextMenuSub, ContextMenuSubContent, ContextMenuSubTrigger } from '@/components/ui/context-menu';
import usePermission from '@/composables/usePermission';
import { displayAlias } from '@/lib/alias';
import { relinkConnection } from '@/map/actions/holeType';
import { useMapStore } from '@/map/store/mapStore';
import type { TMapConnection, TMapSolarsystem, TPendingHole } from '@/pages/maps';
import { Link2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Patch 16: connection right-click → "Link to a different hole…": the jump
 * really went through another signature. Lists the unjumped holes on both
 * ends (your side first); picking one moves the link there, and the two swap
 * numbers.
 */
const { connection } = defineProps<{
    connection: TMapConnection & { source: TMapSolarsystem; target: TMapSolarsystem };
}>();

const { canEdit } = usePermission();
const store = useMapStore();

function name(system: TMapSolarsystem): string {
    return displayAlias(system.alias) || system.solarsystem.name;
}

const sides = computed(() => {
    const ends = [connection.source, connection.target];
    const here = store.currentSystemId.value;
    if (here === connection.target.id) ends.reverse();
    return ends
        .map((end) => {
            const live = store.systems.get(end.id) ?? end;
            const linked = (connection.signatures ?? []).find((signature) => signature.map_solarsystem_id === end.id);
            return {
                system: live,
                current: linked?.signature_id?.slice(0, 3) ?? null,
                holes: (live.pending_holes ?? []).toSorted((a, b) => (a.signature_id ?? '').localeCompare(b.signature_id ?? '')),
            };
        })
        .filter((side) => side.holes.length > 0);
});

function describe(hole: TPendingHole): string {
    const parts = [hole.wormhole, hole.target_class ? hole.target_class.toUpperCase() : null, hole.alias ? `#${displayAlias(hole.alias)}` : null];
    if (hole.armed_by_name) parts.push(`armed: ${hole.armed_by_name}`);
    return parts.filter(Boolean).join(' · ');
}

function pick(system: TMapSolarsystem, hole: TPendingHole, current: string | null): void {
    relinkConnection(connection.id, hole.id, () =>
        toast.success(`Linked through ${hole.signature_id?.slice(0, 3) ?? 'the new hole'} in ${name(system)}`, {
            description: current ? `${current} is an unjumped hole again.` : undefined,
        }),
    );
}
</script>

<template>
    <ContextMenuSub v-if="canEdit && connection.type !== 'stargate' && sides.length">
        <ContextMenuSubTrigger>
            <Link2 class="size-4" />
            Link to a different hole…
        </ContextMenuSubTrigger>
        <ContextMenuSubContent class="max-h-80 w-72 overflow-y-auto">
            <template v-for="side in sides" :key="side.system.id">
                <ContextMenuLabel class="text-[10px] font-normal text-muted-foreground">
                    In {{ name(side.system) }}{{ side.current ? ` (now ${side.current})` : '' }}
                </ContextMenuLabel>
                <ContextMenuItem v-for="hole in side.holes" :key="hole.id" class="text-xs" @select="pick(side.system, hole, side.current)">
                    <span class="font-mono font-semibold">{{ hole.signature_id?.slice(0, 3) ?? '???' }}</span>
                    <span class="ml-auto pl-2 text-[10px] text-muted-foreground">{{ describe(hole) }}</span>
                </ContextMenuItem>
            </template>
        </ContextMenuSubContent>
    </ContextMenuSub>
</template>
