<script setup lang="ts">
import { focusedClipboard } from '@/lib/popOut';
import { CharacterImage } from '@/components/images';
import SolarsystemStatusIcon from '@/components/map/SolarsystemStatusIcon.vue';
import SolarsystemExternalLinks from '@/components/solarsystem/SolarsystemExternalLinks.vue';
import {
    ContextMenuCheckboxItem,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuRadioGroup,
    ContextMenuRadioItem,
    ContextMenuSeparator,
    ContextMenuSub,
    ContextMenuSubContent,
    ContextMenuSubTrigger,
} from '@/components/ui/context-menu';
import { useHomeSystem } from '@/composables/useHomeSystem';
import { useNavigationSystems } from '@/composables/useNavigationSystems';
import usePermission from '@/composables/usePermission';
import { useRageRoll } from '@/composables/useRageRoll';
import { useRallyPoint } from '@/composables/useRallyPoint';
import useUser from '@/composables/useUser';
import { useWaypoint } from '@/composables/useWaypoint';
import { isWormholeClass } from '@/const/solarsystemClasses';
import { combatColorLabel, describeChainRoute } from '@/lib/combat';
import { startChainCleanup } from '@/map/actions/chainCleanup';
import { openClearChain } from '@/map/actions/clearChain';
import { deleteMapSolarsystem } from '@/map/actions/deleteMapSolarsystem';
import { updateMapSolarsystem } from '@/map/actions/updateMapSolarsystem';
import { useAddConnectionDialog } from '@/map/interactions/useAddConnectionDialog';
import { TMapSolarsystem } from '@/pages/maps';
import { TMapSolarsystemStatus } from '@/types/models';
import { Brush, Check, Megaphone, ClipboardCopy, Compass, Eraser, Eye, Flag, Home, Map, MapPin, Navigation, NotebookPen, Pin, Route, Scissors, Trash2, Users, Volume2, VolumeX, Waypoints } from 'lucide-vue-next';
import { isSystemMuted, setSystemMuted } from '@/lib/mutedSystems';
import { setConnectionWatched } from '@/map/actions/holeChecked';
import { chainParents } from '@/map/store/derived';
import { tryUseMapStore } from '@/map/store/mapStore';
import { addNoteTarget } from '@/map/components/overlays/AddNoteDialog.vue';
import { chainBeyondTarget } from '@/map/components/overlays/ChainBeyondDialog.vue';
import { displayAlias } from '@/lib/alias';
import type { AcceptableValue } from 'reka-ui';
import { toast } from 'vue-sonner';
import { computed } from 'vue';

/**
 * The per-node context menu, ported from the old tree as content-only: the new
 * MapNode has no per-node ContextMenu wrapper, so MapRoot renders this inside
 * the single canvas-wide ContextMenu for whichever node was right-clicked.
 */
const { map_solarsystem } = defineProps<{
    map_solarsystem: TMapSolarsystem;
}>();

const user = useUser();
// Patch 37: the system's name in the Add note box.
const displayName = computed(() => displayAlias(map_solarsystem.alias) || map_solarsystem.solarsystem.name);

const { canEdit: can_write } = usePermission();

const { isHome, toggleHomeSystem } = useHomeSystem(() => map_solarsystem.solarsystem_id);
const { isRally, toggleRallyPoint, pingHere } = useRallyPoint(() => map_solarsystem.solarsystem_id);
const { isRolling, openRageRoll, stopRageRoll } = useRageRoll();

/** Patch 35: tick → the RAGE ROLL popup; untick (this system is rolling) → the roll ends for everyone. */
function handleRageRoll(): void {
    if (isRolling(map_solarsystem.solarsystem_id)) {
        stopRageRoll();
        return;
    }
    openRageRoll(map_solarsystem.solarsystem_id);
}

const { setWaypoint, setWaypointAll, onlineCharacters } = useWaypoint();

const { setFromSystem, setToSystem } = useNavigationSystems();

const { openAddConnection } = useAddConnectionDialog();

function handleAddConnection() {
    openAddConnection(map_solarsystem);
}

function handleTogglePin() {
    updateMapSolarsystem(map_solarsystem, { pinned: !map_solarsystem.pinned });
}

function handleSetDestinationAll() {
    setWaypointAll(map_solarsystem.solarsystem_id);
}

function handleRemoveFromMap() {
    deleteMapSolarsystem(map_solarsystem);
}

function handleStatusChange(status: AcceptableValue) {
    updateMapSolarsystem(map_solarsystem, { status: status as string });
}

/** "Red route 1121 → highsec exit (Amarr, Domain)" for fleet chat. */
function handleCopyRoute() {
    const text = describeChainRoute(map_solarsystem);
    focusedClipboard().writeText(text).catch(() => undefined);
    toast.success('Copied route', { description: text });
}

