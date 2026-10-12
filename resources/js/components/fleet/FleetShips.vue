<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import {
    comesWithUs,
    dropInSquad,
    fleetMembers,
    fleetScope,
    fleetShips,
    moveInSquad,
    mySquadIds,
    myWingIds,
    pickedSquads,
    setComesWithUs,
    setFleetScope,
    setSquadTicked,
    squadKey,
    squadOrder,
    squadTicked,
    togglePickedSquad,
    type TFleetMember,
    type TFleetScope,
    type TFleetShip,
    type TFleetWing,
} from '@/composables/useFleet';
import { profileFor } from '@/composables/useShipProfiles';
import { massText } from '@/lib/rollPlan';
import { computed, ref } from 'vue';

/**
 * Patch 37: the fleet laid out like EVE's fleet window: the fleet commander, then each wing
 * with its commander and its squads (EVE's names; empty squads left out). "Count" picks which
 * part counts (whole fleet, my wing, my squads, picked squads); ticks per group and per ship
 * (caps start unticked). Drag a name (or ▲▼) into your ISBoxer order. Columns: cold (prop
 * off), hot (heaviest prop on), tiny (the smallest it can jump: a HIC's entanglers). Hover a
 * ship for its fit and every mass it can make.
 */
const { wings } = defineProps<{ wings: TFleetWing[] }>();

const byId = computed(() => new Map(fleetShips.value.map((ship) => [ship.character_id, ship])));

type TGroup = { key: number; name: string; commander: boolean; mine: boolean; members: TFleetMember[] };

/** Fleet commander, then each wing (its commander first) and its squads. */
const tree = computed(() => {
    const members = fleetMembers.value;
    const group = (key: number, name: string, commander: boolean): TGroup => ({
        key,
        name,
        commander,
        mine: mySquadIds.value.includes(key),
        members: squadOrder(
            key,
            members.filter((member) => squadKey(member) === key),
        ),
    });
    const boss = group(-1, 'Fleet commander', true);
    const wingIds = [...new Set([...wings.map((wing) => wing.id), ...members.map((member) => member.wing_id).filter((id) => id > 0)])];
    const list = wingIds.map((wingId, wingIndex) => {
        const wing = wings.find((candidate) => candidate.id === wingId);
        const squadIds = [...new Set([...(wing?.squads.map((squad) => squad.id) ?? []), ...members.filter((member) => member.wing_id === wingId && member.squad_id > 0).map((member) => member.squad_id)])];
        return {
            id: wingId,
            name: wing?.name || `Wing ${wingIndex + 1}`,
            mine: myWingIds.value.includes(wingId),
            commander: group(-wingId, 'Wing commander', true),
            squads: squadIds
                .map((squadId, squadIndex) => group(squadId, wing?.squads.find((squad) => squad.id === squadId)?.name || `Squad ${squadIndex + 1}`, false))
                .filter((squad) => squad.members.length > 0),
        };
    });
    return { boss, wings: list.filter((wing) => wing.squads.length > 0 || wing.commander.members.length > 0) };
});

const scopes = computed<{ key: TFleetScope; label: string; count: number }[]>(() => [
    { key: 'fleet', label: 'Whole fleet', count: fleetMembers.value.length },
    { key: 'wing', label: 'My wing', count: fleetMembers.value.filter((member) => myWingIds.value.includes(member.wing_id)).length },
    { key: 'squads', label: 'My squads', count: fleetMembers.value.filter((member) => mySquadIds.value.includes(squadKey(member))).length },
    { key: 'pick', label: 'Pick squads', count: fleetMembers.value.filter((member) => pickedSquads.value.includes(squadKey(member))).length },
]);

function ship(member: TFleetMember): TFleetShip | undefined {
    return byId.value.get(member.character_id);
}

/** What the fit adds: Higgs, plates, props, entanglers; "typed in" / "hull only" otherwise. */
function fitChips(member: TFleetMember): string[] {
    const profile = profileFor(member.character_id, member.ship_type_id);
    if (!profile || profile.source === 'hull') return ['hull only'];
    if (profile.source === 'manual') return ['typed in'];
    const props = (profile.options ?? []).filter((option) => option.mode === 'hot' && !option.label.includes('entangler')).length;
    return [
        profile.higgs ? 'Higgs' : null,
        profile.plates ? `${profile.plates} plate${profile.plates === 1 ? '' : 's'}` : null,
        props > 1 ? `${props} props` : props === 1 || profile.prop_mass ? 'prop' : null,
        profile.entanglers ? `${profile.entanglers}× entangler` : null,
    ].filter((chip): chip is string => chip !== null);
}

/** The hover: the fit SeAT saw and every mass the ship can make. */
function fitTitle(member: TFleetMember): string {
    const profile = profileFor(member.character_id, member.ship_type_id);
    const entry = ship(member);
    const lines = [`${member.name} · ${member.ship ?? '?'}`];
    if (profile?.modules?.length) {
        lines.push('', ...profile.modules.map((module) => `${module.slot.replace('Slot', '')}: ${module.name}`));
    } else {
        lines.push('', profile?.source === 'manual' ? `Typed in${profile.note ? `: ${profile.note}` : ''}` : 'Fit not known (bare hull).');
    }
    if (entry) lines.push('', 'Jump masses:', ...entry.options.map((option) => `  ${option.label}: ${massText(option.mass)}`));
    return lines.join('\n');
}

// Drag a name to put the squad in your order.
const dragging = ref<{ key: number; id: number } | null>(null);
function onDrop(group: TGroup, beforeId: number | null): void {
    if (!dragging.value || dragging.value.key !== group.key) return;
    dropInSquad(group.key, group.members, dragging.value.id, beforeId);
    dragging.value = null;
}
</script>

