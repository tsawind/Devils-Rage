<script setup lang="ts">
import AutopilotSettings from '@/components/autopilot/AutopilotSettings.vue';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useWaypoint } from '@/composables/useWaypoint';
import RouteMailDialog from '@/components/map/RouteMailDialog.vue';
import { ref } from 'vue';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { ROUTE_KIND_LABELS, type TRouteKind, useRouteCopy } from '@/composables/useRouteCopy';
import { ChevronDown, ClipboardCopy, Mail, Navigation, Radar, ShieldCheck, TriangleAlert, Users, Zap } from 'lucide-vue-next';
import type { Component } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Patch 26: copy a route for fleet chat. `bar`: in the map bar (Default, Safest, ▾ for the
 * rest); `panel`: the full set at the bottom of the Routing dropdown, with the experimental
 * predictions.
 */
const { variant } = defineProps<{ variant: 'bar' | 'panel' }>();

const { roundTrip, copyForLinking, notepadLinks, copyRoute, copyHoldFacts, copyScanPlan, canCopy, waypointsFor } = useRouteCopy();

// Patch 37: set the route in game on your own characters, and mail a route report.
const { onlineCharacters, setRouteWaypoints } = useWaypoint();
const mailOpen = ref(false);

async function setRouteInGame(characterIds: number[] | null): Promise<void> {
    const ids = await waypointsFor('default');
    if (ids.length) setRouteWaypoints(ids, characterIds);
    else toast.warning('Nothing to set in game', { description: 'The route has no gate legs (autopilot only uses gates).' });
}

/** Patch 37: the bar's copy buttons, each backup right after its route. */
const barButtons: { kind: TRouteKind; icon: Component; tip: string; backup?: boolean }[] = [
    { kind: 'default', icon: ClipboardCopy, tip: 'Copy the route for chat (your route settings)' },
    { kind: 'shortest', icon: Zap, tip: 'Copy the Shortest route (frigate holes skipped)' },
    { kind: 'shortestBackup', icon: Zap, tip: "Copy the Shortest backup: the next route once one of the Shortest route's holes is gone", backup: true },
    { kind: 'safest', icon: ShieldCheck, tip: 'Copy the Safest route (frigate holes skipped)' },
    { kind: 'safestBackup', icon: ShieldCheck, tip: "Copy the Safest backup: the next route once one of the Safest route's holes is gone", backup: true },
];
const kinds: TRouteKind[] = ['default', 'shortest', 'shortestBackup', 'safest', 'safestBackup'];
const hint = 'Pick To in Routing first (From is home while it is empty)';
</script>

