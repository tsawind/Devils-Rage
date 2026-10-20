<script setup lang="ts">
import { startCharacterDrag } from '@/lib/characterTabs';
import SettingsController from '@/actions/App/Http/Controllers/SettingsController';
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
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import PillLocation from '@/components/user/PillLocation.vue';
import { useCharacterPills } from '@/composables/useCharacterPills';
import useUser from '@/composables/useUser';
import { auth, logout } from '@/routes';
import UserCharacters from '@/routes/user-characters';
import type { AppPageProps } from '@/types';
import type { TCharacter } from '@/types/models';
import { Link, router, usePage } from '@inertiajs/vue3';
import { AppWindow, ExternalLink, Link2, LogOut, Minus, PictureInPicture2, Plus, Settings } from 'lucide-vue-next';
import { computed, type Component } from 'vue';
import { copyLinkForBrowser, OPEN_LABELS, openCharacter, type TOpenHow } from '@/lib/openCharacter';
import { switchDriver } from '@/lib/windowDriver';

/**
 * Patch 25: your main as a pill: where you are (click copies the route there) and "Main" on
 * top, your character below. Left-click: switch character (your quick list). Right-click:
 * the quick list, add / remove characters, settings, log out.
 */
const user = useUser();
const page = usePage<AppPageProps<{ map_characters?: TCharacter[] | null; map?: { slug?: string } }>>();
const { quickIds, quickList, setQuick } = useCharacterPills();

const main = computed(() => user.value?.active_character ?? null);
const onMap = computed(() => Boolean(page.props.map?.slug));
const location = computed(() => page.props.map_characters?.find((character) => character.id === main.value?.id)?.status?.solarsystem_id ?? null);
const switchable = computed(() => quickList('main', user.value?.characters ?? []).filter((character) => character.id !== main.value?.id));
const myQuick = computed(() => quickIds.main.value);
const quick = computed(() => new Set(myQuick.value));

/**
 * Patch 39: switching main changes THIS window's main only (other windows and tabs keep
 * theirs); new windows start from it. Tracked-alt ticks are never cleared.
 */
/** Patch 44: pop out / own window / browser tab. */
const OPEN_WAYS: { value: TOpenHow; label: string; icon: Component }[] = [
    { value: 'popout', label: 'Pop out another…', icon: PictureInPicture2 },
    { value: 'window', label: 'Own window (no address bar)', icon: AppWindow },
    { value: 'tab', label: 'Browser tab', icon: ExternalLink },
];
function openWith(character: TCharacter, how: TOpenHow): void {
    void openCharacter(character, how);
}

function switchMain(characterId: number): void {
    switchDriver(characterId);
    router.put(UserCharacters.update(characterId).url, {}, { preserveScroll: true });
}
</script>

