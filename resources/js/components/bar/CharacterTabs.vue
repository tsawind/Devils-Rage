<script setup lang="ts">
import CharacterColorController from '@/actions/App/Http/Controllers/CharacterColorController';
import { CharacterImage } from '@/components/images';
import {
    ContextMenu,
    ContextMenuCheckboxItem,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuLabel,
    ContextMenuSeparator,
    ContextMenuTrigger,
} from '@/components/ui/context-menu';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useCharacterPills } from '@/composables/useCharacterPills';
import { useStaticSolarsystems } from '@/composables/useStaticSolarsystems';
import { useTrackedAlts } from '@/composables/useTrackedAlts';
import useUser from '@/composables/useUser';
import { classMeta } from '@/const/solarsystemClasses';
import { displayAlias } from '@/lib/alias';
import { CHARACTER_COLORS, characterColor, rememberColor, tabsThatFit } from '@/lib/characterColor';
import { OPEN_LABELS, openCharacter, type TOpenHow } from '@/lib/openCharacter';
import { isPoppedOut } from '@/lib/popOut';
import { driverId, switchDriver } from '@/lib/windowDriver';
import { drivenElsewhere, others } from '@/lib/windowRegistry';
import { useMapSolarsystems } from '@/map/api';
import { auth } from '@/routes';
import UserCharacters from '@/routes/user-characters';
import type { AppPageProps } from '@/types';
import type { TCharacter, TStringedSolarsystemClass } from '@/types/models';
import { router, usePage } from '@inertiajs/vue3';
import { AppWindow, ChevronDown, ExternalLink, Eye, PictureInPicture2, Plus } from 'lucide-vue-next';
import { addCharacterTab, characterTabIds, removeCharacterTab } from '@/lib/characterTabs';
import { computed, ref } from 'vue';

/**
 * Patch 41b: only the characters you add get a tab (+ ▾ adds one; a pop-out or its own window
 * adds it too); your main and alt stay in the Main / Alt pills.
 *
 * Patch 41: your characters as tabs. Click one and the whole window looks through its eyes
 * (Follow, Center, prompts, the signature panel all switch); the others can keep being
 * mapped in the background. Each has its colour (everyone sees it: its pin on the map, its
 * own window and its pop-out use it too). As many as fit in `width`, the rest in "+N ▾";
 * the one you look through always has a tab. Right-click: colour, background mapping,
 * pop-out, its own window or browser tab, move to the list.
 */
const { width, mapSlug } = defineProps<{ width: number; mapSlug: string }>();

const user = useUser();
const page = usePage<AppPageProps<{ map_characters?: TCharacter[] | null }>>();
const { map_solarsystems } = useMapSolarsystems();
const { resolveSolarsystem } = useStaticSolarsystems();
const { trackedIds, setTracked } = useTrackedAlts();
const { altClipboard, altPrompts, setAltClipboard, setAltPrompts } = useCharacterPills();

/** One tab takes this many pixels. */
const TAB_WIDTH = 136;

/** The characters you added as tabs, in the order you added them. */
const characters = computed<TCharacter[]>(() => {
    const all = user.value?.characters ?? [];
    return characterTabIds.value.map((id) => all.find((character) => character.id === id)).filter((character): character is TCharacter => Boolean(character));
});

/** Characters you can add (not already a tab), by name; the search box narrows it. */
const search = ref('');
const addable = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return (user.value?.characters ?? [])
        .filter((character) => !characterTabIds.value.includes(character.id))
        .filter((character) => !needle || character.name.toLowerCase().includes(needle))
        .sort((a, b) => a.name.localeCompare(b.name));
});

const split = computed(() => {
    const fit = tabsThatFit(characters.value.length, width, TAB_WIDTH);
    const shown = characters.value.slice(0, fit);
    const driver = characters.value.find((character) => character.id === driverId.value);
    if (driver && !shown.some((character) => character.id === driver.id) && shown.length) shown[shown.length - 1] = driver;
    const ids = new Set(shown.map((character) => character.id));
    return { shown, rest: characters.value.filter((character) => !ids.has(character.id)) };
});

const tracked = computed(() => trackedIds(mapSlug));
const elsewhere = computed(() => {
    void others.value;
    return new Set(characters.value.filter((character) => drivenElsewhere(mapSlug, character.id)).map((character) => character.id));
});

/** Where a character is: "C5 Daisy" (the map's name when it's on the map), or offline. */
function where(character: TCharacter): { klass: string | null; color: string | null; name: string } | null {
    const status = (page.props.map_characters ?? []).find((mapped) => mapped.id === character.id)?.status ?? character.status;
    const id = status?.solarsystem_id;
    if (!id) return null;
    const resolved = resolveSolarsystem(id);
    const alias = displayAlias(map_solarsystems.value.find((system) => system.solarsystem_id === id)?.alias);
    const meta = classMeta((resolved.class ?? null) as TStringedSolarsystemClass | null);
    return { klass: resolved.class ? meta.short_label : null, color: `var(--color-${meta.color_token})`, name: alias || resolved.name };
}

