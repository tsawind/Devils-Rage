import FleetController from '@/actions/App/Http/Controllers/FleetController';
import { computed, onBeforeUnmount, ref } from 'vue';

/**
 * Patch 37: the fleet you're in (members, hulls, masses), read every 30 s while the fleet
 * card is open (not before: each read asks EVE). Each ship has a "comes with us" tick: caps start unticked, everyone else
 * ticked; the ticks are remembered per fleet in this browser. "Will it hold?" uses the
 * ticked ships.
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
};

export type TFleetState =
    | { status: 'idle' | 'loading' }
    | { status: 'ok'; fleet_id: number; boss: string; members: TFleetMember[]; updated_at: string }
    | { status: 'no_fleet' | 'boss_missing' | 'error'; message: string };

const REFRESH_MS = 30_000;

const state = ref<TFleetState>({ status: 'idle' });
const overrides = ref<Record<number, boolean>>({});
let users = 0;
let timer: number | null = null;

const tickKey = (fleetId: number) => `fleet-ticks:${fleetId}`;

function loadTicks(fleetId: number): void {
    try {
        overrides.value = JSON.parse(window.localStorage.getItem(tickKey(fleetId)) ?? '{}') as Record<number, boolean>;
    } catch {
        overrides.value = {};
    }
}

function saveTicks(fleetId: number): void {
    try {
        window.localStorage.setItem(tickKey(fleetId), JSON.stringify(overrides.value));
    } catch {
        // Private window: the ticks last until the page closes.
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
        state.value = body;
        if (body.status === 'ok' && body.fleet_id !== previous) loadTicks(body.fleet_id);
    } catch {
        // Offline for a moment: keep the last fleet.
    }
}

/** Whether a member comes with us: your tick, else not for caps and yes for the rest. */
export function comesWithUs(member: TFleetMember): boolean {
    return overrides.value[member.character_id] ?? !member.capital;
}

export function setComesWithUs(member: TFleetMember, value: boolean): void {
    overrides.value = { ...overrides.value, [member.character_id]: value };
    if (state.value.status === 'ok') saveTicks(state.value.fleet_id);
}

/** Patch 37: what "Will it hold?" needs from the fleet: the ticked ships. */
export type TFleetForHold = { ships: number; mass: number; ofTotal: number; hulls: { ship: string; jumpMass: number }[] };

export const fleetForHold = computed<TFleetForHold | null>(() => {
    if (state.value.status !== 'ok') return null;
    const coming = state.value.members.filter(comesWithUs);
    if (coming.length === 0) return null;
    return {
        ships: coming.length,
        mass: coming.reduce((sum, member) => sum + member.jump_mass, 0),
        ofTotal: state.value.members.length,
        hulls: coming.map((member) => ({ ship: member.ship ?? '?', jumpMass: member.jump_mass })),
    };
});

/**
 * Reads the fleet while `start()`ed (the fleet card is open); the last fleet stays for
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
