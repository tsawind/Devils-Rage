<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import {
    comesWithUs,
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
    squadOrder,
    squadTicked,
    togglePickedSquad,
    type TFleetMember,
    type TFleetScope,
    type TFleetShip,
    type TFleetWing,
} from '@/composables/useFleet';
import { profileFor } from '@/composables/useShipProfiles';
import { mill } from '@/lib/routeSummary';
import { computed } from 'vue';

/**
 * Patch 37: the fleet by wing and squad. "Count" picks which part counts (whole fleet, my
 * wing, my squads, squads you pick); ticks per squad and per ship (caps start unticked);
 * ▲▼ put a squad in your ISBoxer order. Cold = prop off, hot = prop on (from the ship's
 * profile, else the bare hull with a typical prop).
 */
const { wings } = defineProps<{ wings: TFleetWing[] }>();

const byId = computed(() => new Map(fleetShips.value.map((ship) => [ship.character_id, ship])));

/** Wings with their squads and members (EVE's names; squads EVE didn't name get "Squad N"). */
const tree = computed(() => {
    const members = fleetMembers.value;
    const wingIds = [...new Set([...wings.map((wing) => wing.id), ...members.map((member) => member.wing_id)])];
    return wingIds.map((wingId, wingIndex) => {
        const wing = wings.find((candidate) => candidate.id === wingId);
        const squadIds = [...new Set([...(wing?.squads.map((squad) => squad.id) ?? []), ...members.filter((member) => member.wing_id === wingId).map((member) => member.squad_id)])];
        return {
            id: wingId,
            name: wing?.name || `Wing ${wingIndex + 1}`,
            mine: myWingIds.value.includes(wingId),
            squads: squadIds
                .map((squadId, squadIndex) => {
                    const list = members.filter((member) => member.squad_id === squadId);
                    return {
                        id: squadId,
                        name: wing?.squads.find((squad) => squad.id === squadId)?.name || `Squad ${squadIndex + 1}`,
                        mine: mySquadIds.value.includes(squadId),
                        members: squadOrder(squadId, list),
                    };
                })
                .filter((squad) => squad.members.length > 0),
        };
    }).filter((wing) => wing.squads.length > 0);
});

const scopes = computed<{ key: TFleetScope; label: string; count: number }[]>(() => [
    { key: 'fleet', label: 'Whole fleet', count: fleetMembers.value.length },
    { key: 'wing', label: 'My wing', count: fleetMembers.value.filter((member) => myWingIds.value.includes(member.wing_id)).length },
    { key: 'squads', label: 'My squads', count: fleetMembers.value.filter((member) => mySquadIds.value.includes(member.squad_id)).length },
    { key: 'pick', label: 'Pick squads', count: fleetMembers.value.filter((member) => pickedSquads.value.includes(member.squad_id)).length },
]);

function ship(member: TFleetMember): TFleetShip | undefined {
    return byId.value.get(member.character_id);
}

/** What the fit adds: Higgs, plates, prop; "typed in" for a hand-made profile; "hull only" without one. */
function fitChips(member: TFleetMember): string[] {
    const profile = profileFor(member.character_id, member.ship_type_id);
    if (!profile || profile.source === 'hull') return ['hull only'];
    if (profile.source === 'manual') return ['typed in'];
    return [profile.higgs ? 'Higgs' : null, profile.plates ? `${profile.plates} plate${profile.plates === 1 ? '' : 's'}` : null, profile.prop_mass ? 'prop' : null].filter(
        (chip): chip is string => chip !== null,
    );
}
</script>

<template>
    <div class="grid gap-2 text-xs">
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

        <div v-for="wing in tree" :key="wing.id" class="grid gap-1">
            <div class="font-semibold">
                {{ wing.name }}<span v-if="wing.mine" class="ml-1 font-normal text-emerald-400">· mine</span>
            </div>
            <div v-for="squad in wing.squads" :key="squad.id" class="grid gap-0.5 pl-2">
                <div class="flex items-center gap-2 text-muted-foreground">
                    <Checkbox :model-value="squadTicked(squad.id)" :aria-label="`${squad.name} comes with us`" @update:model-value="(value) => setSquadTicked(squad.id, value === true)" />
                    <button
                        type="button"
                        class="text-left"
                        :class="{ 'text-emerald-300': fleetScope === 'pick' && pickedSquads.includes(squad.id), 'cursor-default': fleetScope !== 'pick' }"
                        @click="fleetScope === 'pick' && togglePickedSquad(squad.id)"
                    >
                        {{ squad.name }} · {{ squad.members.length }}<span v-if="squad.mine" class="text-emerald-400"> · mine</span>
                    </button>
                </div>
                <div
                    v-for="(member, index) in squad.members"
                    :key="member.character_id"
                    class="grid grid-cols-[1.75rem_auto_minmax(0,1fr)_minmax(0,1.2fr)_4.5rem_4.5rem] items-center gap-1.5 pl-4"
                    :class="{ 'opacity-50': !ship(member)?.counts }"
                >
                    <span class="flex flex-col leading-none text-muted-foreground">
                        <button type="button" class="text-[9px] hover:text-foreground disabled:opacity-30" :disabled="index === 0" :aria-label="`Move ${member.name} up`" @click="moveInSquad(squad.id, squad.members, member.character_id, -1)">▲</button>
                        <button
                            type="button"
                            class="text-[9px] hover:text-foreground disabled:opacity-30"
                            :disabled="index === squad.members.length - 1"
                            :aria-label="`Move ${member.name} down`"
                            @click="moveInSquad(squad.id, squad.members, member.character_id, 1)"
                        >
                            ▼
                        </button>
                    </span>
                    <Checkbox :model-value="comesWithUs(member)" :aria-label="`${member.name} comes with us`" @update:model-value="(value) => setComesWithUs(member, value === true)" />
                    <span class="truncate"><span class="mr-1 font-mono text-muted-foreground">{{ index + 1 }}</span>{{ member.name }}</span>
                    <span class="truncate text-muted-foreground">
                        {{ member.ship ?? '?' }}
                        <template v-if="member.capital && !comesWithUs(member)"> · staying</template>
                        <span v-for="chip in fitChips(member)" :key="chip" class="ml-1 rounded border border-border px-1 text-[10px]">{{ chip }}</span>
                    </span>
                    <span class="text-right font-mono">{{ mill(ship(member)?.cold ?? member.jump_mass) }}</span>
                    <span class="text-right font-mono text-amber-300">{{ mill(ship(member)?.hot ?? member.jump_mass) }}</span>
                </div>
            </div>
        </div>
        <p class="text-[11px] text-muted-foreground">Columns: cold (prop off) · hot (prop on). Masses from each ship's profile (Ship profiles tab), else the bare hull with a typical prop hot.</p>
    </div>
</template>