<template>
    <div class="grid min-w-0 gap-2 text-xs">
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="text-[11px] text-muted-foreground">Count:</span>
            <button
                v-for="scope in scopes"
                :key="scope.key"
                type="button"
                class="rounded-full border px-2.5 py-0.5"
                :class="fleetScope === scope.key ? 'border-emerald-500 bg-emerald-950/40 text-emerald-300' : 'border-border text-muted-foreground hover:text-foreground'"
                @click="setFleetScope(scope.key)"
            >
                {{ scope.label }} {{ scope.count }}
            </button>
        </div>
        <p v-if="fleetScope === 'pick'" class="text-[11px] text-muted-foreground">Click a squad's name to pick it.</p>

        <div class="grid grid-cols-[1.75rem_auto_minmax(0,1fr)_minmax(0,1.3fr)_3.75rem_3.75rem_3.75rem] items-center gap-x-1.5 gap-y-0.5 text-[10px] text-muted-foreground uppercase">
            <span></span><span></span><span>Pilot</span><span>Ship</span><span class="text-right">Cold</span><span class="text-right">Hot</span><span class="text-right">Tiny</span>
        </div>

        <template v-for="block in [{ id: 0, name: '', mine: false, commander: tree.boss, squads: [] as TGroup[] }, ...tree.wings]" :key="block.id">
            <div v-if="block.id !== 0" class="mt-1 font-semibold">
                {{ block.name }}<span v-if="block.mine" class="ml-1 font-normal text-emerald-400">· mine</span>
            </div>
            <template v-for="group in [block.commander, ...block.squads]" :key="group.key">
                <div v-if="group.members.length" class="grid gap-0.5" :class="block.id === 0 ? '' : 'pl-2'">
                    <div class="flex items-center gap-2 text-muted-foreground">
                        <Checkbox :model-value="squadTicked(group.key)" :aria-label="`${group.name} comes with us`" @update:model-value="(value) => setSquadTicked(group.key, value === true)" />
                        <button
                            type="button"
                            class="text-left"
                            :class="{ 'text-emerald-300': fleetScope === 'pick' && pickedSquads.includes(group.key), 'cursor-default': fleetScope !== 'pick', italic: group.commander }"
                            @click="fleetScope === 'pick' && togglePickedSquad(group.key)"
                        >
                            {{ group.name }}<template v-if="!group.commander"> · {{ group.members.length }}</template><span v-if="group.mine" class="text-emerald-400"> · mine</span>
                        </button>
                    </div>
                    <div
                        v-for="(member, index) in group.members"
                        :key="member.character_id"
                        class="grid grid-cols-[1.75rem_auto_minmax(0,1fr)_minmax(0,1.3fr)_3.75rem_3.75rem_3.75rem] items-center gap-x-1.5 rounded pl-2"
                        :class="{ 'opacity-50': !ship(member)?.counts, 'bg-muted/60': dragging?.id === member.character_id }"
                        @dragover.prevent
                        @drop.prevent="onDrop(group, member.character_id)"
                    >
                        <span class="flex flex-col leading-none text-muted-foreground">
                            <button type="button" class="text-[9px] hover:text-foreground disabled:opacity-30" :disabled="index === 0" :aria-label="`Move ${member.name} up`" @click="moveInSquad(group.key, group.members, member.character_id, -1)">▲</button>
                            <button
                                type="button"
                                class="text-[9px] hover:text-foreground disabled:opacity-30"
                                :disabled="index === group.members.length - 1"
                                :aria-label="`Move ${member.name} down`"
                                @click="moveInSquad(group.key, group.members, member.character_id, 1)"
                            >
                                ▼
                            </button>
                        </span>
                        <Checkbox :model-value="comesWithUs(member)" :aria-label="`${member.name} comes with us`" @update:model-value="(value) => setComesWithUs(member, value === true)" />
                        <span
                            class="cursor-grab truncate active:cursor-grabbing"
                            draggable="true"
                            title="Drag to change the order"
                            @dragstart="dragging = { key: group.key, id: member.character_id }"
                            @dragend="dragging = null"
                        >
                            <span class="mr-1 font-mono text-muted-foreground">{{ index + 1 }}</span>{{ member.name }}
                        </span>
                        <span class="truncate text-muted-foreground" :title="fitTitle(member)">
                            {{ member.ship ?? '?' }}<template v-if="member.capital && !comesWithUs(member)"> · staying</template>
                            <span v-for="chip in fitChips(member)" :key="chip" class="ml-1 rounded border border-border px-1 text-[10px]">{{ chip }}</span>
                        </span>
                        <span class="text-right font-mono">{{ massText(ship(member)?.cold ?? member.jump_mass) }}</span>
                        <span class="text-right font-mono text-amber-300">{{ massText(ship(member)?.hot ?? member.jump_mass) }}</span>
                        <span class="text-right font-mono text-sky-300">{{ (ship(member)?.tiny ?? 0) < (ship(member)?.cold ?? 0) ? massText(ship(member)?.tiny ?? 0) : '—' }}</span>
                    </div>
                    <div v-if="dragging?.key === group.key" class="h-2 rounded border border-dashed border-border/60" @dragover.prevent @drop.prevent="onDrop(group, null)"></div>
                </div>
            </template>
        </template>
        <p class="text-[11px] text-muted-foreground">
            Cold = prop off, hot = heaviest prop on, tiny = the smallest it can jump (a HIC's entanglers). Hover a ship for its fit and every mass. Masses from each ship's profile (Ship
            profiles tab), else the bare hull with a typical prop.
        </p>
    </div>
</template>
