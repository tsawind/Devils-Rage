<script setup lang="ts">
import { centerOnMe, setCenterOnMe } from '@/composables/useCenterOnMe';
import MapAccessController from '@/actions/App/Http/Controllers/MapAccessController';
import MapPreferencesController from '@/actions/App/Http/Controllers/MapPreferencesController';
import MapSettingsController from '@/actions/App/Http/Controllers/MapSettingsController';
import TrackingIcon from '@/components/icons/TrackingIcon.vue';
import AddMassDialog from '@/map/components/overlays/connection/AddMassDialog.vue';
import StaleConnectionsBadge from '@/components/map/StaleConnectionsBadge.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useCombat } from '@/composables/combat/useCombat';
import { useTracking } from '@/composables/signatures/useTracking';
import { useDesktopAppScans } from '@/composables/useDesktopAppScans';
import { useActiveMapCharacter } from '@/composables/useActiveMapCharacter';
import { UseMapLayoutReturn } from '@/composables/useMapLayout';
import usePermission from '@/composables/usePermission';
import { usePing } from '@/composables/usePing';
import { useStaticSolarsystem } from '@/composables/useStaticSolarsystems';
import { updateMapUserSettings, useMapSolarsystems } from '@/map/api';
import type { TMap } from '@/pages/maps';
import type { TMapUserSetting } from '@/types/models';
import { useConnectionStatus } from '@laravel/echo-vue';
import { ConnectionStatus } from 'laravel-echo';
import {
    CircleHelp,
    ClipboardCopy,
    Crosshair,
    Eye,
    LayoutGrid,
    LocateFixed,
    LogOut,
    Map as MapIcon,
    PictureInPicture2,
    Redo2,
    Search,
    Settings,
    Shield,
    ShieldAlert,
    SlidersHorizontal,
    SunMoon,
    Undo2,
    UserCog,
} from 'lucide-vue-next';
import { inDesktopApp } from '@/lib/desktopApp';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { canRedo, canUndo, handleUndoKeydown, redoLabel, redoLast, undoLabel, undoLast } from '@/composables/signatures/signatureUndo';
import { displayAlias } from '@/lib/alias';
import type { TResolvedMapNavigation, TResolvedSelectedMapSolarsystem } from '@/pages/maps';
import type { TCharacter } from '@/types/models';
import RageRollChip from './RageRollChip.vue';
import RallyChip from './RallyChip.vue';
import { useRageRoll } from '@/composables/useRageRoll';
import RouteCopyButtons from './RouteCopyButtons.vue';
import MapSwitcher from './MapSwitcher.vue';
import MapSharePopover from './MapSharePopover.vue';
import RoutingBox from './RoutingBox.vue';
import BarHandle from '@/components/bar/BarHandle.vue';
import CharacterTabs from '@/components/bar/CharacterTabs.vue';
import MapTabs from '@/components/bar/MapTabs.vue';
import { CharacterImage } from '@/components/images';
import ServerStatus from '@/components/server-status/ServerStatus.vue';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import DocumentationController from '@/actions/App/Http/Controllers/DocumentationController';
import SettingsController from '@/actions/App/Http/Controllers/SettingsController';
import { useAppearance } from '@/composables/useAppearance';
import { useCommandPalette } from '@/composables/useCommandPalette';
import useUser from '@/composables/useUser';
import { characterColor } from '@/lib/characterColor';
import { addCharacterTab } from '@/lib/characterTabs';
import { forgetPopOutHere, wantsPopOutHere } from '@/lib/openCharacter';
import { openPopOut } from '@/lib/popOut';
import { driverId, soloTab, urlAs } from '@/lib/windowDriver';
import { home, logout } from '@/routes';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useElementSize, useEventListener, useLocalStorage } from '@vueuse/core';
import TrackingSignatureDialog from './TrackingSignatureDialog.vue';

