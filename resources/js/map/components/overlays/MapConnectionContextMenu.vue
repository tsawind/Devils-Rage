<script setup lang="ts">
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
import { useStaticData } from '@/composables/useStaticData';
import { displayAlias } from '@/lib/alias';
import { SHIP_SIZE_OPTIONS, shipSizeFromJumpMass } from '@/lib/shipSize';
import { formatDateToISO } from '@/lib/utils';
import { openAddMass } from '@/map/actions/addMass';
import { deleteMapConnection } from '@/map/actions/deleteMapConnection';
import { updateMapConnection } from '@/map/actions/updateMapConnection';
import ConnectionHoleTypeMenu from '@/map/components/overlays/ConnectionHoleTypeMenu.vue';
import ConnectionRelinkMenu from '@/map/components/overlays/ConnectionRelinkMenu.vue';
import CopyConnectionNameMenu from '@/map/components/overlays/CopyConnectionNameMenu.vue';
import { TMapConnection, TMapSolarsystem } from '@/pages/maps';
import { TConnectionType, TLifetimeStatus, TMassStatus, TShipSize } from '@/types/models';
import { UTCDate } from '@date-fns/utc';
import { Check, Clock, Heart, Plus, Ship, Trash2, TriangleAlert, Waypoints, Weight } from 'lucide-vue-next';
import type { AcceptableValue } from 'reka-ui';
import { computed } from 'vue';

const { map_connection } = defineProps<{
    map_connection: TMapConnection & { source: TMapSolarsystem; target: TMapSolarsystem };
}>();

const { staticData, loadStaticData } = useStaticData();
void loadStaticData();

// An identified wormhole type on a linked signature dictates the ship size,
// so the manual options are locked while one is present.
const locked_ship_size = computed(() => {
    for (const signature of map_connection.signatures ?? []) {
        const size = shipSizeFromJumpMass(signature.wormhole?.maximum_jump_mass);
        if (size) return size;
    }
    return null;
});

// Whether the two systems are actually joined by a stargate in the static data.
// Used to warn (but not block) when marking a connection as a stargate.
const is_gate_connected = computed(() => {
    const from = map_connection.source.solarsystem_id;
    const to = map_connection.target.solarsystem_id;
    return staticData.value?.connections[from]?.includes(to) ?? false;
});

/** Right-click a line → Add mass… (patch 12): mass seen going through, in million kg. */
function handleAddMass() {
    const name = (system: TMapSolarsystem) => displayAlias(system.alias) || system.solarsystem.name;
    openAddMass({ connectionId: map_connection.id, label: `${name(map_connection.source)} → ${name(map_connection.target)}` });
}

function handleRemoveFromMap() {
    deleteMapConnection(map_connection);
}

function handleTypeChange(type: AcceptableValue) {
    updateMapConnection(map_connection, { type: type as TConnectionType });
}

function handleTogglePreserveMass() {
    updateMapConnection(map_connection, { preserve_mass: !map_connection.preserve_mass });
}

function handleStatusChange(mass_status: AcceptableValue) {
    updateMapConnection(map_connection, { mass_status: mass_status as TMassStatus });
}

/** The radio group needs a value for "nobody has said yet", which the column stores as null. */
const UNKNOWN_SHIP_SIZE = 'unknown';

function handleShipSizeChange(ship_size: AcceptableValue) {
    updateMapConnection(map_connection, {
        ship_size: ship_size === UNKNOWN_SHIP_SIZE ? null : (ship_size as TShipSize),
    });
}

function handleLifetimeChange(lifetime: AcceptableValue) {
    updateMapConnection(map_connection, {
        lifetime: lifetime as TLifetimeStatus,
        lifetime_updated_at: formatDateToISO(new UTCDate()),
    });
}
</script>

