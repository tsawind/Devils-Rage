<script setup lang="ts">
import { fleetShips, type TFleetShip } from '@/composables/useFleet';
import usePermission from '@/composables/usePermission';
import { profileFor, useShipProfiles } from '@/composables/useShipProfiles';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Patch 37: ship profiles for the fleet's pilots: cold (prop off) and hot (prop on) mass,
 * read from the fit SeAT knows (a Higgs doubles hull, plates and prop) or typed in. A typed
 * profile stays until it's removed (then SeAT's fit is used again). Shared with the corp.
 */
const { mapSlug } = defineProps<{ mapSlug: string }>();
const { save, remove, refresh } = useShipProfiles(mapSlug);
const { canEdit } = usePermission();

const editing = ref<number | null>(null);
const draft = ref<{ cold: number; semi: number | null; hot: number; note: string; platesOff: boolean }>({ cold: 0, semi: null, hot: 0, note: '', platesOff: false });

/** The middle prop's mass (SEMI: a capital's smaller prop), when the profile has one. */
function semiOf(ship: TFleetShip): number | null {
    const hot = ship.options.filter((option) => option.mode === 'hot' && option.mass < ship.hot && option.mass > ship.cold && !option.label.includes('entangler'));
    return hot.length ? Math.max(...hot.map((option) => option.mass)) : null;
}

function edit(ship: TFleetShip): void {
    const profile = profileFor(ship.character_id, ship.ship_type_id);
    const semi = semiOf(ship);
    editing.value = ship.character_id;
    draft.value = {
        cold: Math.round(ship.cold / 1e5) / 10,
        semi: semi === null ? null : Math.round(semi / 1e5) / 10,
        hot: Math.round(ship.hot / 1e5) / 10,
        note: profile?.note ?? '',
        platesOff: false,
    };
}

/** Patch 37: plates off: their mass (twice with a Higgs) comes off every mass. */
function platesOff(ship: TFleetShip): void {
    const profile = profileFor(ship.character_id, ship.ship_type_id);
    const plates = ((profile?.plate_mass ?? 0) * (profile?.higgs ? 2 : 1)) / 1e6;
    if (!plates) {
        toast.message('No plates known for this fit: type the masses without them.');
        return;
    }
    const off = (value: number) => Math.max(0, Math.round((value - plates) * 10) / 10);
    draft.value = { ...draft.value, cold: off(draft.value.cold), hot: off(draft.value.hot), semi: draft.value.semi === null ? null : off(draft.value.semi), platesOff: true };
}

async function store(ship: TFleetShip): Promise<void> {
    const cold = Math.max(0, Math.round(draft.value.cold * 1e6));
    const hot = Math.max(cold, Math.round(draft.value.hot * 1e6));
    const semi = draft.value.semi ? Math.min(hot, Math.max(cold, Math.round(draft.value.semi * 1e6))) : null;
    const ok = await save({
        character_id: ship.character_id,
        ship_type_id: ship.ship_type_id,
        cold_mass: cold,
        hot_mass: hot,
        semi_mass: semi,
        plates_off: draft.value.platesOff,
        note: draft.value.note || null,
    });
    if (ok) editing.value = null;
    else toast.error('Could not save the profile');
}

async function backToSeat(ship: TFleetShip): Promise<void> {
    await remove(ship.character_id, ship.ship_type_id);
    await refresh([{ character_id: ship.character_id, ship_type_id: ship.ship_type_id }], true);
}

function source(ship: TFleetShip): string {
    const profile = profileFor(ship.character_id, ship.ship_type_id);
    if (!profile || profile.source === 'hull') return 'bare hull';
    if (profile.source === 'manual') return `typed in${profile.updated_by_name ? ` by ${profile.updated_by_name}` : ''}`;
    const parts = [profile.higgs ? 'Higgs' : null, profile.plates ? `${profile.plates} plate${profile.plates === 1 ? '' : 's'}` : null, profile.prop_mass ? `prop +${Math.round(profile.prop_mass / 1e6)}m` : 'no prop'];
    return `SeAT fit: ${parts.filter(Boolean).join(', ')}`;
}

