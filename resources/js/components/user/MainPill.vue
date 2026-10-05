<script setup lang="ts">
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
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import PillLocation from '@/components/user/PillLocation.vue';
import { useCharacterPills } from '@/composables/useCharacterPills';
import useUser from '@/composables/useUser';
import { auth, logout } from '@/routes';
import UserCharacters from '@/routes/user-characters';
import type { AppPageProps } from '@/types';
import type { TCharacter } from '@/types/models';
import { Link, router, usePage } from '@inertiajs/vue3';
import { LogOut, Minus, Plus, Settings } from 'lucide-vue-next';
import { computed } from 'vue';

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
                        <button type="button" class="flex items-center gap-2 rounded px-0.5 text-xs font-medium hover:bg-muted">
                            <CharacterImage :character_id="main.id" :character_name="main.name" class="size-6 rounded" />
                            <span class="max-w-32 truncate">{{ main.name }}</span>
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="max-h-[60vh] w-56 overflow-y-auto">
                        <DropdownMenuLabel class="text-xs">Switch to</DropdownMenuLabel>
                        <DropdownMenuItem v-for="character in switchable" :key="character.id" as-child>
                            <Link class="flex w-full items-center gap-2 text-xs" :href="UserCharacters.update(character.id)" as="button" method="put">
                                <CharacterImage :character_id="character.id" :character_name="character.name" class="size-5 rounded" />
                                {{ character.name }}
                            </Link>
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
