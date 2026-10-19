<script setup lang="ts">
import { CharacterImage } from '@/components/images';
import {
    ContextMenu,
    ContextMenuCheckboxItem,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuLabel,
    ContextMenuSeparator,
    ContextMenuSub,
    ContextMenuSubContent,
    ContextMenuSubTrigger,
    ContextMenuTrigger,
} from '@/components/ui/context-menu';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import PillLocation from '@/components/user/PillLocation.vue';
import { useCharacterPills } from '@/composables/useCharacterPills';
import { useTrackedAlts } from '@/composables/useTrackedAlts';
import useUser from '@/composables/useUser';
import type { AppPageProps } from '@/types';
import type { TCharacter } from '@/types/models';
import { usePage } from '@inertiajs/vue3';
import { AppWindow, ChevronDown, ExternalLink, Link2, PictureInPicture2, Repeat, Users } from 'lucide-vue-next';
import { soloTab } from '@/lib/windowDriver';
import { drivenElsewhere, others, windowName } from '@/lib/windowRegistry';
import { canPopOut } from '@/lib/popOut';
import { copyLinkForBrowser, OPEN_LABELS, openCharacter, switchOtherWindow, type TOpenHow } from '@/lib/openCharacter';
import { computed, ref } from 'vue';

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

/**
 * Patch 39: open an alt as the main of its own window or tab (a full mapper). That window
 * maps the alt from then on; here its row says "own window ↗" (click brings it up).
 */
function openAs(characterId: number, how: TOpenHow): void {
    // Patch 44: the shared way (a character opened elsewhere gets a tab on the bar).
    const character = (user.value?.characters ?? []).find((candidate) => candidate.id === characterId);
    if (character) void openCharacter(character, how);
}
function popOutCharacter(characterId: number): void {
    openAs(characterId, 'popout');
}
const popOutLabel = canPopOut() ? 'Pop out (on top of EVE)' : 'Pop out (opens a window: Chrome / Edge only)';