const m = (kg: number) => `${Math.round(kg / 1_000_000).toLocaleString('en-US')}m`;
</script>

<template>
    <div class="grid gap-1 text-xs">
        <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_4.5rem_4.5rem_4.5rem_minmax(0,1.6fr)_auto] gap-x-2 text-[11px] text-muted-foreground">
            <span>Pilot</span><span>Ship</span><span class="text-right">Cold</span><span class="text-right">Semi</span><span class="text-right">Hot</span><span>From</span><span></span>
        </div>
        <template v-for="ship in fleetShips" :key="ship.character_id">
            <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_4.5rem_4.5rem_4.5rem_minmax(0,1.6fr)_auto] items-center gap-x-2">
                <span class="truncate">{{ ship.name }}</span>
                <span class="truncate text-muted-foreground">{{ ship.ship ?? '?' }}</span>
                <template v-if="editing === ship.character_id">
                    <input v-model.number="draft.cold" type="number" min="0" class="w-full rounded border border-border bg-background px-1 text-right font-mono" aria-label="Cold mass in millions" />
                    <input
                        v-model.number="draft.semi"
                        type="number"
                        min="0"
                        placeholder="—"
                        class="w-full rounded border border-border bg-background px-1 text-right font-mono"
                        aria-label="Semi-hot mass in millions (a smaller prop)"
                        title="SEMI: a smaller prop (a carrier's 500MN); leave empty when there's none"
                    />
                    <input v-model.number="draft.hot" type="number" min="0" class="w-full rounded border border-border bg-background px-1 text-right font-mono" aria-label="Hot mass in millions" />
                    <input v-model="draft.note" maxlength="120" placeholder="rolling fit, 2 plates + Higgs" class="w-full rounded border border-border bg-background px-1" aria-label="Note" />
                    <span class="flex gap-1">
                        <button
                            v-if="ship.capital"
                            type="button"
                            class="rounded border px-1.5"
                            :class="draft.platesOff ? 'border-amber-500 text-amber-300' : 'border-border'"
                            title="Plates taken off: their mass comes off cold, semi and hot"
                            :disabled="draft.platesOff"
                            @click="platesOff(ship)"
                        >
                            Plates off
                        </button>
                        <button type="button" class="rounded border border-emerald-600 px-1.5 text-emerald-300" @click="store(ship)">Save</button>
                        <button type="button" class="rounded border border-border px-1.5" @click="editing = null">✕</button>
                    </span>
                </template>
                <template v-else>
                    <span class="text-right font-mono">{{ m(ship.cold) }}</span>
                    <span class="text-right font-mono text-orange-300">{{ semiOf(ship) === null ? '—' : m(semiOf(ship)!) }}</span>
                    <span class="text-right font-mono text-amber-300">{{ m(ship.hot) }}</span>
                    <span class="truncate text-muted-foreground" :title="profileFor(ship.character_id, ship.ship_type_id)?.note ?? ''">{{ source(ship) }}</span>
                    <span v-if="canEdit" class="flex gap-1">
                        <button type="button" class="rounded border border-border px-1.5" @click="edit(ship)">Edit</button>
                        <button
                            v-if="profileFor(ship.character_id, ship.ship_type_id)?.source === 'manual'"
                            type="button"
                            class="rounded border border-border px-1.5"
                            title="Remove the typed masses and use SeAT's fit again"
                            @click="backToSeat(ship)"
                        >
                            SeAT
                        </button>
                    </span>
                </template>
            </div>
        </template>
        <p v-if="!fleetShips.length" class="text-muted-foreground">No fleet read yet (Ships tab).</p>
        <p class="text-[11px] text-muted-foreground">
            Masses in millions of kg. SeAT refreshes assets about hourly, so a refit shows up late: type it in meanwhile (capitals: hot, semi and cold, and Plates off). A Higgs doubles hull, plates and prop. Jumps you log use these
            masses (hot or cold by your JUMPING HOT switch).
        </p>
    </div>
</template>
