import MassSessionController from '@/actions/App/Http/Controllers/MassSessionController';
import RollingCrewController from '@/actions/App/Http/Controllers/RollingCrewController';
import { holeWindow, type THoleWindow } from '@/lib/holeWindow';
import { jsonFetch } from '@/lib/jsonFetch';
import type { TMassGoal } from '@/lib/massPlan';
import type { MapStore } from '@/map/store/mapStore';
import type { TMapConnection } from '@/pages/maps';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Patch 37: "⚖ Adding mass…" sessions (one per hole, several at once, everyone on the map
 * sees them), what people saw on those holes, recent jumps (polarization) and everyone's
 * rolling crews. Read every 10 s while the Sessions tab is on screen, and when a pipe's
 * session changes (the map's broadcast).
 */
export type TPlanStepRow = { character_id: number; direction: 'out' | 'home'; mass: number; label: string | null; name: string | null; done: boolean; done_at?: string | null };

export type TMassSession = {
    id: number;
    map_connection_id: number;
    started_by_name: string | null;
    user_id: number;
    goal: TMassGoal;
    crew_ids: number[];
    only_our_jumps: boolean;
    hurry: boolean;
    home_map_solarsystem_id: number | null;
    plan: { steps: TPlanStepRow[] } | null;
    plan_started_at: string | null;
    created_at: string;
};

export type TObservationRow = {
    id: number;
    map_connection_id: number;
    status: 'fresh' | 'reduced' | 'critical';
    logged_mass: number;
    flip_mass: number | null;
    flip_by: string | null;
    by_name: string | null;
    created_at: string;
};

export type TRecentJump = { id: number; map_connection_id: number; character_id: number | null; from_solarsystem_id: number; to_solarsystem_id: number; mass: number; created_at: string };

export type TCrewMember = {
    character_id: number;
    name: string;
    expendable: boolean;
    ship_type_id?: number | null;
    ship?: string | null;
    hull_mass?: number;
    jump_mass?: number;
};

export type TRollingCrew = { id: number; user_id: number; owner_name: string | null; name: string; can_swap: boolean; members: TCrewMember[] };

type TState = {
    sessions: TMassSession[];
    observations: Record<number, TObservationRow[]>;
    logged: Record<number, number>;
    recent_jumps: Record<number, TRecentJump[]>;
    crews: TRollingCrew[];
};

export const massState = ref<TState>({ sessions: [], observations: {}, logged: {}, recent_jumps: {}, crews: [] });
export const massLoaded = ref(false);
/** The Fleet window opens its Sessions tab when this changes (a session was just started). */
export const massOpenRequest = ref(0);

const asRecord = <T>(value: unknown): Record<number, T> => (value && typeof value === 'object' && !Array.isArray(value) ? (value as Record<number, T>) : {});

export async function loadMassSessions(mapSlug: string): Promise<void> {
    const body = await jsonFetch<TState>(MassSessionController.index(mapSlug).url);
    if (!body) return;
    massState.value = {
        sessions: body.sessions ?? [],
        observations: asRecord(body.observations),
        logged: asRecord(body.logged),
        recent_jumps: asRecord(body.recent_jumps),
        crews: body.crews ?? [],
    };
    massLoaded.value = true;
}

/** The side of the pipe nearer home (by jumps on the map), else its first side. */
export function homeSideOf(store: MapStore, connection: TMapConnection): number {
    const homeSolarsystemId = store.meta.value?.home_solarsystem_id ?? null;
    const systems = [...store.systems.values()];
    const home = homeSolarsystemId === null ? null : systems.find((system) => system.solarsystem_id === homeSolarsystemId);
    if (!home) return connection.from_map_solarsystem_id;
    const next = new Map<number, number[]>();
    for (const pipe of store.connections.values()) {
        next.set(pipe.from_map_solarsystem_id, [...(next.get(pipe.from_map_solarsystem_id) ?? []), pipe.to_map_solarsystem_id]);
        next.set(pipe.to_map_solarsystem_id, [...(next.get(pipe.to_map_solarsystem_id) ?? []), pipe.from_map_solarsystem_id]);
    }
    const distance = new Map<number, number>([[home.id, 0]]);
    const queue = [home.id];
    while (queue.length) {
        const at = queue.shift()!;
        for (const to of next.get(at) ?? []) {
            if (!distance.has(to)) {
                distance.set(to, distance.get(at)! + 1);
                queue.push(to);
            }
        }
    }
    const from = distance.get(connection.from_map_solarsystem_id) ?? Infinity;
    const to = distance.get(connection.to_map_solarsystem_id) ?? Infinity;
    return to < from ? connection.to_map_solarsystem_id : connection.from_map_solarsystem_id;
}

/** Right-click a pipe → ⚖ Adding mass…: start a session (or join the one running) and show it. */
export async function startMassSession(store: MapStore, connection: TMapConnection): Promise<void> {
    const mapSlug = store.meta.value?.slug;
    if (!mapSlug) return;
    const body = await jsonFetch<{ session: TMassSession }>(MassSessionController.store(mapSlug).url, 'POST', {
        map_connection_id: connection.id,
        home_map_solarsystem_id: homeSideOf(store, connection),
    });
    if (!body) {
        toast.error("Couldn't start a mass session (viewers can't).");
        return;
    }
    await loadMassSessions(mapSlug);
    massOpenRequest.value++;
}

export function useMassSessions(mapSlug: string) {
    const session = (id: number) => ({ map: mapSlug, massSession: id });

    async function patch(id: number, data: Partial<Omit<TMassSession, 'plan'>> & { plan?: { steps: Omit<TPlanStepRow, 'done'>[] } | null }): Promise<boolean> {
        const body = await jsonFetch<{ session: TMassSession }>(MassSessionController.update(session(id)).url, 'PATCH', data);
        if (!body) {
            toast.error("Couldn't save the session.");
            return false;
        }
        massState.value = { ...massState.value, sessions: massState.value.sessions.map((row) => (row.id === id ? body.session : row)) };
        return true;
    }

    async function end(id: number): Promise<void> {
        await jsonFetch(MassSessionController.destroy(session(id)).url, 'DELETE');
        await loadMassSessions(mapSlug);
    }

    async function observe(id: number, status: TObservationRow['status'], madeByLastJump: boolean): Promise<void> {
        const body = await jsonFetch(MassSessionController.observe(session(id)).url, 'POST', { status, made_by_last_jump: madeByLastJump });
        if (!body) toast.error("Couldn't mark the hole.");
        await loadMassSessions(mapSlug);
    }

    async function saveCrew(crew: { id: number | null; name: string; can_swap: boolean; members: TCrewMember[] }): Promise<boolean> {
        const data = { name: crew.name, can_swap: crew.can_swap, members: crew.members.map((member) => ({ character_id: member.character_id, name: member.name, expendable: member.expendable })) };
        const body =
            crew.id === null
                ? await jsonFetch(RollingCrewController.store(mapSlug).url, 'POST', data)
                : await jsonFetch(RollingCrewController.update({ map: mapSlug, rollingCrew: crew.id }).url, 'PUT', data);
        if (!body) {
            toast.error("Couldn't save the crew.");
            return false;
        }
        await loadMassSessions(mapSlug);
        return true;
    }

    async function removeCrew(id: number): Promise<void> {
        await jsonFetch(RollingCrewController.destroy({ map: mapSlug, rollingCrew: id }).url, 'DELETE');
        await loadMassSessions(mapSlug);
    }

    async function lookup(name: string): Promise<TCrewMember | null> {
        const body = await jsonFetch<{ character: { character_id: number; name: string } | null }>(RollingCrewController.lookup(mapSlug, { query: { name } }).url);
        return body?.character ? { ...body.character, expendable: false } : null;
    }

    return { load: () => loadMassSessions(mapSlug), patch, end, observe, saveCrew, removeCrew, lookup };
}

/**
 * What's left in a session's hole from what people saw (only when every jump through it was
 * logged: "only our jumps"). Null when nobody saw anything yet.
 */
export function sessionWindow(connectionId: number, nominal: number): THoleWindow | null {
    const rows = massState.value.observations[connectionId] ?? [];
    if (!rows.length) return null;
    return holeWindow({
        nominal,
        observations: rows.map((row) => ({ status: row.status, logged: row.logged_mass, flip: row.flip_mass, flipBy: row.flip_by, by: row.by_name, at: row.created_at })),
        loggedNow: massState.value.logged[connectionId] ?? 0,
    });
}
