import { isWormholeClass } from '@/const/solarsystemClasses';
import { displayAlias, orderStatics } from '@/lib/alias';
import type { TStringedSolarsystemClass } from '@/types/models';

/**
 * Patch 35: RAGE ROLL. The pure parts: what the ping says (the static, the nearest
 * way into k-space), which pipe is the static being rolled, and when a target
 * system has been hit.
 */

export type TRageRollTarget = { id: number; name: string };

export type TRageRoll = {
    solarsystem_id: number;
    started_at: string | null;
    started_by: string | null;
    /** Also a Rage Scanning session: Rage speed for everyone until the roll ends. */
    scanning: boolean;
    targets: TRageRollTarget[];
};

type TRollSystem = {
    id: number;
    solarsystem_id: number;
    alias: string | null;
    solarsystem: {
        name: string;
        class?: TStringedSolarsystemClass | null;
        region?: { name?: string | null } | null;
        statics?: { name: string; leads_to: string }[] | null;
    };
};

type TRollSignature = { map_solarsystem_id: number; signature_id: string; is_static?: boolean; wormhole?: { name: string } | null };

type TRollConnection = {
    id: number;
    from_map_solarsystem_id: number;
    to_map_solarsystem_id: number;
    created_at?: string;
    signatures?: TRollSignature[] | null;
};

const SECURITY_LABELS: Record<string, string> = { h: 'highsec', l: 'lowsec', n: 'nullsec', p: 'Pochven' };

/** Whether a system is known space you can leave the chain into. */
export function isKspace(solarsystemClass: TStringedSolarsystemClass | null | undefined): boolean {
    return solarsystemClass != null && String(solarsystemClass) in SECURITY_LABELS && !isWormholeClass(solarsystemClass);
}

function otherEnd(connection: TRollConnection, id: number): number | null {
    if (connection.from_map_solarsystem_id === id) return connection.to_map_solarsystem_id;
    if (connection.to_map_solarsystem_id === id) return connection.from_map_solarsystem_id;
    return null;
}

/**
 * The shortest way out of `startId` (a map system id) into k-space along the map's
 * pipes: the systems after the start, ending in the k-space system. Null when the
 * start is k-space itself or nothing on the map leads out.
 */
export function nearestKspacePath<S extends TRollSystem>(startId: number, systems: ReadonlyMap<number, S>, connections: Iterable<TRollConnection>): S[] | null {
    const start = systems.get(startId);
    if (!start || isKspace(start.solarsystem.class)) return null;

    const neighbours = new Map<number, number[]>();
    for (const connection of connections) {
        const { from_map_solarsystem_id: from, to_map_solarsystem_id: to } = connection;
        neighbours.set(from, [...(neighbours.get(from) ?? []), to]);
        neighbours.set(to, [...(neighbours.get(to) ?? []), from]);
    }

    const cameFrom = new Map<number, number>([[startId, startId]]);
    const queue = [startId];
    while (queue.length > 0) {
        const current = queue.shift() as number;
        for (const next of (neighbours.get(current) ?? []).toSorted((a, b) => a - b)) {
            if (cameFrom.has(next) || !systems.has(next)) continue;
            cameFrom.set(next, current);
            if (isKspace(systems.get(next)?.solarsystem.class)) {
                const path: S[] = [];
                for (let id = next; id !== startId; id = cameFrom.get(id) as number) {
                    path.unshift(systems.get(id) as S);
                }
                return path;
            }
            queue.push(next);
        }
    }

    return null;
}

/** "Bravo → Tama (lowsec, The Citadel) · 2 jumps", or null when there is no way out. */
export function describeKspacePath(path: readonly TRollSystem[] | null): string | null {
    if (!path || path.length === 0) return null;

    const exit = path[path.length - 1];
    const security = SECURITY_LABELS[String(exit.solarsystem.class)] ?? 'k-space';
    const region = exit.solarsystem.region?.name;
    const via = path.slice(0, -1).map((system) => displayAlias(system.alias) || system.solarsystem.name);
    const exitText = `${exit.solarsystem.name} (${security}${region ? `, ${region}` : ''})`;

    return `${[...via, exitText].join(' → ')} · ${path.length} jump${path.length === 1 ? '' : 's'}`;
}

/**
 * The pipes off the rolling system whose signature on its side is marked static:
 * the holes being rolled.
 */
export function staticPipes<C extends TRollConnection>(rollingId: number, connections: Iterable<C>): C[] {
    const pipes: C[] = [];
    for (const connection of connections) {
        if (otherEnd(connection, rollingId) === null) continue;
        if ((connection.signatures ?? []).some((signature) => signature.map_solarsystem_id === rollingId && signature.is_static)) {
            pipes.push(connection);
        }
    }
    return pipes;
}

/**
 * "V753 → C6 (Alpha, ZGB)" for each static of the rolling system, with the hole
 * currently mapped for it when there is one.
 */