<template>
    <div v-if="variant === 'bar'" class="flex items-center gap-1">
        <!-- Patch 37: the backups sit next to their route, drawn as a "second" (dashed, dimmer, a small 2) -->
        <Tooltip v-for="button in barButtons" :key="button.kind">
            <TooltipTrigger as-child>
                <button
                    type="button"
                    class="relative flex items-center rounded border bg-background p-1.5 hover:border-border hover:text-foreground disabled:opacity-40"
                    :class="button.backup ? 'border-dashed border-border/60 text-muted-foreground/70' : 'border-border/50 text-muted-foreground'"
                    :disabled="!canCopy"
                    :aria-label="`Copy the ${ROUTE_KIND_LABELS[button.kind]} route`"
                    @click="copyRoute(button.kind)"
                >
                    <component :is="button.icon" class="size-4" />
                    <span v-if="button.backup" class="absolute -right-0.5 -bottom-0.5 rounded-sm bg-background px-px font-mono text-[9px] leading-none font-bold">2</span>
                </button>
            </TooltipTrigger>
            <TooltipContent side="bottom"><p class="text-xs">{{ canCopy ? button.tip : hint }}</p></TooltipContent>
        </Tooltip>
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    class="flex items-center rounded border border-border/50 bg-background p-1.5 text-muted-foreground hover:border-border hover:text-foreground"
                    aria-label="More routes to copy"
                >
                    <ChevronDown class="size-4" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="w-56">
                <DropdownMenuItem v-for="kind in kinds" :key="kind" class="text-xs" :disabled="!canCopy" @select="copyRoute(kind)">
                    <ClipboardCopy class="size-3.5" /> {{ ROUTE_KIND_LABELS[kind] }}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuCheckboxItem
                    class="text-xs"
                    :model-value="roundTrip"
                    @update:model-value="(value: boolean | 'indeterminate') => (roundTrip = value === true)"
                    @select.prevent
                >
                    In and back out (½ mass)
                </DropdownMenuCheckboxItem>
                <!-- Patch 37: copy for EVE's Auto Link, or with ready-made links for the in-game Notepad -->
                <DropdownMenuCheckboxItem
                    class="text-xs"
                    :model-value="copyForLinking"
                    title="One real system per line, so a double-click selects it in fleet chat: right-click → Auto Link → Solar System"
                    @update:model-value="(value: boolean | 'indeterminate') => (copyForLinking = value === true)"
                    @select.prevent
                >
                    Copy for linking (one system per line)
                </DropdownMenuCheckboxItem>
                <DropdownMenuCheckboxItem
                    class="text-xs"
                    :model-value="notepadLinks"
                    title="Copies with EVE link codes. Paste in the in-game Notepad (the systems become links), select it, right-click → Copy Selected With Formatting, and paste in fleet chat. Fleet chat alone drops the codes."
                    @update:model-value="(value: boolean | 'indeterminate') => (notepadLinks = value === true)"
                    @select.prevent
                >
                    Notepad links <span class="ml-auto rounded-full border px-1 text-[10px] text-muted-foreground">?</span>
                </DropdownMenuCheckboxItem>
                <p v-if="notepadLinks" class="px-2 pb-1 text-[11px] leading-snug text-muted-foreground">
                    Paste in the in-game Notepad, select it, right-click → Copy Selected With Formatting, then paste in fleet chat.
                </p>
                <DropdownMenuSeparator />
                <!-- Patch 37: the Default route's gate legs as in-game waypoints, on your own characters -->
                <DropdownMenuSub>
                    <DropdownMenuSubTrigger class="text-xs" :disabled="!canCopy">
                        <Navigation class="size-3.5" /> Set route in game
                    </DropdownMenuSubTrigger>
                    <DropdownMenuSubContent class="w-56">
                        <template v-if="onlineCharacters.length">
                            <DropdownMenuItem v-for="character in onlineCharacters" :key="character.id" class="text-xs" @select="setRouteInGame([character.id])">
                                {{ character.name }}
                            </DropdownMenuItem>
                            <DropdownMenuItem v-if="onlineCharacters.length > 1" class="text-xs" @select="setRouteInGame(null)">
                                <Users class="size-3.5" /> All my online characters ({{ onlineCharacters.length }})
                            </DropdownMenuItem>
                        </template>
                        <p v-else class="px-2 py-1.5 text-xs text-muted-foreground">None of your characters is online.</p>
                        <p class="px-2 pb-1.5 text-[11px] leading-snug text-muted-foreground">
                            Waypoints at the end of every gate leg (Default route). Autopilot can't use holes: take those yourself.
                        </p>
                    </DropdownMenuSubContent>
                </DropdownMenuSub>
                <DropdownMenuItem class="text-xs" :disabled="!canCopy" @select="mailOpen = true"> <Mail class="size-3.5" /> Mail route report… </DropdownMenuItem>
                <slot name="extra" />
            </DropdownMenuContent>
        </DropdownMenu>
        <!-- Patch 26: route settings (the same as Navigation's gear) -->
        <AutopilotSettings variant="bar" />
        <RouteMailDialog v-model:open="mailOpen" />
    </div>

    <div v-else class="border-t border-border/30 px-3 py-2">
        <p class="mb-1.5 font-sans text-[11px] font-semibold tracking-wider text-muted-foreground/60 uppercase">Copy route</p>
        <div class="flex flex-wrap gap-1.5">
            <button
                v-for="kind in kinds"
                :key="kind"
                type="button"
                class="inline-flex items-center gap-1 rounded-md border border-border/40 bg-muted/30 px-2 py-1 text-xs transition-colors hover:bg-muted/60 disabled:opacity-40"
                :disabled="!canCopy"
                :title="canCopy ? undefined : hint"
                @click="copyRoute(kind)"
            >
                <ClipboardCopy class="size-3" /> {{ ROUTE_KIND_LABELS[kind] }}
            </button>
        </div>
        <label class="mt-1.5 flex cursor-pointer items-center gap-1.5 text-xs text-muted-foreground">
            <input v-model="roundTrip" type="checkbox" class="size-3" />
            In and back out (½ mass)
        </label>
        <label class="mt-1 flex cursor-pointer items-center gap-1.5 text-xs text-muted-foreground" title="One real system per line, so a double-click selects it in fleet chat: right-click → Auto Link → Solar System">
            <input v-model="copyForLinking" type="checkbox" class="size-3" />
            Copy for linking (one system per line)
        </label>
        <label
            class="mt-1 flex cursor-pointer items-center gap-1.5 text-xs text-muted-foreground"
            title="Copies with EVE link codes. Paste in the in-game Notepad, select it, right-click → Copy Selected With Formatting, and paste in fleet chat."
        >
            <input v-model="notepadLinks" type="checkbox" class="size-3" />
            Notepad links
        </label>
        <p class="mt-2 mb-1.5 font-sans text-[11px] font-semibold tracking-wider text-amber-400/80 uppercase">Experimental</p>
        <div class="flex flex-wrap gap-1.5">
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-1 text-xs transition-colors hover:bg-amber-500/20 disabled:opacity-40"
                :disabled="!canCopy"
                title="The facts about each risky hole on the Default route (mass left, what fits, EOL worst case)"
                @click="copyHoldFacts"
            >
                <TriangleAlert class="size-3" /> Will it hold?
            </button>
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-1 text-xs transition-colors hover:bg-amber-500/20 disabled:opacity-40"
                :disabled="!canCopy"
                title="Where to scan if the chokepoint goes (unscanned sigs, statics not found yet)"
                @click="copyScanPlan"
            >
                <Radar class="size-3" /> Scan plan
            </button>
        </div>
    </div>
</template>