const { map, map_user_settings, layout, map_navigation = null, map_characters = null, selected_map_solarsystem = null, ignored_systems = [] } = defineProps<{
    map: TMap;
    map_user_settings: TMapUserSetting;
    layout: UseMapLayoutReturn;
    map_navigation?: TResolvedMapNavigation | null;
    map_characters?: TCharacter[] | null;
    selected_map_solarsystem?: TResolvedSelectedMapSolarsystem | null;
    ignored_systems?: number[];
}>();

/** Patch 35: the bar pulses red while a rage roll is on. */
const { rageRoll } = useRageRoll();

// Patch 41: the slim bar. Room for the map tabs and the character tabs (the two drag handles),
// remembered in this browser; never so much that the right side is pushed off the bar.
const barWidths = useLocalStorage('slim-bar-widths', { maps: 3 * 92, chars: 3 * 136 });
const bar = ref<HTMLElement | null>(null);
const middle = ref<HTMLElement | null>(null);
const right = ref<HTMLElement | null>(null);
const { width: barWidth } = useElementSize(bar);
const { width: middleWidth } = useElementSize(middle);
const { width: rightWidth } = useElementSize(right);
/** Room left for both tab areas (map picker, Share, the handles and gaps take about 110 px). */
const tabRoom = computed(() => Math.max(228, barWidth.value - middleWidth.value - rightWidth.value - 110));
const mapsWidth = computed(() => Math.max(92, Math.min(barWidths.value.maps, tabRoom.value - 136)));
const charsWidth = computed(() => Math.max(136, Math.min(barWidths.value.chars, tabRoom.value - mapsWidth.value)));

const { openPalette } = useCommandPalette();
const { appearance, updateAppearance } = useAppearance();
const page = usePage();
const missingScopes = computed(() => ((page.props as { missing_scopes?: unknown[] }).missing_scopes ?? []).length);

// A character in its own window: its strip, Pop out, Back to a tab.
const user = useUser();
const driverCharacter = computed(() => (user.value?.characters ?? []).find((candidate) => candidate.id === driverId.value) ?? null);
const driverColor = computed(() => characterColor(driverCharacter.value));
async function popOutDriver(): Promise<void> {
    const character = driverCharacter.value;
    if (!character) return;
    const backToOpener = waitingToPopOut.value;
    waitingToPopOut.value = false;
    // Patch 54: in the desktop app this hidden window only hosts the pop-out: it keeps ?popout=1,
    // so if the page ever reloads (a deploy) the pop-out comes straight back instead of going.
    if (!inDesktopApp()) forgetPopOutHere();
    if (!(await openPopOut(character.id, character.name, driverColor.value).catch(() => false))) {
        window.open(urlAs(window.location.href, character.id, true), '_blank', 'popup=yes,width=900,height=440');
        return;
    }
    // Patch 44b: this tab was opened just for the pop-out: go back to the tab it came from.
    if (backToOpener) {
        try {
            window.opener?.focus();
        } catch {
            // The browser kept this tab in front: switch back by hand.
        }
    }
}

// Patch 44: this window was opened to pop its character out (one pop-out per window): the
// first click anywhere here pops it out (a browser only opens one from a click).
const waitingToPopOut = ref(soloTab.value && wantsPopOutHere());
// Patch 51: in the desktop app this window is hidden (it only hosts the pop-out) and the app
// needs no click to open one: pop out straight away.
onMounted(() => {
    if (waitingToPopOut.value && inDesktopApp()) void popOutDriver();
});
useEventListener(
    document,
    'pointerdown',
    () => {
        if (waitingToPopOut.value) void popOutDriver();
    },
    { capture: true },
);
/** Close this window; the character is a tab in the main window again (the tab list is shared by this browser). */
function backToTab(): void {
    if (driverId.value !== null) addCharacterTab(driverId.value);
    window.close();
}

// Initialize tracking
usePing(map);

const character = useActiveMapCharacter();
const { canEdit, canManageAccess } = usePermission();
// Patch 37: members and up can make viewer links.
const canShare = canEdit;