/** Patch 14: offer the cleanup with a confirming toast (nothing is renamed when it starts). */
function handleStartCleanup() {
    const label = combatColorLabel(map_solarsystem.combat_color) ?? 'rage';
    toast(`Clean up the ${label} chain?`, {
        description: 'The home loses its color and Rage Scanning turns off for whoever works the chain. Nothing is renamed: each system converts when a scanner standing next to it presses Done.',
        duration: 15_000,
        action: { label: 'Start cleanup', onClick: () => startChainCleanup(map_solarsystem.id, label) },
    });
}

function handleClearCombatChain() {
    if (map_solarsystem.combat_color) openClearChain(map_solarsystem.combat_color);
}

const options: TMapSolarsystemStatus[] = ['unknown', 'friendly', 'hostile', 'active', 'unscanned', 'empty'];

// Patch 45: mute this system's kill sounds (just you; back to normal when it leaves the map).
const muted = computed(() => isSystemMuted(map_solarsystem.id));

// Patch 45: watch the hole leading into this system (the pipe from its parent, toward home).
const store = tryUseMapStore();
const inbound = computed(() => {
    if (!store) return null;
    const systems = [...store.systems.values()];
    const connections = [...store.connections.values()];
    const homeId = systems.find((system) => system.solarsystem_id === store.meta.value?.home_solarsystem_id)?.id ?? null;
    const parentId = chainParents(systems, connections, homeId).get(map_solarsystem.id);
    if (parentId === undefined) return null;
    return (
        connections.find(
            (connection) =>
                connection.type !== 'stargate' &&
                ((connection.from_map_solarsystem_id === parentId && connection.to_map_solarsystem_id === map_solarsystem.id) ||
                    (connection.to_map_solarsystem_id === parentId && connection.from_map_solarsystem_id === map_solarsystem.id)),
        ) ?? null
    );
});
</script>