export function describeStatics(rolling: TRollSystem, systems: ReadonlyMap<number, TRollSystem>, connections: Iterable<TRollConnection>): string | null {
    const statics = orderStatics(rolling.solarsystem.statics ?? []);
    const holes = staticPipes(rolling.id, connections).map((pipe) => {
        const far = systems.get(otherEnd(pipe, rolling.id) as number);
        const signature = (pipe.signatures ?? []).find((candidate) => candidate.map_solarsystem_id === rolling.id && candidate.is_static);
        return {
            type: signature?.wormhole?.name?.toUpperCase() ?? null,
            text: [far ? displayAlias(far.alias) || far.solarsystem.name : null, signature?.signature_id?.slice(0, 3)].filter(Boolean).join(', '),
        };
    });

    if (statics.length === 0) {
        return holes.length > 0 ? `Static hole: ${holes.map((hole) => hole.text).join(' + ')}` : null;
    }

    const unused = [...holes];
    const take = (name: string) => {
        const index = unused.findIndex((hole) => hole.type === name.toUpperCase());
        const at = index !== -1 ? index : unused.findIndex((hole) => hole.type === null);
        return at === -1 ? null : unused.splice(at, 1)[0];
    };

    return statics
        .map((value) => {
            const hole = take(value.name);
            return `${value.name} → ${value.leads_to.toUpperCase()}${hole?.text ? ` (${hole.text})` : ''}`;
        })
        .join(' + ');
}

/** A target system hit: the pipe off the rolling system that leads into it. */
export type TTargetHit = { connectionId: number; mapSolarsystemId: number; solarsystemId: number };

/**
 * Pipes off the rolling system that lead straight into a target system and were
 * made after the roll started (a target already connected before doesn't count).
 */
export function targetHits(
    rollingId: number,
    targetIds: readonly number[],
    systems: ReadonlyMap<number, TRollSystem>,
    connections: Iterable<TRollConnection>,
    startedAt: string | null,
): TTargetHit[] {
    if (targetIds.length === 0) return [];
    const since = startedAt ? Date.parse(startedAt) : Number.NEGATIVE_INFINITY;
    const hits: TTargetHit[] = [];
    for (const connection of connections) {
        const farId = otherEnd(connection, rollingId);
        if (farId === null) continue;
        const far = systems.get(farId);
        if (!far || !targetIds.includes(far.solarsystem_id)) continue;
        if (connection.created_at && Date.parse(connection.created_at) < since - 60_000) continue;
        hits.push({ connectionId: connection.id, mapSolarsystemId: far.id, solarsystemId: far.solarsystem_id });
    }
    return hits;
}

/** "J123456, thera ,J234567" → ["J123456", "thera", "J234567"] (J-codes upper-cased). */
export function parseTargets(text: string): string[] {
    const seen = new Set<string>();
    return text
        .split(/[\s,;]+/)
        .map((part) => part.trim())
        .filter(Boolean)
        .map((part) => (/^j\d{6}$/i.test(part) ? part.toUpperCase() : part))
        .filter((part) => {
            const key = part.toUpperCase();
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        });
}

/** "4:07" / "1:02:07" since the roll started. */
export function formatElapsed(startedAt: string | null, now: number): string {
    if (!startedAt) return '0:00';
    const total = Math.max(0, Math.floor((now - Date.parse(startedAt)) / 1000));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = String(total % 60).padStart(2, '0');
    return hours > 0 ? `${hours}:${String(minutes).padStart(2, '0')}:${seconds}` : `${minutes}:${seconds}`;
}

type TPastedSignature = {
    id: number;
    signature_id: string | null;
    signature_category_id: number | null;
    signature_category?: { code?: string; name?: string } | null;
    map_connection_id: number | null;
    is_static?: boolean;
    alias?: string | null;
};

function isWormhole(signature: TPastedSignature): boolean {
    const code = signature.signature_category?.code ?? signature.signature_category?.name ?? '';
    return /wormhole/i.test(code);
}

/**
 * "New Alpha?" after a paste in the rolling system: the statics already marked
 * (the ones being rolled), and the wormhole signatures the paste just added that
 * could be the new one. Nothing to ask unless there is at least one of each.
 */
export function newStaticQuestion<S extends TPastedSignature>(beforeIds: ReadonlySet<number>, signatures: readonly S[]): { olds: S[]; candidates: S[] } | null {
    const olds = signatures.filter((signature) => signature.is_static && beforeIds.has(signature.id));
    const candidates = signatures.filter(
        (signature) => !beforeIds.has(signature.id) && !signature.is_static && signature.map_connection_id === null && (isWormhole(signature) || signature.signature_category_id === null),
    );
    return olds.length > 0 && candidates.length > 0 ? { olds, candidates } : null;
}