const echoStatus = typeof window !== 'undefined' ? useConnectionStatus() : ref<ConnectionStatus>('connected');
const connectionStatus = computed(() => echoStatus.value);

// Get pilot's current location
const currentSolarsystem = useStaticSolarsystem(() => character.value?.status?.solarsystem_id ?? null);
// Patch 26: the map's name for where you are (Daisy, A12), when it's on the map.
const currentAlias = computed(() => {
    const id = character.value?.status?.solarsystem_id;
    if (!id) return null;
    return displayAlias(map.map_solarsystems?.find((system) => system.solarsystem_id === id)?.alias) || null;
});

// Tracking
const {
    toggle: toggleTracking,
    toggle_follow: toggleFollow,
    follow_enabled,
    is_tracking,
    is_tracking_allowed,
    can_track,
    signatures,
    show_signature_modal,
    handleSelectSignature,
    origin_map_solarsystem,
    target_solarsystem,
    suggested_alias,
    planned_aliases,
    prompt_static_slot_alias,
    static_owner_id,
    jumper_name,
} = useTracking();
// Patch 47: probe scans copied in EVE, pasted by the desktop app.
useDesktopAppScans();

// Patch 19: the Clipboard switch (on by default; off = names show with a Copy button instead).
const clipboard_on = computed(() => map_user_settings.clipboard_enabled !== false);
function toggleClipboard(): void {
    updateMapUserSettings(map.slug, { clipboard_enabled: !clipboard_on.value });
}