<template>
    <ContextMenuContent>
        <!-- Patch 53: PING first: rally here, the ping dialog with the message box ready (Enter sends) -->
        <ContextMenuItem v-if="can_write" class="font-semibold text-pink-400" @select="pingHere()">
            <Megaphone class="size-4" />
            PING
        </ContextMenuItem>
        <ContextMenuItem @select="handleTogglePin" v-if="can_write">
            <Pin class="size-4" />
            {{ map_solarsystem.pinned ? 'Unpin' : 'Pin' }}
        </ContextMenuItem>
        <ContextMenuItem @select="handleAddConnection" v-if="can_write">
            <Waypoints class="size-4" />
            Add connection
        </ContextMenuItem>
        <!-- Patch 37: add a stamped note at the end of the system's notes -->
        <ContextMenuItem v-if="can_write" @select="addNoteTarget = { id: map_solarsystem.id, name: displayName }">
            <NotebookPen class="size-4" />
            Add note…
        </ContextMenuItem>
        <ContextMenuSub v-if="can_write">
            <ContextMenuSubTrigger>
                <Map class="size-4" />
                Status
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
                <ContextMenuRadioGroup :model-value="map_solarsystem.status ?? undefined" @update:model-value="handleStatusChange">
                    <ContextMenuRadioItem v-for="option in options" :key="option" :value="option">
                        {{ option.charAt(0).toUpperCase() + option.slice(1) }}
                        <SolarsystemStatusIcon :status="option" class="ml-auto" />
                    </ContextMenuRadioItem>
                </ContextMenuRadioGroup>
            </ContextMenuSubContent>
        </ContextMenuSub>

        <ContextMenuItem
            v-if="can_write && inbound"
            :title="inbound.mass_watched_at ? `Watched by ${inbound.mass_watched_by_name ?? 'someone'}: click to stop` : 'Watch the hole into this system: the 👁 on its pill and the watcher'"
            @select="setConnectionWatched(inbound.id, !inbound.mass_watched_at)"
        >
            <Eye class="size-4" />
            Watching the hole in
            <Check v-if="inbound.mass_watched_at" class="ml-auto size-4" />
        </ContextMenuItem>
        <ContextMenuItem :title="muted ? 'Kill sounds here are muted (just you)' : 'No kill sounds from this system (just you) until it leaves the map'" @select="setSystemMuted(map_solarsystem.id, !muted)">
            <VolumeX v-if="!muted" class="size-4" />
            <Volume2 v-else class="size-4" />
            {{ muted ? 'Unmute kill sounds' : 'Mute kill sounds' }}
        </ContextMenuItem>
        <ContextMenuItem @select="handleCopyRoute">
            <ClipboardCopy class="size-4" />
            Copy route
        </ContextMenuItem>
        <ContextMenuItem v-if="can_write && map_solarsystem.combat_home && map_solarsystem.combat_color" @select="handleStartCleanup">
            <Brush class="size-4" />
            Clean up {{ combatColorLabel(map_solarsystem.combat_color) ?? 'rage' }} chain…
        </ContextMenuItem>
        <ContextMenuItem v-if="can_write && map_solarsystem.combat_color" @select="handleClearCombatChain">
            <Eraser class="size-4" />
            Clear {{ combatColorLabel(map_solarsystem.combat_color) ?? 'rage' }} chain…
        </ContextMenuItem>

        <ContextMenuSeparator />

        <SolarsystemExternalLinks :solarsystem="map_solarsystem.solarsystem" />
        <ContextMenuSub v-if="user && !isWormholeClass(map_solarsystem.solarsystem.class)">
            <ContextMenuSubTrigger>
                <Navigation class="size-4" />
                Set destination
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
                <template v-if="onlineCharacters.length">
                    <ContextMenuItem
                        v-for="character in onlineCharacters"
                        :key="character.id"
                        @select="setWaypoint(character.id, map_solarsystem.solarsystem_id)"
                    >
                        <CharacterImage :character_id="character.id" :character_name="character.name" class="size-5 rounded-lg" />
                        {{ character.name }}
                    </ContextMenuItem>
                    <ContextMenuSeparator v-if="onlineCharacters.length > 1" />
                    <ContextMenuItem v-if="onlineCharacters.length > 1" @select="handleSetDestinationAll">
                        <Users class="size-4" />
                        All Characters
                    </ContextMenuItem>
                </template>
                <ContextMenuItem v-else disabled>No characters online</ContextMenuItem>
            </ContextMenuSubContent>
        </ContextMenuSub>

        <ContextMenuSub v-if="user && !isWormholeClass(map_solarsystem.solarsystem.class)">
            <ContextMenuSubTrigger>
                <MapPin class="size-4" />
                Add waypoint
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
                <template v-if="onlineCharacters.length">
                    <ContextMenuItem
                        v-for="character in onlineCharacters"
                        :key="character.id"
                        @select="setWaypoint(character.id, map_solarsystem.solarsystem_id, false)"
                    >
                        <CharacterImage :character_id="character.id" :character_name="character.name" class="size-5 rounded-lg" />
                        {{ character.name }}
                    </ContextMenuItem>
                    <ContextMenuSeparator v-if="onlineCharacters.length > 1" />
                    <ContextMenuItem v-if="onlineCharacters.length > 1" @select="setWaypointAll(map_solarsystem.solarsystem_id, false)">
                        <Users class="size-4" />
                        All Characters
                    </ContextMenuItem>
                </template>
                <ContextMenuItem v-else disabled>No characters online</ContextMenuItem>
            </ContextMenuSubContent>
        </ContextMenuSub>

        <ContextMenuSub>
            <ContextMenuSubTrigger>
                <Route class="size-4" />
                Route planner
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
                <ContextMenuItem @select="setFromSystem(map_solarsystem.solarsystem_id)">
                    <Compass class="size-4" />
                    Set as origin
                </ContextMenuItem>
                <ContextMenuItem @select="setToSystem(map_solarsystem.solarsystem_id)">
                    <Navigation class="size-4" />
                    Set as destination
                </ContextMenuItem>
            </ContextMenuSubContent>
        </ContextMenuSub>

        <ContextMenuSeparator />

        <ContextMenuItem @select="toggleHomeSystem" v-if="can_write">
            <Home class="size-4" />
            {{ isHome ? 'Unset Home System' : 'Set as Home System' }}
        </ContextMenuItem>
        <!-- Patch 35: RAGE ROLL, bold and red, just above the (pink) rally point -->
        <ContextMenuCheckboxItem
            v-if="can_write"
            :model-value="isRolling(map_solarsystem.solarsystem_id)"
            class="font-display font-bold tracking-wider text-red-500 uppercase focus:bg-red-950/60 focus:text-red-400"
            @select="handleRageRoll"
        >
            ⚡ Rage roll
        </ContextMenuCheckboxItem>
        <ContextMenuItem @select="toggleRallyPoint" v-if="can_write" class="text-pink-500 focus:bg-pink-950/50 focus:text-pink-400 [&_svg]:text-pink-500">
            <Flag class="size-4" />
            {{ isRally ? 'Clear Rally Point' : 'Set as Rally Point' }}
        </ContextMenuItem>

        <template v-if="!map_solarsystem.pinned && !isHome && can_write">
            <ContextMenuSeparator />
            <ContextMenuItem @select="handleRemoveFromMap" class="text-destructive focus:text-destructive">
                <Trash2 class="size-4" />
                Remove
            </ContextMenuItem>
            <ContextMenuItem @select="chainBeyondTarget = map_solarsystem.id" class="text-destructive focus:text-destructive">
                <Scissors class="size-4" />
                Delete system and chain beyond…
            </ContextMenuItem>
        </template>
    </ContextMenuContent>
</template>

<style scoped></style>
