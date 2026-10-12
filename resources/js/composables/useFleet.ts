import FleetController from '@/actions/App/Http/Controllers/FleetController';
import { jumpingHotNow } from '@/composables/useJumpingHot';
import { shipMasses } from '@/composables/useShipProfiles';
import { computed, onBeforeUnmount, ref } from 'vue';

/**
 * Patch 37: the fleet you're in, for the Fleet window: members, hulls, wings and squads, read
 * every 30 s while the window is open (each read asks EVE). Per fleet, in this browser:
 * - "comes with us" ticks per ship (caps start unticked) and per squad;
 * - which part counts: the whole fleet, my wing, my squads, or squads you pick;
 * - each squad's order (your ISBoxer order).
 * "Will it hold?" and the rolling plans use the ships that count.
 */
export type TFleetMember = {
    character_id: number;
    name: string;
    ship_type_id: number;
    ship: string | null;
    group: string | null;
    mass: number;
    jump_mass: number;
    capital: boolean;
    solarsystem_id: number;
    solarsystem: string | null;
    wing_id: number;
    squad_id: number;
    role: string;
    owner: string;
};

export type TFleetWing = { id: number; name: string; squads: { id: number; name: string }[] };

export type TFleetState =
    | { status: 'idle' | 'loading' }
    | { status: 'ok'; fleet_id: number; boss: string; members: TFleetMember[]; wings: TFleetWing[]; updated_at: string }
    | { status: 'no_fleet' | 'boss_missing' | 'error'; message: string };

export type TFleetScope = 'fleet' | 'wing' | 'squads' | 'pick';

type TFleetPrefs = { ticks: Record<number, boolean>; squads: Record<number, boolean>; scope: TFleetScope; picked: number[]; order: Record<number, number[]> };

const REFRESH_MS = 30_000;

const state = ref<TFleetState>({ status: 'idle' });
const prefs = ref<TFleetPrefs>({ ticks: {}, squads: {}, scope: 'fleet', picked: [], order: {} });
/** Your own character ids (to find "my wing" and "my squads"). */
const mine = ref<number[]>([]);
let users = 0;
let timer: number | null = null;

const prefsKey = (fleetId: number) => `fleet-prefs:${fleetId}`;

function loadPrefs(fleetId: number): void {
    try {
        const saved = JSON.parse(window.localStorage.getItem(prefsKey(fleetId)) ?? 'null') as Partial<TFleetPrefs> | null;
        prefs.value = { ticks: {}, squads: {}, scope: 'fleet', picked: [], order: {}, ...(saved ?? {}) };
    } catch {
        prefs.value = { ticks: {}, squads: {}, scope: 'fleet', picked: [], order: {} };
    }
}

function savePrefs(): void {
    if (state.value.status !== 'ok') return;
    try {
        window.localStorage.setItem(prefsKey(state.value.fleet_id), JSON.stringify(prefs.value));
    } catch {
        // Private window: the choices last until the page closes.
    }
}

async function refresh(mapSlug: string): Promise<void> {
    if (state.value.status === 'idle') state.value = { status: 'loading' };
    try {
        const response = await fetch(FleetController.show(mapSlug).url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!response.ok) {
            state.value = { status: 'error', message: response.status === 429 ? 'Asked EVE too often, wait a moment.' : 'The mapper could not read the fleet.' };
            return;
        }
        const body = (await response.json()) as TFleetState;
        const previous = state.value.status === 'ok' ? state.value.fleet_id : null;
        state.value = body.status === 'ok' ? { ...body, wings: body.wings ?? [] } : body;
        if (body.status === 'ok' && body.fleet_id !== previous) loadPrefs(body.fleet_id);
    } catch {
        // Offline for a moment: keep the last fleet.
    }
}

export const fleetMembers = computed(() => (state.value.status === 'ok' ? state.value.members : []));

/** Whether a member comes with us: your tick, else not for caps and yes for the rest; an unticked squad leaves them all out. */
export function comesWithUs(member: TFleetMember): boolean {
    if (prefs.value.squads[member.squad_id] === false) return false;
    return prefs.value.ticks[member.character_id] ?? !member.capital;
}

export function setComesWithUs(member: TFleetMember, value: boolean): void {
    prefs.value = { ...prefs.value, ticks: { ...prefs.value.ticks, [member.character_id]: value } };
    savePrefs();
}

export function squadTicked(squadId: number): boolean {
    return prefs.value.squads[squadId] !== false;
}

export function setSquadTicked(squadId: number, value: boolean): void {
    prefs.value = { ...prefs.value, squads: { ...prefs.value.squads, [squadId]: value } };
    savePrefs();
}

export const fleetScope = computed(() => prefs.value.scope);
export const pickedSquads = computed(() => prefs.value.picked);

