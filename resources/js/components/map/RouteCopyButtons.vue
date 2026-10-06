<script setup lang="ts">
import AutopilotSettings from '@/components/autopilot/AutopilotSettings.vue';
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { ROUTE_KIND_LABELS, type TRouteKind, useRouteCopy } from '@/composables/useRouteCopy';
import { ChevronDown, ClipboardCopy, Radar, ShieldCheck, TriangleAlert, Zap } from 'lucide-vue-next';

/**
 * Patch 26: copy a route for fleet chat. `bar`: in the map bar (Default, Safest, ▾ for the
 * rest); `panel`: the full set at the bottom of the Routing dropdown, with the experimental
 * predictions.
 */
const { variant } = defineProps<{ variant: 'bar' | 'panel' }>();

const { roundTrip, copyRoute, copyHoldFacts, copyScanPlan, canCopy } = useRouteCopy();
const kinds: TRouteKind[] = ['default', 'shortest', 'shortestBackup', 'safest', 'safestBackup'];
const hint = 'Pick To in Routing first (From is home while it is empty)';
</script>

<template>
    <div v-if="variant === 'bar'" class="flex items-center gap-1">
        <Tooltip>
            <TooltipTrigger as-child>
                <button
                    type="button"
                    class="flex items-center rounded border border-border/50 bg-background p-1.5 text-muted-foreground hover:border-border hover:text-foreground disabled:opacity-40"
                    :disabled="!canCopy"
                    aria-label="Copy the Default route"
                    @click="copyRoute('default')"
                >
                    <ClipboardCopy class="size-4" />
                </button>
            </TooltipTrigger>
            <TooltipContent side="bottom"><p class="text-xs">{{ canCopy ? 'Copy the route for chat (your route settings)' : hint }}</p></TooltipContent>
        </Tooltip>
        <!-- Patch 30: Shortest next to Safest (as on the rally badge) -->
        <Tooltip>
            <TooltipTrigger as-child>
                <button
                    type="button"
                    class="flex items-center rounded border border-border/50 bg-background p-1.5 text-muted-foreground hover:border-border hover:text-foreground disabled:opacity-40"
                    :disabled="!canCopy"
                    aria-label="Copy the Shortest route"
                    @click="copyRoute('shortest')"
                >
                    <Zap class="size-4" />
                </button>
            </TooltipTrigger>
            <TooltipContent side="bottom"><p class="text-xs">{{ canCopy ? 'Copy the Shortest route (frigate holes skipped)' : hint }}</p></TooltipContent>
        </Tooltip>
        <Tooltip>
            <TooltipTrigger as-child>
                <button
                    type="button"
                    class="flex items-center rounded border border-border/50 bg-background p-1.5 text-muted-foreground hover:border-border hover:text-foreground disabled:opacity-40"
                    :disabled="!canCopy"
                    aria-label="Copy the Safest route"
                    @click="copyRoute('safest')"
                >
                    <ShieldCheck class="size-4" />
                </button>
            </TooltipTrigger>
            <TooltipContent side="bottom"><p class="text-xs">{{ canCopy ? 'Copy the Safest route (frigate holes skipped)' : hint }}</p></TooltipContent>
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
            </DropdownMenuContent>
        </DropdownMenu>
        <!-- Patch 26: route settings (the same as Navigation's gear) -->
        <AutopilotSettings variant="bar" />
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
