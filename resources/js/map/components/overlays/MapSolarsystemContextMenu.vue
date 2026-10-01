<script setup lang="ts">
import { CharacterImage } from '@/components/images';
import SolarsystemStatusIcon from '@/components/map/SolarsystemStatusIcon.vue';
import SolarsystemExternalLinks from '@/components/solarsystem/SolarsystemExternalLinks.vue';
import {
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
import { Brush, ClipboardCopy, Compass, Eraser, Flag, Home, Map, MapPin, Navigation, Pin, Route, Trash2, Users, Waypoints } from 'lucide-vue-next';
import type { AcceptableValue } from 'reka-ui';
import { toast } from 'vue-sonner';

/**
 * The per-node context menu, ported from the old tree as content-only: the new
 * MapNode has no per-node ContextMenu wrapper, so MapRoot renders this inside
 * the single canvas-wide ContextMenu for whichever node was right-clicked.
 */
const { map_solarsystem } = defineProps<{
    map_solarsystem: TMapSolarsystem;
}>();

const user = useUser();

const { canEdit: can_write } = usePermission();

const { isHome, toggleHomeSystem } = useHomeSystem(() => map_solarsystem.solarsystem_id);
const { isRally, toggleRallyPoint } = useRallyPoint(() => map_solarsystem.solarsystem_id);

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
    navigator.clipboard.writeText(text).catch(() => undefined);
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
</script>

<template>
    <ContextMenuContent>
        <ContextMenuItem @select="handleTogglePin" v-if="can_write">
            <Pin class="size-4" />
            {{ map_solarsystem.pinned ? 'Unpin' : 'Pin' }}
        </ContextMenuItem>
        <ContextMenuItem @select="handleAddConnection" v-if="can_write">
            <Waypoints class="size-4" />
            Add connection
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
        <ContextMenuItem @select="toggleRallyPoint" v-if="can_write">
            <Flag class="size-4" />
            {{ isRally ? 'Clear Rally Point' : 'Set as Rally Point' }}
        </ContextMenuItem>

        <template v-if="!map_solarsystem.pinned && !isHome && can_write">
            <ContextMenuSeparator />
            <ContextMenuItem @select="handleRemoveFromMap" class="text-destructive focus:text-destructive">
                <Trash2 class="size-4" />
                Remove
            </ContextMenuItem>
        </template>
    </ContextMenuContent>
</template>

<style scoped></style>
