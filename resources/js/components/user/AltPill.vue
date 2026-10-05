<script setup lang="ts">
import { CharacterImage } from '@/components/images';
import {
    ContextMenu,
    ContextMenuCheckboxItem,
    ContextMenuContent,
    ContextMenuLabel,
    ContextMenuSeparator,
    ContextMenuSub,
    ContextMenuSubContent,
    ContextMenuSubTrigger,
    ContextMenuTrigger,
} from '@/components/ui/context-menu';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import PillLocation from '@/components/user/PillLocation.vue';
import { useCharacterPills } from '@/composables/useCharacterPills';
import { useTrackedAlts } from '@/composables/useTrackedAlts';
import useUser from '@/composables/useUser';
import type { AppPageProps } from '@/types';
import type { TCharacter } from '@/types/models';
import { usePage } from '@inertiajs/vue3';
import { Users } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Patch 25: one alt as a pill, left of your main: where it is (click copies the route there)
 * and "Alt" on top, the alt below. Left-click: pick the alt (your quick list); on a map the
 * picked alt is tracked. Right-click: tracking, other tracked alts, clipboard and prompts
 * for alts' jumps, the quick list.
 */
const user = useUser();
const page = usePage<AppPageProps<{ map_characters?: TCharacter[] | null; map?: { slug?: string } }>>();
const { quickIds, pillAltId, altClipboard, altPrompts, quickList, setQuick, setPillAlt, setAltClipboard, setAltPrompts } = useCharacterPills();
const { trackedIds, setTracked } = useTrackedAlts();

const mapSlug = computed(() => page.props.map?.slug ?? null);
const alts = computed(() => (user.value?.characters ?? []).filter((character) => character.id !== user.value?.active_character?.id));
const alt = computed(() => alts.value.find((character) => character.id === pillAltId.value) ?? null);
const pickable = computed(() => quickList('alt', alts.value));
const tracked = computed(() => trackedIds(mapSlug.value));
const myQuick = computed(() => quickIds.alt.value);
const quick = computed(() => new Set(myQuick.value));
const locationOf = (id: number) => page.props.map_characters?.find((character) => character.id === id)?.status?.solarsystem_id ?? null;
const location = computed(() => (alt.value ? locationOf(alt.value.id) : null));

/** Picking the pill's alt tracks it on this map (and stops tracking the one it replaces). */
function pick(id: number | null): void {
    const previous = alt.value?.id ?? null;
    setPillAlt(id);
    if (!mapSlug.value) return;
    if (previous !== null && previous !== id) setTracked(mapSlug.value, previous, false);
    if (id !== null) setTracked(mapSlug.value, id, true);
}
</script>