<template>
    <ContextMenuContent>
        <!-- Patch 14: the hole's type, from the map -->
        <ConnectionHoleTypeMenu :connection="map_connection" />
        <!-- Patch 16: the jump went through another signature -->
        <ConnectionRelinkMenu :connection="map_connection" />
        <ContextMenuSub>
            <ContextMenuSubTrigger>
                <Clock class="size-4" />
                Lifetime
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
                <ContextMenuRadioGroup :model-value="map_connection.lifetime_status" @update:model-value="handleLifetimeChange">
                    <ContextMenuRadioItem value="healthy" class="flex items-center justify-between gap-2">
                        <span class="flex items-center gap-2">
                            <span class="inline-block size-2 rounded-full bg-neutral-500" />
                            Healthy
                        </span>
                    </ContextMenuRadioItem>
                    <ContextMenuRadioItem value="eol" class="flex items-center justify-between gap-2">
                        <span class="flex items-center gap-2">
                            <span class="inline-block size-2 rounded-full bg-purple-500" />
                            End of Life
                        </span>
                        <span class="text-muted-foreground">&lt; 4h</span>
                    </ContextMenuRadioItem>
                    <ContextMenuRadioItem value="critical" class="flex items-center justify-between gap-2">
                        <span class="flex items-center gap-2">
                            <span class="inline-block size-2 rounded-full bg-red-500" />
                            Critical
                        </span>
                        <span class="text-muted-foreground">&lt; 1h</span>
                    </ContextMenuRadioItem>
                </ContextMenuRadioGroup>
                <!-- Patch 16: re-confirm a healthy hole (clears the faint "likely EOL by age" clock). -->
                <template v-if="map_connection.lifetime_status === 'healthy'">
                    <ContextMenuSeparator />
                    <ContextMenuItem class="text-xs" @select="handleLifetimeChange('healthy')">
                        <Check class="size-4" />
                        Checked: still healthy
                    </ContextMenuItem>
                </template>
            </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSub>
            <ContextMenuSubTrigger>
                <Weight class="size-4" />
                Mass Status
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
                <ContextMenuRadioGroup :model-value="map_connection.mass_status" @update:model-value="handleStatusChange">
                    <ContextMenuRadioItem value="fresh" class="flex items-center justify-between gap-2">
                        <span class="flex items-center gap-2">
                            <span class="inline-block size-2 rounded-full bg-neutral-500" />
                            Fresh
                        </span>
                        <span class="text-muted-foreground">&ge; 50%</span>
                    </ContextMenuRadioItem>
                    <ContextMenuRadioItem value="reduced" class="flex items-center justify-between gap-2">
                        <span class="flex items-center gap-2">
                            <span class="inline-block size-2 rounded-full bg-amber-500" />
                            Reduced
                        </span>
                        <span class="text-muted-foreground">&lt; 50%</span>
                    </ContextMenuRadioItem>
                    <ContextMenuRadioItem value="critical" class="flex items-center justify-between gap-2">
                        <span class="flex items-center gap-2">
                            <span class="inline-block size-2 rounded-full bg-red-500" />
                            Critical
                        </span>
                        <span class="text-muted-foreground">&le; 15%</span>
                    </ContextMenuRadioItem>
                </ContextMenuRadioGroup>
            </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSub>
            <ContextMenuSubTrigger>
                <Ship class="size-4" />
                Ship Size
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
                <ContextMenuRadioGroup :model-value="map_connection.ship_size ?? UNKNOWN_SHIP_SIZE" @update:model-value="handleShipSizeChange">
                    <ContextMenuRadioItem :value="UNKNOWN_SHIP_SIZE" class="flex items-center gap-2" :disabled="locked_ship_size !== null">
                        <span class="inline-flex w-6 justify-center font-mono text-[11px] leading-4 text-muted-foreground">?</span>
                        Unknown
                    </ContextMenuRadioItem>
                    <ContextMenuRadioItem
                        v-for="option in SHIP_SIZE_OPTIONS"
                        :key="option.value"
                        :value="option.value"
                        class="flex items-center gap-2"
                        :disabled="locked_ship_size !== null"
                    >
                        <span class="inline-flex w-6 justify-center font-mono text-[11px] leading-4 text-muted-foreground">{{ option.letter }}</span>
                        {{ option.label }}
                    </ContextMenuRadioItem>
                </ContextMenuRadioGroup>
                <div v-if="locked_ship_size" class="px-2 py-1 text-[11px] text-muted-foreground">Locked by the identified wormhole type</div>
            </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuSub>
            <ContextMenuSubTrigger>
                <Waypoints class="size-4" />
                Connection type
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
                <ContextMenuRadioGroup :model-value="map_connection.type" @update:model-value="handleTypeChange">
                    <ContextMenuRadioItem value="wormhole">Wormhole</ContextMenuRadioItem>
                    <ContextMenuRadioItem value="stargate" class="flex items-center justify-between gap-2">
                        Stargate
                        <TriangleAlert v-if="!is_gate_connected" class="size-3.5 text-amber-500" />
                    </ContextMenuRadioItem>
                </ContextMenuRadioGroup>
                <p v-if="!is_gate_connected" class="max-w-52 px-2 py-1 text-xs text-muted-foreground">
                    These systems aren't connected by a stargate. You can still mark it as one.
                </p>
            </ContextMenuSubContent>
        </ContextMenuSub>
        <ContextMenuItem v-if="map_connection.type !== 'stargate'" @select="handleAddMass">
            <Plus class="size-4" />
            Add mass…
        </ContextMenuItem>
        <ContextMenuSeparator />
        <ContextMenuItem @select.prevent="handleTogglePreserveMass">
            <Heart class="size-4" />
            Preserve mass
            <Check v-if="map_connection.preserve_mass" class="ml-auto size-4" />
        </ContextMenuItem>
        <ContextMenuSeparator />
        <CopyConnectionNameMenu :map_connection="map_connection" />
        <ContextMenuSeparator />
        <ContextMenuItem @click="handleRemoveFromMap" class="text-destructive focus:text-destructive">
            <Trash2 class="size-4" />
            Remove connection
        </ContextMenuItem>
    </ContextMenuContent>
</template>
