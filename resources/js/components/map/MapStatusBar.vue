<script setup lang="ts">
import { centerOnMe, setCenterOnMe } from '@/composables/useCenterOnMe';
import MapAccessController from '@/actions/App/Http/Controllers/MapAccessController';
import MapPreferencesController from '@/actions/App/Http/Controllers/MapPreferencesController';
import MapSettingsController from '@/actions/App/Http/Controllers/MapSettingsController';
import TrackingIcon from '@/components/icons/TrackingIcon.vue';
import AddMassDialog from '@/map/components/overlays/connection/AddMassDialog.vue';
import StaleConnectionsBadge from '@/components/map/StaleConnectionsBadge.vue';
import SolarsystemClass from '@/components/solarsystem/SolarsystemClass.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useCombat } from '@/composables/combat/useCombat';
import { useTracking } from '@/composables/signatures/useTracking';
import { useActiveMapCharacter } from '@/composables/useActiveMapCharacter';
import { UseMapLayoutReturn } from '@/composables/useMapLayout';
import usePermission from '@/composables/usePermission';
import { usePing } from '@/composables/usePing';
import { useStaticSolarsystem } from '@/composables/useStaticSolarsystems';
import { updateMapUserSettings, useMapSolarsystems } from '@/map/api';
import type { TMap } from '@/pages/maps';
import type { TMapUserSetting } from '@/types/models';
import { Link } from '@inertiajs/vue3';
import { useConnectionStatus } from '@laravel/echo-vue';
import { ConnectionStatus } from 'laravel-echo';
import {
    ChevronsDown,
    ChevronsUp,
    ClipboardCopy,
    Crosshair,
    Eye,
    EyeOff,
    LayoutGrid,
    LocateFixed,
    Redo2,
    Settings,
    ShieldAlert,
    Undo2,
    Wifi,
    WifiOff,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { canRedo, canUndo, handleUndoKeydown, redoLabel, redoLast, undoLabel, undoLast } from '@/composables/signatures/signatureUndo';
import { useMapChrome } from '@/composables/useMapChrome';
import { displayAlias } from '@/lib/alias';
import type { TResolvedMapNavigation, TResolvedSelectedMapSolarsystem } from '@/pages/maps';
import type { TCharacter } from '@/types/models';
import CommandPaletteButton from './CommandPaletteButton.vue';
import RouteCopyButtons from './RouteCopyButtons.vue';
import MapSwitcher from './MapSwitcher.vue';
import RoutingBox from './RoutingBox.vue';
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

// Patch 23: fold the site header away (your character floats on the map; this toolbar shrinks to small icons).
const { barsFolded, setBarsFolded } = useMapChrome();
const labelClass = computed(() => (barsFolded.value ? 'hidden' : 'hidden md:inline'));

// Initialize tracking
usePing(map);

const character = useActiveMapCharacter();
const { canEdit, canManageAccess } = usePermission();

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
    <div class="relative flex h-10 shrink-0 items-center gap-2 border-b border-border/50 bg-muted/30 px-2 sm:gap-3 sm:px-3">
        <!-- Map Name (patch 26: opens a list of your maps, plus New map) -->
        <MapSwitcher :map="map" />

        <div class="hidden h-4 w-px bg-border/50 sm:block" />

        <!-- Search + Routing (patch 23: Search a bit narrower, Routing next to it) -->
        <div class="hidden items-center gap-2 sm:flex">
            <div class="w-56 lg:w-64">
                <CommandPaletteButton />
            </div>
            <div class="w-40 lg:w-48">
                <RoutingBox :map :map_navigation :map_characters :selected_map_solarsystem :ignored_systems />
            </div>
            <!-- Patch 26: copy the route for fleet chat (Default, Safest, ▾ the rest) -->
            <RouteCopyButtons variant="bar" />
        </div>

        <!-- Spacer -->
        <div class="flex-1" />

        <!-- Info badges: in line before the pilot location (centred, they covered it) -->
        <div class="flex shrink-0 items-center gap-2">
            <!-- Active Character Access Warning -->
            <Tooltip v-if="$page.props.auth.user && !$page.props.active_character_has_access">
                <TooltipTrigger as-child>
                    <component
                        :is="canManageAccess ? Link : 'div'"
                        :href="canManageAccess ? MapAccessController.show(map.slug) : undefined"
                        class="flex items-center gap-1.5 rounded-full border border-sky-500/30 bg-sky-500/10 px-3 py-1 text-xs font-medium text-sky-300 shadow-sm transition-colors hover:bg-sky-500/20"
                    >
                        <span class="whitespace-nowrap">Alt · account access</span>
                    </component>
                </TooltipTrigger>
                <TooltipContent side="bottom">
                    <p class="text-xs font-medium text-sky-300">Alt access</p>
                    <p class="max-w-xs text-xs text-muted-foreground">
                        {{ $page.props.auth.user.active_character?.name ?? 'This character' }}
                        isn't on the access list itself, so it uses the best access of the other characters on your account
                        (your main): it can map, track and shows on the map the same way.{{
                            canManageAccess ? ' Click to manage access.' : ''
                        }}
                    </p>
                </TooltipContent>
            </Tooltip>

            <!-- Stale Connections Cleanup -->
            <StaleConnectionsBadge />
        </div>

        <!-- Pilot Location -->
        <div v-if="character && currentSolarsystem" class="hidden items-center gap-2 lg:flex">
            <span class="text-[11px] tracking-wider text-muted-foreground uppercase">Location</span>
            <div class="flex items-center gap-1.5">
                <SolarsystemClass :solarsystem_class="currentSolarsystem.class" :name="currentSolarsystem.name" />
                <span v-if="currentAlias" class="text-xs font-semibold">{{ currentAlias }}</span>
                <span class="text-xs" :class="currentAlias ? 'text-muted-foreground' : ''">{{ currentSolarsystem.name }}</span>
            </div>
        </div>

        <div class="hidden h-4 w-px bg-border/50 lg:block" />

        <!-- Patch 23: the toolbar; small icons only while the site header is folded away -->
        <div class="flex items-center" :class="barsFolded ? 'origin-right scale-[0.7] gap-1' : 'gap-2 sm:gap-3'">
        <!-- Connection Status -->
        <Tooltip>
            <TooltipTrigger as-child>
                <div class="flex items-center gap-1.5">
                    <div
                        class="size-2 rounded-full"
                        :class="{
                            'bg-green-500': isConnected,
                            'animate-pulse bg-yellow-500': isConnecting,
                            'bg-red-500': !isConnected && !isConnecting,
                        }"
                    />
                    <Wifi v-if="isConnected" class="hidden size-3.5 text-muted-foreground sm:block" />
                    <WifiOff v-else class="hidden size-3.5 text-muted-foreground sm:block" />
                </div>
            </TooltipTrigger>
            <TooltipContent side="bottom">
                <p class="text-xs">{{ connectionStatusText }}</p>
            </TooltipContent>
        </Tooltip>

        <div class="hidden h-4 w-px bg-border/50 md:block" />

        <!-- Location Visibility Toggle -->
        <Tooltip v-if="canEdit">
            <TooltipTrigger as-child>
                <button
                    @click="handleToggleVisibility"
                    class="flex items-center gap-1.5 rounded px-1.5 py-1 text-xs transition-colors sm:px-2"
                    :class="
                        map_user_settings.tracking_allowed
                            ? 'bg-green-700 text-white hover:bg-green-800 dark:bg-green-500/20 dark:text-green-400 dark:hover:bg-green-500/30'
                            : 'bg-muted text-muted-foreground hover:bg-muted/80'
                    "
                >
                    <Eye v-if="map_user_settings.tracking_allowed" class="size-3.5" />
                    <EyeOff v-else class="size-3.5" />
                    <span :class="labelClass">Visible</span>
                </button>
            </TooltipTrigger>
            <TooltipContent side="bottom">
                <p class="text-xs font-medium">Location Monitoring</p>
                <p class="text-xs text-muted-foreground">
                    {{ map_user_settings.tracking_allowed ? 'Enabled' : 'Disabled' }} - Share location with map users
                </p>
            </TooltipContent>
        </Tooltip>

        <!-- Tracking Toggle -->
        <Tooltip v-if="canEdit">
            <TooltipTrigger as-child>
                <button
                    @click="toggleTracking"
                    :disabled="!can_track"
                    class="flex items-center gap-1.5 rounded px-1.5 py-1 text-xs transition-colors disabled:cursor-not-allowed disabled:opacity-50 sm:px-2"
                    :class="is_tracking ? 'bg-amber-600 text-white hover:bg-amber-700 dark:bg-amber-500/20 dark:text-amber-400 dark:hover:bg-amber-500/30' : 'bg-muted text-muted-foreground hover:bg-muted/80'"
                >
                    <TrackingIcon class="size-3.5" />
                    <span :class="labelClass">Tracking</span>
                </button>
            </TooltipTrigger>
            <TooltipContent side="bottom">
                <template v-if="!is_tracking_allowed">
                    <p class="text-xs font-medium text-red-500">Tracking Unavailable</p>
                    <p class="text-xs text-muted-foreground">Enable location monitoring first</p>
                </template>
                <template v-else-if="!character">
                    <p class="text-xs font-medium text-red-500">Character Status Unknown</p>
                    <p class="max-w-xs text-xs text-muted-foreground">Active character must be online with scopes granted</p>
                </template>
                <template v-else>
                    <p class="text-xs font-medium">Auto-Tracking</p>
                    <p class="text-xs text-muted-foreground">{{ is_tracking ? 'Enabled' : 'Disabled' }} - Track system changes</p>
                </template>
            </TooltipContent>
        </Tooltip>

        <!-- Patch 19: Clipboard: off = the mapper never writes your clipboard on its own -->
        <Tooltip v-if="canEdit">
            <TooltipTrigger as-child>
                <button
                    @click="toggleClipboard"
                    class="flex items-center gap-1.5 rounded px-1.5 py-1 text-xs transition-colors sm:px-2"
                    :class="clipboard_on ? 'bg-violet-700 text-white hover:bg-violet-800 dark:bg-violet-500/20 dark:text-violet-300 dark:hover:bg-violet-500/30' : 'bg-muted text-muted-foreground hover:bg-muted/80'"
                >
                    <ClipboardCopy class="size-3.5" />
                    <span :class="labelClass">Clipboard</span>
                </button>
            </TooltipTrigger>
            <TooltipContent side="bottom">
                <p class="text-xs font-medium">Clipboard</p>
                <p class="max-w-xs text-xs text-muted-foreground">
                    {{
                        clipboard_on
                            ? 'On - the mapper copies bookmark names for you (arming, type picks, renames, the way back)'
                            : 'Off - the mapper never writes your clipboard on its own; names show with a Copy button'
                    }}
                </p>
            </TooltipContent>
        </Tooltip>

        <!-- Patch 19: Follow and Center joined, each on/off by itself -->
        <div
            class="flex items-stretch overflow-hidden rounded transition-shadow"
            :class="follow_enabled && centerOnMe ? 'shadow-[0_0_8px_rgba(56,189,248,0.45)]' : ''"
        >
            <Tooltip v-if="canEdit">
                <TooltipTrigger as-child>
                    <button
                        @click="toggleFollow"
                        class="flex items-center gap-1.5 px-1.5 py-1 text-xs transition-colors sm:px-2"
                        :class="follow_enabled ? 'bg-sky-700 text-white hover:bg-sky-800 dark:bg-sky-500/30 dark:text-sky-200 dark:hover:bg-sky-500/40' : 'bg-sky-100 text-sky-800 hover:bg-sky-200 dark:bg-sky-950/60 dark:text-sky-600 dark:hover:bg-sky-900/60'"
                    >
                        <LocateFixed class="size-3.5" />
                        <span :class="labelClass">Follow</span>
                    </button>
                </TooltipTrigger>
                <TooltipContent side="bottom">
                    <p class="text-xs font-medium">Follow Pilot</p>
                    <p class="text-xs text-muted-foreground">
                        {{ follow_enabled ? 'Enabled' : 'Disabled' }} - Select the system your character jumps into
                    </p>
                </TooltipContent>
            </Tooltip>
            <div class="w-px bg-sky-400/30" />
            <Tooltip>
                <TooltipTrigger as-child>
                    <button
                        @click="setCenterOnMe(!centerOnMe)"
                        class="flex items-center gap-1.5 px-1.5 py-1 text-xs transition-colors sm:px-2"
                        :class="centerOnMe ? 'bg-sky-700 text-white hover:bg-sky-800 dark:bg-sky-500/30 dark:text-sky-200 dark:hover:bg-sky-500/40' : 'bg-sky-100 text-sky-800 hover:bg-sky-200 dark:bg-sky-950/60 dark:text-sky-600 dark:hover:bg-sky-900/60'"
                    >
                        <Crosshair class="size-3.5" />
                        <span :class="labelClass">Center</span>
                    </button>
                </TooltipTrigger>
                <TooltipContent side="bottom">
                    <p class="text-xs font-medium">Center on me</p>
                    <p class="text-xs text-muted-foreground">
                        {{ centerOnMe ? 'On' : 'Off' }} - Center the map on your system after every jump, and when it moves on the map (this browser)
                    </p>
                </TooltipContent>
            </Tooltip>
        </div>

        <!-- Patch 21: the global undo / redo (any of your map changes) -->
        <div v-if="canEdit" class="flex items-center gap-0.5">
            <Tooltip>
                <TooltipTrigger as-child>
                    <button
                        @click="undoLast()"
                        :disabled="!canUndo"
                        class="flex items-center rounded px-1.5 py-1 text-xs text-muted-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
                        aria-label="Undo"
                    >
                        <Undo2 class="size-3.5" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="bottom">
                    <p class="text-xs font-medium">Undo{{ undoLabel ? `: ${undoLabel}` : '' }}</p>
                    <p class="max-w-xs text-xs text-muted-foreground">Ctrl+Z · your last map change: moves, deletes, pastes, signature edits…</p>
                </TooltipContent>
            </Tooltip>
            <Tooltip>
                <TooltipTrigger as-child>
                    <button
                        @click="redoLast()"
                        :disabled="!canRedo"
                        class="flex items-center rounded px-1.5 py-1 text-xs text-muted-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
                        aria-label="Redo"
                    >
                        <Redo2 class="size-3.5" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="bottom">
                    <p class="text-xs font-medium">Redo{{ redoLabel ? `: ${redoLabel}` : '' }}</p>
                    <p class="text-xs text-muted-foreground">Ctrl+Y · put back what you undid</p>
                </TooltipContent>
            </Tooltip>
        </div>

        <!-- Threat Analysis Toggle -->
        <Tooltip>
            <TooltipTrigger as-child>
                <button
                    @click="handleToggleThreatLevel"
                    class="flex items-center gap-1.5 rounded px-1.5 py-1 text-xs transition-colors sm:px-2"
                    :class="
                        map_user_settings.show_threat_level
                            ? 'bg-red-700 text-white hover:bg-red-800 dark:bg-red-500/20 dark:text-red-400 dark:hover:bg-red-500/30'
                            : 'bg-muted text-muted-foreground hover:bg-muted/80'
                    "
                >
                    <ShieldAlert class="size-3.5" />
                    <span :class="labelClass">Threats</span>
                </button>
            </TooltipTrigger>
            <TooltipContent side="bottom">
                <p class="text-xs font-medium">Wormhole Threat Analysis</p>
                <p class="text-xs text-muted-foreground">
                    {{ map_user_settings.show_threat_level ? 'Showing' : 'Hidden' }} - Threat level rings on systems
                </p>
            </TooltipContent>
        </Tooltip>

        <div class="hidden h-4 w-px bg-border/50 md:block" />

        <!-- Layout Edit Toggle (patch 26: hidden while the top bar is folded, unless you are editing) -->
        <Tooltip v-if="!barsFolded || layout.isEditMode.value">
            <TooltipTrigger as-child>
                <button
                    @click="handleLayoutToggle"
                    class="flex items-center gap-1.5 rounded px-1.5 py-1 text-xs transition-colors sm:px-2"
                    :class="
                        layout.isEditMode.value
                            ? 'bg-blue-500/20 text-blue-400 hover:bg-blue-500/30'
                            : 'bg-muted text-muted-foreground hover:bg-muted/80'
                    "
                >
                    <LayoutGrid class="size-3.5" />
                    <span :class="labelClass">Layout</span>
                </button>
            </TooltipTrigger>
            <TooltipContent side="bottom">
                <p class="text-xs">{{ layout.isEditMode.value ? 'Exit Layout Edit Mode' : 'Edit Layout' }}</p>
            </TooltipContent>
        </Tooltip>

        <!-- Settings (authenticated users only) -->
        <Link
            v-if="$page.props.auth.user"
            :href="settingsUrl"
            class="flex items-center gap-1.5 rounded bg-muted px-1.5 py-1 text-xs text-muted-foreground transition-colors hover:bg-muted/80 hover:text-foreground sm:px-2"
            prefetch
        >
            <Settings class="size-3.5" />
            <span :class="labelClass">Settings</span>
        </Link>
        </div>

        <!-- Patch 23: fold the site header away -->
        <Tooltip>
            <TooltipTrigger as-child>
                <button
                    type="button"
                    class="flex items-center rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                    :aria-label="barsFolded ? 'Show the top bar' : 'Fold the top bar away'"
                    @click="setBarsFolded(!barsFolded)"
                >
                    <ChevronsDown v-if="barsFolded" class="size-3.5" />
                    <ChevronsUp v-else class="size-3.5" />
                </button>
            </TooltipTrigger>
            <TooltipContent side="bottom">
                <p class="text-xs">{{ barsFolded ? 'Show the top bar' : 'Fold the top bar away (more room; your character floats on the map)' }}</p>
            </TooltipContent>
        </Tooltip>
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