<template>
    <ContextMenu v-if="user && alts.length">
        <ContextMenuTrigger as-child>
            <div class="flex flex-col items-end gap-0.5 rounded-lg border border-border/60 bg-card/90 px-2 py-1 shadow-sm backdrop-blur" title="Right-click for options">
                <div class="flex items-center gap-1 text-[10px] leading-none">
                    <PillLocation v-if="mapSlug && alt && location" :solarsystem-id="location" :who="alt.name" />
                    <span v-else-if="alt && mapSlug" class="px-1 text-muted-foreground">offline</span>
                    <span class="font-semibold tracking-wider text-amber-400 uppercase">Alt</span>
                    <span v-if="tracked.size > 1" class="rounded-full bg-amber-500/20 px-1 text-amber-400" :title="`${tracked.size} alts tracked on this map`">{{ tracked.size }}</span>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button type="button" class="flex items-center gap-2 rounded px-0.5 text-xs font-medium hover:bg-muted">
                            <CharacterImage v-if="alt" :character_id="alt.id" :character_name="alt.name" class="size-6 rounded" />
                            <Users v-else class="size-5 text-muted-foreground" />
                            <span class="max-w-32 truncate" :class="alt ? '' : 'text-muted-foreground'">{{ alt?.name ?? 'Pick an alt' }}</span>
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="max-h-[60vh] w-64 overflow-y-auto">
                        <DropdownMenuLabel class="text-xs">{{ mapSlug ? 'Alt to show and track' : 'Alt to show' }}</DropdownMenuLabel>
                        <DropdownMenuItem v-for="candidate in pickable" :key="candidate.id" class="flex items-center gap-2 text-xs" @select="pick(candidate.id)">
                            <CharacterImage :character_id="candidate.id" :character_name="candidate.name" class="size-5 rounded" />
                            <span class="truncate" :class="candidate.id === alt?.id ? 'font-semibold text-amber-400' : ''">{{ candidate.name }}</span>
                        </DropdownMenuItem>
                        <template v-if="alt">
                            <DropdownMenuSeparator />
                            <DropdownMenuItem class="text-xs text-muted-foreground" @select="pick(null)">No alt</DropdownMenuItem>
                        </template>
                        <p v-if="myQuick.length === 0" class="px-2 py-1 text-[11px] text-muted-foreground">Right-click this pill → Quick list to shorten this list.</p>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </ContextMenuTrigger>
        <ContextMenuContent class="w-64">
            <ContextMenuLabel class="text-xs">Alts</ContextMenuLabel>
            <ContextMenuSeparator />
            <template v-if="mapSlug">
                <ContextMenuSub>
                    <ContextMenuSubTrigger class="text-xs">Tracked on this map ({{ tracked.size }})</ContextMenuSubTrigger>
                    <ContextMenuSubContent class="max-h-[60vh] w-56 overflow-y-auto">
                        <ContextMenuLabel class="text-[11px] font-normal text-muted-foreground">Their jumps are mapped while Tracking is on</ContextMenuLabel>
                        <ContextMenuCheckboxItem
                            v-for="candidate in quickList('alt', alts)"
                            :key="candidate.id"
                            class="text-xs"
                            :model-value="tracked.has(candidate.id)"
                            @update:model-value="(value: boolean | 'indeterminate') => setTracked(mapSlug!, candidate.id, value === true)"
                            @select.prevent
                        >
                            {{ candidate.name }}
                        </ContextMenuCheckboxItem>
                    </ContextMenuSubContent>
                </ContextMenuSub>
                <ContextMenuSeparator />
            </template>
            <ContextMenuCheckboxItem
                class="text-xs"
                :model-value="altClipboard"
                @update:model-value="(value: boolean | 'indeterminate') => setAltClipboard(value === true)"
                @select.prevent
            >
                Clipboard on alts' jumps
            </ContextMenuCheckboxItem>
            <ContextMenuCheckboxItem
                class="text-xs"
                :model-value="altPrompts"
                @update:model-value="(value: boolean | 'indeterminate') => setAltPrompts(value === true)"
                @select.prevent
            >
                "Which signature?" prompt on alts' jumps
            </ContextMenuCheckboxItem>
            <ContextMenuSeparator />
            <ContextMenuSub>
                <ContextMenuSubTrigger class="text-xs">Quick list ({{ myQuick.length || 'all' }})</ContextMenuSubTrigger>
                <ContextMenuSubContent class="max-h-[60vh] w-56 overflow-y-auto">
                    <ContextMenuLabel class="text-[11px] font-normal text-muted-foreground">Who the Alt pill's left-click lists</ContextMenuLabel>
                    <ContextMenuCheckboxItem
                        v-for="character in user.characters"
                        :key="character.id"
                        class="text-xs"
                        :model-value="quick.has(character.id)"
                        @update:model-value="(value: boolean | 'indeterminate') => setQuick('alt', character.id, value === true)"
                        @select.prevent
                    >
                        {{ character.name }}
                    </ContextMenuCheckboxItem>
                </ContextMenuSubContent>
            </ContextMenuSub>
        </ContextMenuContent>
    </ContextMenu>
</template>