export function setFleetScope(scope: TFleetScope): void {
    prefs.value = { ...prefs.value, scope };
    savePrefs();
}

export function togglePickedSquad(squadId: number): void {
    const picked = prefs.value.picked.includes(squadId) ? prefs.value.picked.filter((id) => id !== squadId) : [...prefs.value.picked, squadId];
    prefs.value = { ...prefs.value, scope: 'pick', picked };
    savePrefs();
}

export function setMine(characterIds: number[]): void {
    mine.value = characterIds;
}

const myMembers = computed(() => fleetMembers.value.filter((member) => mine.value.includes(member.character_id)));
export const myWingIds = computed(() => [...new Set(myMembers.value.map((member) => member.wing_id))]);
export const mySquadIds = computed(() => [...new Set(myMembers.value.map((member) => member.squad_id))]);

/** Whether a member is in the part of the fleet that counts (whole fleet, my wing, my squads, picked squads). */
export function inScope(member: TFleetMember): boolean {
    switch (prefs.value.scope) {
        case 'wing':
            return myWingIds.value.includes(member.wing_id);
        case 'squads':
            return mySquadIds.value.includes(member.squad_id);
        case 'pick':
            return prefs.value.picked.includes(member.squad_id);
        default:
            return true;
    }
}

/** A squad's members in your order (ISBoxer order); new members at the end. */
export function squadOrder(squadId: number, members: TFleetMember[]): TFleetMember[] {
    const order = prefs.value.order[squadId] ?? [];
    const rank = (member: TFleetMember) => {
        const index = order.indexOf(member.character_id);
        return index === -1 ? 1_000 + members.indexOf(member) : index;
    };
    return [...members].sort((a, b) => rank(a) - rank(b));
}

export function moveInSquad(squadId: number, members: TFleetMember[], characterId: number, by: -1 | 1): void {
    const ids = squadOrder(squadId, members).map((member) => member.character_id);
    const at = ids.indexOf(characterId);
    const to = at + by;
    if (at === -1 || to < 0 || to >= ids.length) return;
    [ids[at], ids[to]] = [ids[to], ids[at]];
    prefs.value = { ...prefs.value, order: { ...prefs.value.order, [squadId]: ids } };
    savePrefs();
}

/** One ship of the fleet with its masses and whether it counts. */
export type TFleetShip = TFleetMember & { cold: number; hot: number; fromProfile: boolean; counts: boolean; position: number };

export const fleetShips = computed<TFleetShip[]>(() => {
    const members = fleetMembers.value;
    const bySquad = new Map<number, TFleetMember[]>();
    for (const member of members) bySquad.set(member.squad_id, [...(bySquad.get(member.squad_id) ?? []), member]);
    const positions = new Map<number, number>();
    for (const [squadId, list] of bySquad) squadOrder(squadId, list).forEach((member, index) => positions.set(member.character_id, index + 1));
    return members.map((member) => {
        const masses = shipMasses(member.character_id, member.ship_type_id, member.jump_mass, member.mass);
        return {
            ...member,
            cold: masses.cold,
            hot: masses.hot,
            fromProfile: masses.profile !== null,
            counts: inScope(member) && comesWithUs(member),
            position: positions.get(member.character_id) ?? 99,
        };
    });
});

export const countedShips = computed(() => fleetShips.value.filter((ship) => ship.counts));

/** Patch 37: what "Will it hold?" needs from the fleet: the ships that count, hot or cold by the switch. */
export type TFleetForHold = { ships: number; mass: number; ofTotal: number; hulls: { ship: string; jumpMass: number }[] };

export const fleetForHold = computed<TFleetForHold | null>(() => {
    const ships = countedShips.value;
    if (ships.length === 0) return null;
    const massOf = (ship: TFleetShip) => (jumpingHotNow.value ? ship.hot : ship.cold);
    return {
        ships: ships.length,
        mass: ships.reduce((sum, ship) => sum + massOf(ship), 0),
        ofTotal: fleetMembers.value.length,
        hulls: ships.map((ship) => ({ ship: ship.ship ?? '?', jumpMass: massOf(ship) })),
    };
});

/**
 * Reads the fleet while `start()`ed (the Fleet window is open); the last fleet stays for
 * "Will it hold?" after it closes.
 */
export function useFleet(mapSlug: string | null) {
    let running = false;
    function start(): void {
        if (mapSlug === null || running) return;
        running = true;
        users++;
        if (users === 1) {
            void refresh(mapSlug);
            timer = window.setInterval(() => void refresh(mapSlug), REFRESH_MS);
        }
    }
    function stop(): void {
        if (!running) return;
        running = false;
        users--;
        if (users === 0 && timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
    }
    onBeforeUnmount(stop);
    return { state, start, stop, refresh: () => (mapSlug ? refresh(mapSlug) : Promise.resolve()) };
}
