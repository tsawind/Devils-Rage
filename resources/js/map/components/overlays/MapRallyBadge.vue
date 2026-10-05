<script setup lang="ts">
import DestinationContextMenu from '@/components/autopilot/DestinationContextMenu.vue';
import RoutePopover from '@/components/autopilot/RoutePopover.vue';
import SolarsystemClass from '@/components/solarsystem/SolarsystemClass.vue';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useMap } from '@/composables/useMap';
import { useNavigationSystems } from '@/composables/useNavigationSystems';
import { useRallyRoute } from '@/composables/useRallyRoute';
import { useRouteCopy } from '@/composables/useRouteCopy';
import { useStaticSolarsystem, useStaticSolarsystems } from '@/composables/useStaticSolarsystems';
import { displayAlias } from '@/lib/alias';
import { ClipboardCopy, Flag, Navigation, ShieldCheck } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * The rally point. Patch 26: top-middle of the map (the Main / Alt pills have the top-right),
 * with the map's name for it, and one click to route there: set it as Routing's To, copy the
 * route (Default or Safest) for fleet chat.
 */
const map = useMap();
const { rallyRoute } = useRallyRoute();
const { resolveSolarsystem } = useStaticSolarsystems();
const { setToSystem } = useNavigationSystems();
const { copyRoute } = useRouteCopy();

const rallySolarsystemId = computed(() => map.value.rally_solarsystem_id);
const rallySolarsystem = useStaticSolarsystem(rallySolarsystemId);
const rallyAlias = computed(() => displayAlias(map.value.map_solarsystems?.find((system) => system.solarsystem_id === rallySolarsystemId.value)?.alias) || null);

const jumpCount = computed(() => {
    if (rallyRoute.value.length < 2) return null;
    return rallyRoute.value.length - 1;
});

const resolvedRoute = computed(() => {
    return rallyRoute.value.map((step) => resolveSolarsystem(step.id));
});

const action = 'flex size-8 items-center justify-center rounded-lg bg-pink-500/15 text-pink-500 transition-colors hover:bg-pink-500/30';
</script>

<template>
    <div v-if="rallySolarsystem" class="absolute top-3 left-1/2 z-30 -translate-x-1/2">
        <div
            class="rally-badge flex items-center gap-3 rounded-xl border-2 border-pink-500/70 bg-gradient-to-r from-pink-500/20 to-pink-500/5 px-4 py-2 shadow-lg shadow-pink-500/20 backdrop-blur-md dark:from-pink-500/25 dark:to-pink-950/30"
        >
            <DestinationContextMenu :solarsystem_id="rallySolarsystem.id">
                <button class="group flex cursor-pointer items-center gap-3 transition-all hover:opacity-80">
                    <div class="flex flex-col items-start gap-0.5">
                        <span class="text-[11px] font-bold tracking-wider text-pink-500 uppercase">⚑ Rally Point</span>
                        <div class="flex items-center gap-1.5 text-sm font-semibold">
                            <SolarsystemClass :solarsystem_class="rallySolarsystem.class" class="font-bold" />
                            <span v-if="rallyAlias" class="font-display text-base">{{ rallyAlias }}</span>
                            <span :class="rallyAlias ? 'text-muted-foreground' : ''">{{ rallySolarsystem.name }}</span>
                            <span v-if="rallySolarsystem.region" class="text-xs text-muted-foreground">{{ rallySolarsystem.region.name }}</span>
                        </div>
                    </div>
                </button>
            </DestinationContextMenu>
            <RoutePopover v-if="jumpCount !== null" :route="resolvedRoute">
                <button
                    class="flex h-8 cursor-pointer items-center gap-1.5 rounded-lg bg-pink-500/15 px-2.5 font-mono text-sm font-bold text-pink-500 transition-colors hover:bg-pink-500/25"
                >
                    <Flag class="size-3" />
                    {{ jumpCount }}j
                </button>
            </RoutePopover>
            <Tooltip>
                <TooltipTrigger as-child>
                    <button type="button" :class="action" aria-label="Route to the rally point" @click="setToSystem(rallySolarsystem.id)">
                        <Navigation class="size-4" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="bottom"><p class="text-xs">Set as Routing's To</p></TooltipContent>
            </Tooltip>
            <Tooltip>
                <TooltipTrigger as-child>
                    <button type="button" :class="action" aria-label="Copy the route to the rally point" @click="copyRoute('default', rallySolarsystem.id, 'the rally point')">
                        <ClipboardCopy class="size-4" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="bottom"><p class="text-xs">Copy the route here for chat (from Routing's From, or home)</p></TooltipContent>
            </Tooltip>
            <Tooltip>
                <TooltipTrigger as-child>
                    <button type="button" :class="action" aria-label="Copy the safest route to the rally point" @click="copyRoute('safest', rallySolarsystem.id, 'the rally point (safest)')">
                        <ShieldCheck class="size-4" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="bottom"><p class="text-xs">Copy the Safest route here</p></TooltipContent>
            </Tooltip>
        </div>
    </div>
</template>

<style scoped>
/* Patch 26: hard to miss: the badge's border glows in and out. */
.rally-badge {
    animation: rally-badge 2s ease-in-out infinite;
}
@keyframes rally-badge {
    0%,
    100% {
        box-shadow: 0 0 0 0 rgb(236 72 153 / 0.1);
    }
    50% {
        box-shadow: 0 0 18px 2px rgb(236 72 153 / 0.45);
    }
}
</style>
