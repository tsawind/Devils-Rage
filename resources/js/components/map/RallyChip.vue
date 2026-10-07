<script setup lang="ts">
import RallyPingDialog from '@/components/map/RallyPingDialog.vue';
import SolarsystemClass from '@/components/solarsystem/SolarsystemClass.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useMap } from '@/composables/useMap';
import { useNavigationSystems } from '@/composables/useNavigationSystems';
import usePermission from '@/composables/usePermission';
import { useRallyRoute } from '@/composables/useRallyRoute';
import { useRouteCopy } from '@/composables/useRouteCopy';
import { useStaticSolarsystem } from '@/composables/useStaticSolarsystems';
import { displayAlias } from '@/lib/alias';
import { ChevronDown, ClipboardCopy, Flag, Megaphone, Navigation, ShieldCheck, Zap } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Patch 31: the rally point lives in the map bar (the badge on the map covered the chain).
 * A pink chip: the rally system and the jumps to it; its menu routes there, copies a route
 * (your settings, Shortest, Safest) and pings Discord. The pulsing ring stays on the map card.
 */
const map = useMap();
const { rallyRoute } = useRallyRoute();
const { setToSystem } = useNavigationSystems();
const { copyRoute } = useRouteCopy();
const { canEdit } = usePermission();

const rallySolarsystemId = computed(() => map.value.rally_solarsystem_id);
const rallySolarsystem = useStaticSolarsystem(rallySolarsystemId);
const rallyAlias = computed(() => displayAlias(map.value.map_solarsystems?.find((system) => system.solarsystem_id === rallySolarsystemId.value)?.alias) || null);
const jumpCount = computed(() => (rallyRoute.value.length < 2 ? null : rallyRoute.value.length - 1));
const where = computed(() => (rallyAlias.value ? `${rallyAlias.value} (${rallySolarsystem.value?.name ?? ''})` : (rallySolarsystem.value?.name ?? 'the rally point')));
const pingOpen = ref(false);
</script>

<template>
    <template v-if="rallySolarsystem">
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    class="rally-chip flex h-8 max-w-56 items-center gap-1.5 rounded-md border border-pink-500/70 bg-pink-500/15 px-2 text-sm font-semibold text-pink-100 transition-colors hover:bg-pink-500/25"
                    :title="`Rally point: ${where}`"
                >
                    <Flag class="size-3.5 shrink-0 text-pink-400" />
                    <SolarsystemClass :solarsystem_class="rallySolarsystem.class" class="shrink-0 font-bold" />
                    <span class="min-w-0 truncate">{{ rallyAlias ?? rallySolarsystem.name }}</span>
                    <span v-if="jumpCount !== null" class="shrink-0 font-mono text-xs text-pink-300">{{ jumpCount }}j</span>
                    <ChevronDown class="size-3.5 shrink-0 text-pink-300" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="w-60">
                <DropdownMenuLabel class="text-xs">⚑ Rally: {{ where }}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem class="text-xs" @select="setToSystem(rallySolarsystem.id)"><Navigation class="size-3.5" /> Set as Routing's To</DropdownMenuItem>
                <DropdownMenuItem class="text-xs" @select="copyRoute('default', rallySolarsystem.id, 'the rally point')"
                    ><ClipboardCopy class="size-3.5" /> Copy route (your settings)</DropdownMenuItem
                >
                <DropdownMenuItem class="text-xs" @select="copyRoute('shortest', rallySolarsystem.id, 'the rally point (shortest)')"
                    ><Zap class="size-3.5" /> Copy Shortest route</DropdownMenuItem
                >
                <DropdownMenuItem class="text-xs" @select="copyRoute('safest', rallySolarsystem.id, 'the rally point (safest)')"
                    ><ShieldCheck class="size-3.5" /> Copy Safest route</DropdownMenuItem
                >
                <template v-if="canEdit">
                    <DropdownMenuSeparator />
                    <DropdownMenuItem class="text-xs text-pink-400" @select="pingOpen = true"><Megaphone class="size-3.5" /> Ping Discord…</DropdownMenuItem>
                </template>
            </DropdownMenuContent>
        </DropdownMenu>
        <RallyPingDialog v-if="canEdit" v-model:open="pingOpen" :map-slug="map.slug" :rally-solarsystem-id="rallySolarsystem.id" :where="where" />
    </template>
</template>

<style scoped>
/* A soft glow so the rally point still stands out in the bar. */
.rally-chip {
    animation: rally-chip 2.4s ease-in-out infinite;
}
@keyframes rally-chip {
    0%,
    100% {
        box-shadow: 0 0 0 0 rgb(236 72 153 / 0);
    }
    50% {
        box-shadow: 0 0 10px 1px rgb(236 72 153 / 0.4);
    }
}
</style>