function lookThrough(character: TCharacter): void {
    if (character.id === driverId.value) return;
    switchDriver(character.id);
    router.put(UserCharacters.update(character.id).url, {}, { preserveScroll: true });
}

function addTab(character: TCharacter): void {
    addCharacterTab(character.id);
    search.value = '';
}

function setColor(character: TCharacter, color: string | null): void {
    rememberColor(character.id, color);
    router.put(CharacterColorController.update(character.id).url, { color }, { preserveScroll: true, preserveState: true, only: ['auth', 'map_characters'] });
}

// Patch 44: the same three ways to open a character as the Alt and Main pills.
function openAs(character: TCharacter, how: TOpenHow): void {
    void openCharacter(character, how);
}
</script>

<template>
    <div class="flex shrink-0 items-center gap-0.5 overflow-hidden" :style="{ maxWidth: `${width}px` }">
        <ContextMenu v-for="character in split.shown" :key="character.id">
            <ContextMenuTrigger as-child>
                <button
                    type="button"
                    class="relative flex h-[30px] shrink-0 items-center gap-1.5 rounded-md border pr-1.5 pl-2 text-left"
                    :class="character.id === driverId ? 'text-foreground' : 'border-transparent text-muted-foreground hover:bg-muted/60'"
                    :style="{
                        width: `${TAB_WIDTH - 2}px`,
                        ...(character.id === driverId
                            ? { background: `color-mix(in srgb, ${characterColor(character)} 14%, var(--color-muted))`, borderColor: `color-mix(in srgb, ${characterColor(character)} 45%, transparent)` }
                            : {}),
                    }"
                    :title="`${character.name}${elsewhere.has(character.id) ? ' · in its own window' : ''}${isPoppedOut(character.id) ? ' · in the pop-out' : ''}${tracked.has(character.id) && character.id !== driverId ? ' · its jumps are mapped in the background' : ''}\nClick: see through ${character.name} · right-click: more`"
                    @click="lookThrough(character)"
                >
                    <span class="absolute top-[5px] bottom-[5px] left-0 w-[3px] rounded" :style="{ background: characterColor(character) }" />
                    <span class="relative shrink-0">
                        <CharacterImage :character_id="character.id" :character_name="character.name" class="size-5 rounded" />
                        <span
                            v-if="tracked.has(character.id) && character.id !== driverId"
                            class="absolute -right-1 -bottom-1 size-2.5 rounded-full border-2 bg-background"
                            :style="{ borderColor: characterColor(character) }"
                        />
                    </span>
                    <span class="flex min-w-0 flex-col leading-[1.05]">
                        <span class="truncate text-[11px] font-semibold">
                            {{ character.name }}
                            <span v-if="elsewhere.has(character.id)" :style="{ color: characterColor(character) }">↗</span>
                            <span v-if="isPoppedOut(character.id)" :style="{ color: characterColor(character) }">◉</span>
                        </span>
                        <span class="truncate text-[9.5px] text-muted-foreground">
                            <template v-if="where(character)">
                                <span v-if="where(character)!.klass" class="font-semibold" :style="{ color: where(character)!.color ?? undefined }">{{ where(character)!.klass }}</span>
                                {{ where(character)!.name }}
                            </template>
                            <template v-else>offline</template>
                        </span>
                    </span>
                    <span v-if="character.id === driverId" class="absolute right-1.5 -bottom-[6px] left-1.5 h-0.5 rounded" :style="{ background: characterColor(character) }" />
                </button>
            </ContextMenuTrigger>
            <ContextMenuContent class="w-64">
                <ContextMenuLabel class="text-xs">{{ character.name }}</ContextMenuLabel>
                <ContextMenuItem class="text-xs" :disabled="character.id === driverId" @select="lookThrough(character)"><Eye class="size-3.5" /> See through {{ character.name }}</ContextMenuItem>
                <ContextMenuLabel class="pt-2 text-[10px] font-normal tracking-wide text-muted-foreground uppercase">Colour (everyone sees it)</ContextMenuLabel>
                <div class="flex flex-wrap items-center gap-1.5 px-2 pb-2">
                    <button
                        v-for="color in CHARACTER_COLORS"
                        :key="color"
                        type="button"
                        class="size-4 rounded-full border-2"
                        :class="characterColor(character) === color ? 'border-white ring-1 ring-black' : 'border-transparent'"
                        :style="{ background: color }"
                        :title="color"
                        @click="setColor(character, color)"
                    />
                    <label class="relative size-4 cursor-pointer overflow-hidden rounded-full" style="background: conic-gradient(red, yellow, lime, cyan, blue, magenta, red)" title="Any colour…">
                        <input type="color" class="absolute inset-0 cursor-pointer opacity-0" :value="characterColor(character)" @change="(event) => setColor(character, (event.target as HTMLInputElement).value)" />
                    </label>
                </div>
                <ContextMenuSeparator />
                <ContextMenuCheckboxItem
                    class="text-xs"
                    :disabled="character.id === driverId"
                    :model-value="tracked.has(character.id)"
                    @update:model-value="(value: boolean | 'indeterminate') => setTracked(mapSlug, character.id, value === true)"
                    @select.prevent
                >
                    Map its jumps in the background
                </ContextMenuCheckboxItem>
                <ContextMenuSeparator />
                <ContextMenuItem class="text-xs" :title="OPEN_LABELS.popout.hint" @select="openAs(character, 'popout')"><PictureInPicture2 class="size-3.5" /> Pop out (on top of EVE)</ContextMenuItem>
                <ContextMenuItem class="text-xs" :title="OPEN_LABELS.window.hint" @select="openAs(character, 'window')"><AppWindow class="size-3.5" /> Own window (no address bar)</ContextMenuItem>
                <ContextMenuItem class="text-xs" :title="OPEN_LABELS.tab.hint" @select="openAs(character, 'tab')"><ExternalLink class="size-3.5" /> Browser tab</ContextMenuItem>
                <ContextMenuSeparator />
                <ContextMenuCheckboxItem class="text-xs" :model-value="altClipboard" @update:model-value="(value: boolean | 'indeterminate') => setAltClipboard(value === true)" @select.prevent>
                    Clipboard on background jumps
                </ContextMenuCheckboxItem>
                <ContextMenuCheckboxItem class="text-xs" :model-value="altPrompts" @update:model-value="(value: boolean | 'indeterminate') => setAltPrompts(value === true)" @select.prevent>
                    "Which signature?" on background jumps
                </ContextMenuCheckboxItem>
                <ContextMenuSeparator />
                <ContextMenuItem class="text-xs text-muted-foreground" @select="removeCharacterTab(character.id)">Remove the tab</ContextMenuItem>
            </ContextMenuContent>
        </ContextMenu>

        <DropdownMenu v-if="user" @update:open="(open: boolean) => open || (search = '')">
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    class="flex h-7 shrink-0 items-center gap-0.5 rounded-md border border-dashed border-border px-1.5 text-[11px] text-muted-foreground hover:text-foreground"
                    :title="split.rest.length ? 'More tabs, or add a character tab' : 'Add a character tab'"
                >
                    <template v-if="split.rest.length">+{{ split.rest.length }}</template><Plus v-else class="size-3" /><ChevronDown class="size-3" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="w-60">
                <template v-if="split.rest.length">
                    <DropdownMenuLabel class="text-xs">More tabs</DropdownMenuLabel>
                    <DropdownMenuItem v-for="character in split.rest" :key="character.id" class="gap-2 text-xs" @select="lookThrough(character)">
                        <span class="size-2 shrink-0 rounded-full" :style="{ background: characterColor(character) }" />
                        <span class="flex-1 truncate">{{ character.name }}</span>
                        <span class="text-[10px] text-muted-foreground">{{ where(character) ? where(character)!.name : 'offline' }}</span>
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                </template>
                <DropdownMenuLabel class="text-xs">Add a tab</DropdownMenuLabel>
                <div class="px-2 pb-1">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Find a character…"
                        class="h-7 w-full rounded border border-border bg-background px-2 text-xs"
                        @keydown.stop
                    />
                </div>
                <div class="max-h-64 overflow-y-auto">
                    <DropdownMenuItem v-for="character in addable" :key="character.id" class="gap-2 text-xs" @select="addTab(character)">
                        <CharacterImage :character_id="character.id" :character_name="character.name" class="size-5 rounded" />
                        <span class="flex-1 truncate">{{ character.name }}</span>
                        <span class="text-[10px] text-muted-foreground">{{ where(character) ? where(character)!.name : 'offline' }}</span>
                    </DropdownMenuItem>
                    <p v-if="!addable.length" class="px-2 py-1 text-xs text-muted-foreground">No other characters.</p>
                </div>
                <DropdownMenuSeparator />
                <DropdownMenuItem as-child class="text-xs">
                    <a :href="auth({ query: { add_to_account: true } }).url" class="flex w-full items-center gap-2"><Plus class="size-3.5" /> Add a character to your account</a>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
