import ShipProfileController from '@/actions/App/Http/Controllers/ShipProfileController';
import { jsonFetch } from '@/lib/jsonFetch';
import { ref } from 'vue';

/**
 * Patch 37: ship profiles: each pilot's hull with its real cold / hot jump mass (from the fit
 * SeAT knows, or typed in). Shared with the corp.
 */
export type TShipProfile = {
    character_id: number;
    ship_type_id: number;
    ship: string | null;
    cold_mass: number;
    hot_mass: number;
    higgs: boolean;
    plates: number;
    prop_mass: number;
    source: 'seat' | 'manual' | 'hull';
    note: string | null;
    updated_by_name: string | null;
    fitted_at: string | null;
};

const profiles = ref(new Map<string, TShipProfile>());
const key = (characterId: number, shipTypeId: number) => `${characterId}:${shipTypeId}`;

function store(list: TShipProfile[] | undefined): void {
    if (!list?.length) return;
    const next = new Map(profiles.value);
    for (const profile of list) next.set(key(profile.character_id, profile.ship_type_id), profile);
    profiles.value = next;
}

export function profileFor(characterId: number, shipTypeId: number): TShipProfile | null {
    return profiles.value.get(key(characterId, shipTypeId)) ?? null;
}

/** A typical prop mod for the hull's size when its fit isn't known. */
export function guessedProp(hullMass: number): number {
    if (hullMass >= 500_000_000) return 0;
    if (hullMass >= 50_000_000) return 50_000_000;
    if (hullMass >= 5_000_000) return 5_000_000;
    return 500_000;
}

/** Cold / hot jump mass of a pilot's hull: its profile, else the bare hull (+ a typical prop hot). */
export function shipMasses(characterId: number, shipTypeId: number, hullJumpMass: number, hullMass: number): { cold: number; hot: number; profile: TShipProfile | null } {
    const profile = profileFor(characterId, shipTypeId);
    if (profile) return { cold: profile.cold_mass, hot: profile.hot_mass, profile };
    return { cold: hullJumpMass, hot: hullJumpMass + guessedProp(hullMass), profile: null };
}

let lastAsked = '';

export function useShipProfiles(mapSlug: string) {
    /** Read the fits SeAT knows for these pilots (the server asks SeAT at most hourly per pilot). */
    async function refresh(members: { character_id: number; ship_type_id: number }[], force = false): Promise<void> {
        const asked = members.map((member) => key(member.character_id, member.ship_type_id)).sort().join(',');
        if (!members.length || (!force && asked === lastAsked)) return;
        lastAsked = asked;
        const body = await jsonFetch<{ profiles: TShipProfile[] }>(ShipProfileController.refresh(mapSlug).url, 'POST', { members: members.slice(0, 100) });
        store(body?.profiles);
    }

    async function save(profile: { character_id: number; ship_type_id: number; cold_mass: number; hot_mass: number; note: string | null }): Promise<boolean> {
        const body = await jsonFetch<{ profiles: TShipProfile[] }>(ShipProfileController.update(mapSlug).url, 'PUT', profile);
        store(body?.profiles);
        return body !== null;
    }

    async function remove(characterId: number, shipTypeId: number): Promise<void> {
        await jsonFetch(ShipProfileController.destroy(mapSlug).url, 'DELETE', { character_id: characterId, ship_type_id: shipTypeId });
        const next = new Map(profiles.value);
        next.delete(key(characterId, shipTypeId));
        profiles.value = next;
        lastAsked = '';
    }

    return { profiles, refresh, save, remove };
}