// Patch 20: Ctrl+Z / Ctrl+Y undo and redo your own signature edits.
onMounted(() => window.addEventListener('keydown', handleUndoKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', handleUndoKeydown));


const { map_solarsystems } = useMapSolarsystems();

const targetSolarsystemName = computed(() => target_solarsystem.value?.name || currentSolarsystem.value?.name || null);

// Combat mode: the toggle lives in the signatures panel (patch 12); the jump prompt still uses its countdown.
const { popup_seconds } = useCombat();

// Confirm before leaving layout edit mode so users don't lose unsaved changes by
// clicking the toggle again instead of Save.
const showCancelLayoutDialog = ref(false);

function handleLayoutToggle() {
    if (layout.isEditMode.value && layout.hasUnsavedChanges.value) {
        showCancelLayoutDialog.value = true;
        return;
    }
    layout.toggleEditMode();
}

function saveLayoutAndExit() {
    showCancelLayoutDialog.value = false;
    layout.saveLayout();
}

function discardLayoutAndExit() {
    showCancelLayoutDialog.value = false;
    layout.revertChanges();
    layout.toggleEditMode();
}

// Location visibility toggle
function handleToggleVisibility() {
    updateMapUserSettings(map.slug, {
        tracking_allowed: !map_user_settings.tracking_allowed,
    });
}

function handleToggleThreatLevel() {
    updateMapUserSettings(map.slug, {
        show_threat_level: !map_user_settings.show_threat_level,
    });
}

const isConnected = computed(() => connectionStatus.value === 'connected');
const isConnecting = computed(() => connectionStatus.value === 'connecting' || connectionStatus.value === 'reconnecting');

const connectionStatusText = computed(() => {
    switch (connectionStatus.value) {
        case 'connected':
            return 'Connected';
        case 'connecting':
            return 'Connecting...';
        case 'reconnecting':
            return 'Reconnecting...';
        case 'disconnected':
            return 'Disconnected';
        case 'failed':
            return 'Connection Failed';
        default:
            return 'Unknown';
    }
});

const settingsUrl = computed(() => {
    if (canManageAccess.value) {
        return MapSettingsController.show(map.slug).url;
    }

    return MapPreferencesController.show(map.slug).url;
});
</script>

<template>
    <!-- Patch 41: one slim bar: map tabs, Share, character tabs (two drag handles), search, route, Clipboard / Follow / Center, the toggles and ⚙ -->
    <div
        ref="bar"
        class="relative flex h-10 shrink-0 items-center gap-1 border-b border-border/50 bg-muted/30 px-2"
        :class="{ 'rage-bar': rageRoll }"
        :style="soloTab && driverCharacter ? { background: `linear-gradient(90deg, color-mix(in srgb, ${driverColor} 26%, transparent), color-mix(in srgb, ${driverColor} 6%, transparent) 45%, transparent)`, borderBottomColor: `color-mix(in srgb, ${driverColor} 55%, transparent)` } : undefined"
    >
        <!-- A character in its own window or browser tab: whose window this is, in its colour -->
        <template v-if="soloTab">
            <span class="shrink-0 rounded border px-1.5 py-0.5 text-[10px] font-extrabold tracking-[0.1em]" :style="{ color: driverColor, borderColor: `color-mix(in srgb, ${driverColor} 60%, transparent)` }">ALT WINDOW</span>
            <span v-if="driverCharacter" class="flex shrink-0 items-center gap-1.5 px-1">
                <CharacterImage :character_id="driverCharacter.id" :character_name="driverCharacter.name" class="size-5 rounded" />
                <span class="flex flex-col leading-[1.05]">
                    <span class="text-[11px] font-semibold">{{ driverCharacter.name }}</span>
                    <span class="text-[9.5px] text-muted-foreground">{{ currentAlias || currentSolarsystem?.name || 'offline' }}</span>
                </span>
            </span>
            <button
                v-if="driverCharacter"
                type="button"
                class="flex h-7 shrink-0 items-center gap-1 rounded-md border px-2 text-[11px]"
                :class="waitingToPopOut ? 'animate-pulse border-cyan-400 bg-cyan-500/20 font-semibold text-cyan-200' : 'border-border text-muted-foreground hover:text-foreground'"
                title="Open this character in the always-on-top pop-out"
                @click="popOutDriver"
            >
                <PictureInPicture2 class="size-3.5" /> {{ waitingToPopOut ? 'Click anywhere to pop out' : 'Pop out' }}
            </button>
            <button v-if="driverCharacter" type="button" class="flex h-7 shrink-0 items-center gap-1 rounded-md border border-border px-2 text-[11px] text-muted-foreground hover:text-foreground" title="Close this window; the character shows as a tab in your main window again" @click="backToTab">
                ⇥ Back to a tab
            </button>
            <div class="mx-1 h-4 w-px bg-border/60" />
            <MapSwitcher :map="map" />
        </template>
        <template v-else>
            <MapSwitcher :map="map" icon-only />
            <MapTabs :map="map" :width="mapsWidth" />
            <!-- Patch 37: share the map read only (timed viewer links; permanent for managers) -->
            <MapSharePopover v-if="canShare" :map-slug="map.slug" />
            <BarHandle :width="mapsWidth" :min="92" @resize="(width) => (barWidths.maps = width)" />
            <CharacterTabs v-if="$page.props.auth.user" :width="charsWidth" :map-slug="map.slug" />
            <BarHandle :width="charsWidth" :min="136" @resize="(width) => (barWidths.chars = width)" />
        </template>

        <div ref="middle" class="hidden shrink-0 items-center gap-1 sm:flex">
            <!-- Search: just the icon (Ctrl K still works) -->
            <Tooltip>
                <TooltipTrigger as-child>
                    <button type="button" aria-label="Search" data-test="bar-search" class="flex h-7 w-8 items-center justify-center rounded-md border border-border/60 bg-background text-muted-foreground hover:text-foreground" @click="openPalette">
                        <Search class="size-3.5" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="bottom"><p class="text-xs">Search (Ctrl K)</p></TooltipContent>
            </Tooltip>
            <div class="w-36">
                <RoutingBox compact :map :map_navigation :map_characters :selected_map_solarsystem :ignored_systems />
            </div>
            <!-- Patch 26: copy the route for fleet chat (Default, Safest, ▾ the rest) -->
            <RouteCopyButtons variant="bar" />
            <!-- Patch 31: the rally point, moved off the map -->
            <RallyChip />
        </div>
        <!-- Patch 35: the rage roll, for everyone while it's on -->
        <RageRollChip />

        <div class="min-w-0 flex-1" />

        <div ref="right" class="flex shrink-0 items-center gap-1">
            <!-- Info badges -->
            <Tooltip v-if="$page.props.auth.user && !$page.props.active_character_has_access">
                <TooltipTrigger as-child>
                    <component
                        :is="canManageAccess ? Link : 'div'"
                        :href="canManageAccess ? MapAccessController.show(map.slug) : undefined"
                        class="flex items-center gap-1.5 rounded-full border border-sky-500/30 bg-sky-500/10 px-2 py-0.5 text-[11px] font-medium text-sky-300"
                    >
                        <span class="whitespace-nowrap">Alt access</span>
                    </component>
                </TooltipTrigger>
                <TooltipContent side="bottom">
                    <p class="max-w-xs text-xs text-muted-foreground">
                        {{ $page.props.auth.user.active_character?.name ?? 'This character' }}
                        isn't on the access list itself, so it uses the best access of the other characters on your account.{{ canManageAccess ? ' Click to manage access.' : '' }}
                    </p>
                </TooltipContent>
            </Tooltip>
            <StaleConnectionsBadge />

            <!-- Clipboard, Follow, Center: icons only, lit when on -->
            <Tooltip v-if="canEdit">
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="flex h-7 w-7 items-center justify-center rounded-md border transition-colors"
                        :class="clipboard_on ? 'border-violet-500/50 bg-violet-500/15 text-violet-300' : 'border-border/60 text-muted-foreground hover:text-foreground'"
                        @click="toggleClipboard"
                    >
                        <ClipboardCopy class="size-3.5" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="bottom">
                    <p class="text-xs font-medium">Clipboard {{ clipboard_on ? 'on' : 'off' }}</p>
                    <p class="max-w-xs text-xs text-muted-foreground">
                        {{ clipboard_on ? 'The mapper copies bookmark names for you (arming, type picks, renames, the way back)' : 'The mapper never writes your clipboard on its own; names show with a Copy button' }}
                    </p>
                </TooltipContent>
            </Tooltip>
            <Tooltip v-if="canEdit">
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="flex h-7 w-7 items-center justify-center rounded-md border transition-colors"
                        :class="follow_enabled ? 'border-sky-500/50 bg-sky-500/15 text-sky-300' : 'border-border/60 text-muted-foreground hover:text-foreground'"
                        @click="toggleFollow"
                    >
                        <LocateFixed class="size-3.5" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="bottom"><p class="text-xs">Follow {{ follow_enabled ? 'on' : 'off' }} · select the system you jump into</p></TooltipContent>
            </Tooltip>
            <Tooltip>
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="flex h-7 w-7 items-center justify-center rounded-md border transition-colors"
                        :class="centerOnMe ? 'border-sky-500/50 bg-sky-500/15 text-sky-300' : 'border-border/60 text-muted-foreground hover:text-foreground'"
                        @click="setCenterOnMe(!centerOnMe)"
                    >
                        <Crosshair class="size-3.5" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="bottom"><p class="text-xs">Center {{ centerOnMe ? 'on' : 'off' }} · keep the map centred on your system</p></TooltipContent>
            </Tooltip>

            <!-- Patch 21: undo / redo -->
            <Tooltip v-if="canEdit">
                <TooltipTrigger as-child>
                    <button type="button" class="flex h-7 w-6 items-center justify-center rounded text-muted-foreground hover:bg-muted disabled:opacity-40" :disabled="!canUndo" aria-label="Undo" @click="undoLast()">
                        <Undo2 class="size-3.5" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="bottom"><p class="text-xs">Undo{{ undoLabel ? `: ${undoLabel}` : '' }} (Ctrl+Z)</p></TooltipContent>
            </Tooltip>
            <Tooltip v-if="canEdit">
                <TooltipTrigger as-child>
                    <button type="button" class="flex h-7 w-6 items-center justify-center rounded text-muted-foreground hover:bg-muted disabled:opacity-40" :disabled="!canRedo" aria-label="Redo" @click="redoLast()">
                        <Redo2 class="size-3.5" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="bottom"><p class="text-xs">Redo{{ redoLabel ? `: ${redoLabel}` : '' }} (Ctrl+Y)</p></TooltipContent>
            </Tooltip>

            <!-- The toggles (dots: which are on) -->
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button type="button" class="flex h-7 items-center gap-1 rounded-md border border-border/60 px-1.5 text-muted-foreground hover:text-foreground" title="Visible, Tracking, Threats, connection">
                        <SlidersHorizontal class="size-3.5" />
                        <span class="flex gap-0.5">
                            <span v-if="map_user_settings.tracking_allowed" class="size-1.5 rounded-full bg-green-500" />
                            <span v-if="is_tracking" class="size-1.5 rounded-full bg-amber-500" />
                            <span v-if="map_user_settings.show_threat_level" class="size-1.5 rounded-full bg-red-500" />
                            <span v-if="!isConnected" class="size-1.5 animate-pulse rounded-full bg-yellow-400" />
                        </span>
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-60">
                    <DropdownMenuLabel class="text-xs">This map</DropdownMenuLabel>
                    <DropdownMenuCheckboxItem v-if="canEdit" class="text-xs" :model-value="map_user_settings.tracking_allowed" @select.prevent="handleToggleVisibility">
                        <Eye class="size-3.5" /> Visible to others
                    </DropdownMenuCheckboxItem>
                    <DropdownMenuCheckboxItem v-if="canEdit" class="text-xs" :model-value="Boolean(is_tracking)" :disabled="!can_track" @select.prevent="toggleTracking">
                        <TrackingIcon class="size-3.5" /> Tracking{{ !is_tracking_allowed ? ' (needs Visible)' : '' }}
                    </DropdownMenuCheckboxItem>
                    <DropdownMenuCheckboxItem class="text-xs" :model-value="map_user_settings.show_threat_level" @select.prevent="handleToggleThreatLevel">
                        <ShieldAlert class="size-3.5" /> Threats
                    </DropdownMenuCheckboxItem>
                    <DropdownMenuSeparator />
                    <div class="flex items-center gap-2 px-2 py-1 text-xs text-muted-foreground">
                        <span class="size-2 rounded-full" :class="isConnected ? 'bg-green-500' : isConnecting ? 'animate-pulse bg-yellow-500' : 'bg-red-500'" />
                        Live updates: {{ connectionStatusText }}
                    </div>
                </DropdownMenuContent>
            </DropdownMenu>

            <!-- ⚙: layout, settings, theme, scopes, docs, log out -->
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button type="button" class="flex h-7 w-7 items-center justify-center rounded-md border transition-colors" :class="layout.isEditMode.value ? 'border-blue-500/50 bg-blue-500/15 text-blue-300' : 'border-border/60 text-muted-foreground hover:text-foreground'" title="Layout, settings, theme…">
                        <Settings class="size-3.5" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-52">
                    <DropdownMenuItem class="text-xs" @select="handleLayoutToggle"><LayoutGrid class="size-3.5" /> {{ layout.isEditMode.value ? 'Stop editing the layout' : 'Layout (edit cards)' }}</DropdownMenuItem>
                    <DropdownMenuItem v-if="$page.props.auth.user" as-child class="text-xs"><Link :href="settingsUrl" class="flex w-full items-center gap-2"><MapIcon class="size-3.5" /> Map settings</Link></DropdownMenuItem>
                    <DropdownMenuItem class="text-xs" @select.prevent="updateAppearance(appearance === 'dark' ? 'light' : 'dark')">
                        <SunMoon class="size-3.5" /> Theme: {{ appearance === 'dark' ? 'dark' : 'light' }}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <template v-if="$page.props.auth.user">
                        <DropdownMenuItem as-child class="text-xs"><Link :href="SettingsController.show()" class="flex w-full items-center gap-2"><UserCog class="size-3.5" /> Settings</Link></DropdownMenuItem>
                        <DropdownMenuItem as-child class="text-xs">
                            <Link :href="SettingsController.show({ query: { section: 'esi' } })" class="flex w-full items-center gap-2" :class="missingScopes ? 'text-red-400' : ''">
                                <Shield class="size-3.5" /> {{ missingScopes ? `Scopes (${missingScopes} missing)` : 'Scopes' }}
                            </Link>
                        </DropdownMenuItem>
                    </template>
                    <DropdownMenuItem as-child class="text-xs"><Link :href="DocumentationController.index()" class="flex w-full items-center gap-2"><CircleHelp class="size-3.5" /> Documentation</Link></DropdownMenuItem>
                    <DropdownMenuItem as-child class="text-xs"><Link :href="home()" class="flex w-full items-center gap-2"><LayoutGrid class="size-3.5" /> All maps</Link></DropdownMenuItem>
                    <template v-if="$page.props.auth.user">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem as-child class="text-xs text-red-400">
                            <Link :href="logout()" method="delete" as="button" class="flex w-full items-center gap-2" @click="router.flushAll()"><LogOut class="size-3.5" /> Log out</Link>
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>

            <!-- EVE time and players online -->
            <div class="hidden pl-1 lg:block"><ServerStatus /></div>
        </div>
    </div>

    <!-- Tracking Signature Dialog -->
    <TrackingSignatureDialog
        v-model:open="show_signature_modal"
        :origin-map-solarsystem="origin_map_solarsystem"
        :target-solarsystem-name="targetSolarsystemName"
        :target-solarsystem-class="target_solarsystem?.class ?? null"
        :map-solarsystems="map_solarsystems"
        :preselect-first-signature="map_user_settings.preselect_signature_enabled"
        :signatures="signatures"
        :suggested-alias="suggested_alias"
        :planned-aliases="planned_aliases"
        :static-slot-alias="prompt_static_slot_alias"
        :static-owner-id="static_owner_id"
        :countdown-seconds="popup_seconds"
        :character-name="jumper_name"
        @select-signature="handleSelectSignature"
    />

    <!-- Right-click a connection → Add mass… -->
    <AddMassDialog />

    <!-- Leave Layout Editing Confirmation -->
    <Dialog v-model:open="showCancelLayoutDialog">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Save your layout changes?</DialogTitle>
                <DialogDescription>
                    You have unsaved layout changes. Save them, discard them and revert to your last saved layout, or keep editing.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2 sm:justify-between">
                <Button variant="outline" @click="showCancelLayoutDialog = false">Keep editing</Button>
                <div class="flex gap-2">
                    <Button variant="destructive" @click="discardLayoutAndExit">Discard</Button>
                    <Button @click="saveLayoutAndExit">Save</Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
/* Patch 35: the bar breathes red while a rage roll is on. */
.rage-bar {
    border-bottom-color: rgb(185 28 28 / 0.9);
    animation: rage-bar 1.6s ease-in-out infinite;
}
@keyframes rage-bar {
    0%,
    100% {
        background-color: rgb(69 10 10 / 0.55);
        box-shadow: inset 0 -1px 0 rgb(185 28 28), 0 0 10px rgb(127 29 29 / 0.6);
    }
    50% {
        background-color: rgb(127 29 29 / 0.6);
        box-shadow: inset 0 -1px 0 rgb(239 68 68), 0 0 24px rgb(220 38 38 / 0.85);
    }
}
@media (prefers-reduced-motion: reduce) {
    .rage-bar {
        animation: none;
        background-color: rgb(69 10 10 / 0.6);
    }
}
</style>
