import { suggestAlias } from '@/lib/alias';
import { buildSignatureBookmark } from '@/lib/bookmark';
import { chainAliases } from '@/lib/combat';
import type { MapStore } from '@/map/store/mapStore';
import type { TMapSolarsystem, TPendingHole } from '@/pages/maps';

/**
 * An unjumped hole as the map knows it (patch 12/13): its system, and the
 * number it has or would take (its own, the planned one, or in a combat
 * chain the next free one).
 */
export function pendingHole(store: MapStore, systemId: number, signatureId: number): { system: TMapSolarsystem; hole: TPendingHole } | null {
    const system = store.systems.get(systemId);
    const hole = system?.pending_holes?.find((candidate) => candidate.id === signatureId);
    return system && hole ? { system, hole } : null;
}

export function claimFor(store: MapStore, system: TMapSolarsystem, hole: TPendingHole, plannedAlias: string | null): string | null {
    if (hole.alias) return hole.alias;
    if (plannedAlias) return plannedAlias;
    const meta = store.meta.value;
    const locked = (system.pending_holes ?? []).map((candidate) => candidate.alias).filter((alias): alias is string => Boolean(alias));
    return suggestAlias({
        parentAlias: system.alias,
        targetIsWormhole: true,
        originIsWormhole: true,
        aliases: [...chainAliases([...store.systems.values()], system), ...locked],
        scheme: meta?.bookmark_alias_scheme,
        ignoredAlias: meta?.bookmark_ignored_alias,
        combatHome: Boolean(system.combat_home),
    });
}

/** The bookmark name of an unjumped hole, as it would be numbered `alias`. */
export function pendingHoleBookmark(store: MapStore, system: TMapSolarsystem, hole: TPendingHole, alias: string | null): string {
    const meta = store.meta.value;
    if (!meta) return '';
    return buildSignatureBookmark({
        signature: holeAsSignature(hole),
        currentSystem: {
            alias: system.alias,
            class: system.solarsystem.class,
            combatHome: Boolean(system.combat_home),
            combatColor: system.combat_color ?? null,
        },
        aliases: chainAliases([...store.systems.values()], system),
        formats: meta,
        plannedAlias: alias,
    });
}

/** A pending hole in the shape the bookmark builder (and armHole) reads. */
export function holeAsSignature(hole: TPendingHole) {
    return {
        id: hole.id,
        signature_id: hole.signature_id,
        ship_size: null,
        mass_status: null,
        lifetime: 'healthy' as const,
        wormhole: { name: hole.wormhole },
        signature_type: { target_class: hole.target_class },
        is_static: hole.is_static,
        is_wandering: hole.is_wandering,
    };
}
