import type { TLifetimeStatus, TMassStatus, TRoutePreference } from '@/types/models';
import type { TStaticConnections, TStaticSolarsystem } from '@/types/static-data';

export type ConnectionType = 'stargate' | 'wormhole' | 'evescout';

export type RoutingSettings = {
    routePreference: TRoutePreference;
    securityPenalty: number;
    lifetimeStatus: TLifetimeStatus;
    massStatus: TMassStatus;
    useEveScout: boolean;
    useWormholes: boolean;
    /** Patch 37: off = frigate-only holes are left out. */
    useFrigateHoles?: boolean;
};

export type RoutingConnection = {
    from: number;
    to: number;
    type: ConnectionType;
    massStatus?: TMassStatus;
    lifetimeStatus?: TLifetimeStatus;
    /** Patch 37: a frigate-only hole. */
    frigate?: boolean;
};

export type RouteStep = {
    id: number;
    via: ConnectionType | null;
};

export type RouteResult = {
    route: RouteStep[];
    jumps: number;
    cost: number;
};

export type ClosestSystem = {
    solarsystem_id: number;
    jumps: number;
    cost: number;
    route: RouteStep[];
};

export type RoutingInitPayload = {
    solarsystems: TStaticSolarsystem[];
    connections: TStaticConnections;
};