<template>
    <ContextMenu v-if="user && main">
        <ContextMenuTrigger as-child>
            <div class="flex flex-col items-end gap-0.5 rounded-lg border border-border/60 bg-card/90 px-2 py-1 shadow-sm backdrop-blur" title="Right-click for options">
                <div class="flex items-center gap-1 text-[10px] leading-none">
                    <PillLocation v-if="onMap && location" :solarsystem-id="location" :who="main.name" />
                    <span class="font-semibold tracking-wider text-sky-400 uppercase">Main</span>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="flex items-center gap-2 rounded px-0.5 text-xs font-medium hover:bg-muted"
                            draggable="true"
                            title="Click: switch or open · drag onto the character tabs to add a tab"
                            @dragstart="startCharacterDrag($event, main.id)"
                        >
                            <CharacterImage :character_id="main.id" :character_name="main.name" class="size-6 rounded" />
                            <span class="max-w-32 truncate">{{ main.name }}</span>
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="max-h-[60vh] w-60 overflow-y-auto">
                        <!-- Patch 44: the same three ways to open a character as the Alt pill -->
                        <template v-if="onMap">
                            <DropdownMenuLabel class="text-xs">Open {{ main.name }}</DropdownMenuLabel>
                            <DropdownMenuItem class="text-xs" :title="OPEN_LABELS.popout.hint" @select="openWith(main, 'popout')"><PictureInPicture2 class="size-3.5" /> Pop out (on top of EVE)</DropdownMenuItem>
                            <DropdownMenuItem class="text-xs" :title="OPEN_LABELS.window.hint" @select="openWith(main, 'window')"><AppWindow class="size-3.5" /> Own window (no address bar)</DropdownMenuItem>
                            <DropdownMenuItem class="text-xs" :title="OPEN_LABELS.tab.hint" @select="openWith(main, 'tab')"><ExternalLink class="size-3.5" /> Browser tab</DropdownMenuItem>
                            <DropdownMenuItem class="text-xs" title="For a second pop-out: paste it into another browser (Edge next to Chrome)" @select="copyLinkForBrowser(main)"><Link2 class="size-3.5" /> Copy link for another browser</DropdownMenuItem>
                            <DropdownMenuSeparator />
                        </template>
                        <DropdownMenuLabel class="text-xs">Switch to</DropdownMenuLabel>
                        <DropdownMenuItem v-for="character in switchable" :key="character.id" as-child>
                            <button type="button" class="flex w-full items-center gap-2 text-xs" draggable="true" @dragstart="startCharacterDrag($event, character.id)" @click="switchMain(character.id)">
                                <CharacterImage :character_id="character.id" :character_name="character.name" class="size-5 rounded" />
                                {{ character.name }}
                            </button>
                        </DropdownMenuItem>
                        <p v-if="myQuick.length === 0" class="px-2 py-1 text-[11px] text-muted-foreground">Right-click this pill → Quick list to shorten this list.</p>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </ContextMenuTrigger>
        <ContextMenuContent class="w-60">
            <ContextMenuLabel class="text-xs">{{ main.name }}</ContextMenuLabel>
            <ContextMenuSeparator />
            <ContextMenuSub>
                <ContextMenuSubTrigger class="text-xs">Quick list ({{ myQuick.length || 'all' }})</ContextMenuSubTrigger>
                <ContextMenuSubContent class="max-h-[60vh] w-56 overflow-y-auto">
                    <ContextMenuLabel class="text-[11px] font-normal text-muted-foreground">Who the Main pill's left-click lists</ContextMenuLabel>
                    <ContextMenuCheckboxItem
                        v-for="character in user.characters"
                        :key="character.id"
                        class="text-xs"
                        :model-value="quick.has(character.id)"
                        @update:model-value="(value: boolean | 'indeterminate') => setQuick('main', character.id, value === true)"
                        @select.prevent
                    >
                        {{ character.name }}
                    </ContextMenuCheckboxItem>
                </ContextMenuSubContent>
            </ContextMenuSub>
            <template v-if="onMap">
                <ContextMenuSeparator />
                <!-- Patch 45e: one click pops your main out -->
                <ContextMenuItem class="text-xs" :title="OPEN_LABELS.popout.hint" @select="openWith(main, 'popout')">
                    <PictureInPicture2 class="size-3.5" /> Pop out {{ main.name }}
                </ContextMenuItem>
                <ContextMenuSub v-for="how in OPEN_WAYS" :key="how.value">
                    <ContextMenuSubTrigger class="text-xs"><component :is="how.icon" class="mr-1 size-3.5" /> {{ how.label }}</ContextMenuSubTrigger>
                    <ContextMenuSubContent class="max-h-[60vh] w-56 overflow-y-auto">
                        <ContextMenuLabel class="text-[11px] font-normal text-muted-foreground">{{ OPEN_LABELS[how.value].hint }}</ContextMenuLabel>
                        <ContextMenuItem v-for="character in user.characters" :key="character.id" class="text-xs" @select="openWith(character, how.value)">
                            {{ character.name }}
                        </ContextMenuItem>
                    </ContextMenuSubContent>
                </ContextMenuSub>
            </template>
            <ContextMenuSeparator />
            <ContextMenuItem as-child class="text-xs">
                <a class="flex w-full items-center gap-2" :href="auth({ query: { add_to_account: true } }).url"><Plus class="size-3.5" /> Add character</a>
            </ContextMenuItem>
            <ContextMenuItem v-if="user.characters.length > 1" as-child class="text-xs">
                <Link class="flex w-full items-center gap-2" :href="UserCharacters.delete(main.id)" method="delete" as="button"><Minus class="size-3.5" /> Remove {{ main.name }}</Link>
            </ContextMenuItem>
            <ContextMenuItem as-child class="text-xs">
                <Link class="flex w-full items-center gap-2" :href="SettingsController.show()" prefetch><Settings class="size-3.5" /> Settings</Link>
            </ContextMenuItem>
            <ContextMenuSeparator />
            <ContextMenuItem as-child class="text-xs">
                <Link class="flex w-full items-center gap-2" method="delete" :href="logout()" as="button" @click="router.flushAll()"><LogOut class="size-3.5" /> Log out</Link>
            </ContextMenuItem>
        </ContextMenuContent>
    </ContextMenu>
</template>