/** Patch 45d: the alt and the ▾ both open the menu (pick another alt, or open this one). */
const pickerOpen = ref(false);
function focusWindow(characterId: number): void {
    // Opening the same window name again brings the existing window up (it isn't reloaded).
    window.open('', windowName(characterId));
}
const elsewhere = computed(() => {
    void others.value;
    return new Set(alts.value.filter((candidate) => drivenElsewhere(mapSlug.value, candidate.id)).map((candidate) => candidate.id));
});

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
    <ContextMenu v-if="user && alts.length && !soloTab">
        <ContextMenuTrigger as-child>
            <div class="flex flex-col items-end gap-0.5 rounded-lg border border-border/60 bg-card/90 px-2 py-1 shadow-sm backdrop-blur" title="Right-click for options">
                <div class="flex items-center gap-1 text-[10px] leading-none">
                    <PillLocation v-if="mapSlug && alt && location" :solarsystem-id="location" :who="alt.name" />
                    <span v-else-if="alt && mapSlug" class="px-1 text-muted-foreground">offline</span>
                    <span class="font-semibold tracking-wider text-amber-400 uppercase">Alt</span>
                    <span v-if="tracked.size > 1" class="rounded-full bg-amber-500/20 px-1 text-amber-400" :title="`${tracked.size} alts tracked on this map`">{{ tracked.size }}</span>
                </div>
                <DropdownMenu v-model:open="pickerOpen">
                    <div class="flex items-center">
                        <button
                            type="button"
                            class="flex items-center gap-2 rounded px-0.5 text-xs font-medium hover:bg-muted"
                            :title="alt ? `${alt.name}: pick another alt, or open this one` : 'Pick an alt'"
                            @click="pickerOpen = true"
                        >
                            <CharacterImage v-if="alt" :character_id="alt.id" :character_name="alt.name" class="size-6 rounded" />
                            <Users v-else class="size-5 text-muted-foreground" />
                            <span class="max-w-32 truncate" :class="alt ? '' : 'text-muted-foreground'">{{ alt?.name ?? 'Pick an alt' }}</span>
                        </button>
                        <DropdownMenuTrigger as-child>
                            <button type="button" class="rounded px-0.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="Pick another alt, or open this one">
                                <ChevronDown class="size-3.5" />
                            </button>
                        </DropdownMenuTrigger>
                    </div>
                    <DropdownMenuContent align="end" class="max-h-[60vh] w-64 overflow-y-auto">
                        <template v-if="alt">
                            <DropdownMenuLabel class="text-xs">Open {{ alt.name }}</DropdownMenuLabel>
                            <DropdownMenuItem class="text-xs" :title="OPEN_LABELS.popout.hint" @select="openAs(alt.id, 'popout')"><PictureInPicture2 class="size-3.5" /> Pop out (on top of EVE)</DropdownMenuItem>
                            <DropdownMenuItem class="text-xs" :title="OPEN_LABELS.window.hint" @select="openAs(alt.id, 'window')"><AppWindow class="size-3.5" /> Own window (no address bar)</DropdownMenuItem>
                            <DropdownMenuItem class="text-xs" :title="OPEN_LABELS.tab.hint" @select="openAs(alt.id, 'tab')"><ExternalLink class="size-3.5" /> Browser tab</DropdownMenuItem>
                            <DropdownMenuItem class="text-xs" title="For a second pop-out: paste it into another browser (Edge next to Chrome)" @select="copyLinkForBrowser(alt)"><Link2 class="size-3.5" /> Copy link for another browser</DropdownMenuItem>
                            <DropdownMenuSub>
                                <DropdownMenuSubTrigger class="text-xs"><Repeat class="mr-1 size-3.5" /> Switch its window to…</DropdownMenuSubTrigger>
                                <DropdownMenuSubContent class="max-h-[60vh] w-56 overflow-y-auto">
                                    <DropdownMenuLabel class="text-[11px] font-normal text-muted-foreground">The window (and pop-out) showing {{ alt.name }}, in any browser</DropdownMenuLabel>
                                    <DropdownMenuItem v-for="other in alts.filter((candidate) => candidate.id !== alt?.id)" :key="other.id" class="text-xs" @select="switchOtherWindow(alt, other)">
                                        {{ other.name }}
                                    </DropdownMenuItem>
                                </DropdownMenuSubContent>
                            </DropdownMenuSub>
                            <DropdownMenuSeparator />
                        </template>
                        <DropdownMenuLabel class="text-xs">{{ mapSlug ? 'Alt to show and track' : 'Alt to show' }}</DropdownMenuLabel>
                        <DropdownMenuItem v-for="candidate in pickable" :key="candidate.id" class="flex items-center gap-2 text-xs" @select="pick(candidate.id)">
                            <CharacterImage :character_id="candidate.id" :character_name="candidate.name" class="size-5 rounded" />
                            <span class="truncate" :class="candidate.id === alt?.id ? 'font-semibold text-amber-400' : ''">{{ candidate.name }}</span>
                            <!-- Patch 45e: pop this one out (the pop-out switches to it) -->
                            <button
                                type="button"
                                class="ml-auto shrink-0 rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                                :title="`Pop out ${candidate.name} (the pop-out switches to it)`"
                                @click.stop="popOutCharacter(candidate.id)"
                            >
                                <PictureInPicture2 class="size-3.5" />
                            </button>
                            <button
                                v-if="elsewhere.has(candidate.id)"
                                type="button"
                                class="shrink-0 text-[10px] text-sky-400 hover:underline"
                                title="Mapped in its own window, as that window's main. Click to bring it up."
                                @click.stop="focusWindow(candidate.id)"
                            >
                                own window ↗
                            </button>
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
            <template v-if="mapSlug">
                <ContextMenuSub>
                    <ContextMenuSubTrigger class="text-xs"><PictureInPicture2 class="mr-1 size-3.5" /> {{ popOutLabel }}</ContextMenuSubTrigger>
                    <ContextMenuSubContent class="max-h-[60vh] w-56 overflow-y-auto">
                        <ContextMenuLabel class="text-[11px] font-normal text-muted-foreground">A small scanner window over EVE: where it is, its way back, its signatures (Ctrl+V)</ContextMenuLabel>
                        <ContextMenuItem v-for="candidate in user.characters" :key="candidate.id" class="text-xs" @select="popOutCharacter(candidate.id)">
                            {{ candidate.name }}
                        </ContextMenuItem>
                    </ContextMenuSubContent>
                </ContextMenuSub>
                <ContextMenuSub>
                    <ContextMenuSubTrigger class="text-xs"><AppWindow class="mr-1 size-3.5" /> Own window (no address bar)</ContextMenuSubTrigger>
                    <ContextMenuSubContent class="max-h-[60vh] w-56 overflow-y-auto">
                        <ContextMenuLabel class="text-[11px] font-normal text-muted-foreground">A separate full mapper window with this alt as its main</ContextMenuLabel>
                        <ContextMenuItem v-for="candidate in quickList('alt', alts)" :key="candidate.id" class="text-xs" @select="openAs(candidate.id, 'window')">
                            {{ candidate.name }}
                        </ContextMenuItem>
                    </ContextMenuSubContent>
                </ContextMenuSub>
                <ContextMenuSub>
                    <ContextMenuSubTrigger class="text-xs"><ExternalLink class="mr-1 size-3.5" /> Browser tab</ContextMenuSubTrigger>
                    <ContextMenuSubContent class="max-h-[60vh] w-56 overflow-y-auto">
                        <ContextMenuLabel class="text-[11px] font-normal text-muted-foreground">A browser tab with this alt as its only main</ContextMenuLabel>
                        <ContextMenuItem v-for="candidate in quickList('alt', alts)" :key="candidate.id" class="text-xs" @select="openAs(candidate.id, 'tab')">
                            {{ candidate.name }}
                        </ContextMenuItem>
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
