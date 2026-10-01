import { aliasesBelow, suggestAlias } from '@/lib/alias';
import { buildSignatureBookmark, formatBookmarkName } from '@/lib/bookmark';
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

/** The bookmark name of an unjumped hole, as it would be numbered `alias` (and flagged static or not). */
export function pendingHoleBookmark(store: MapStore, system: TMapSolarsystem, hole: TPendingHole, alias: string | null, isStatic?: boolean): string {
    const meta = store.meta.value;
    if (!meta) return '';
    return buildSignatureBookmark({
        signature: { ...holeAsSignature(hole), ...(isStatic !== undefined ? { is_static: isStatic, is_wandering: false } : {}) },
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
        // The same details the signature list builds its bookmark from (size, mass, EOL markers).
        ship_size: hole.ship_size ?? null,
        mass_status: hole.mass_status ?? null,
        lifetime: hole.lifetime ?? 'healthy',
        wormhole: { name: hole.wormhole },
        signature_type: { target_class: hole.target_class },
        is_static: hole.is_static,
        is_wandering: hole.is_wandering,
    };
}

/**
 * Patch 14: the in-game bookmarks that change when the hole `signatureId` in
 * `system` is renamed from → to (an unjumped hole, or a jumped one: then also
 * the far side's way back). Shown in the rename popups.
 */
export function renameChanges(
    store: MapStore,
    system: TMapSolarsystem,
    signatureId: number,
    fromAlias: string,
    toAlias: string,
    isStatic: boolean,
): { label: string; from: string; to: string }[] {
    const meta = store.meta.value;
    if (!meta) return [];
    const hole = system.pending_holes?.find((candidate) => candidate.id === signatureId);
    if (hole) {
        return [{ label: 'In this system', from: pendingHoleBookmark(store, system, hole, fromAlias), to: pendingHoleBookmark(store, system, hole, toAlias, isStatic) }];
    }

    for (const connection of store.connections.values()) {
        const signature = (connection.signatures ?? []).find((candidate) => candidate.id === signatureId && candidate.map_solarsystem_id === system.id);
        if (!signature) continue;
        const otherId = connection.from_map_solarsystem_id === system.id ? connection.to_map_solarsystem_id : connection.from_map_solarsystem_id;
        const target = store.systems.get(otherId);
        if (!target) return [];
        const farSignature = (connection.signatures ?? []).find((candidate) => candidate.map_solarsystem_id !== system.id) ?? null;
        const forward = (alias: string, flag: boolean): string =>
            buildSignatureBookmark({
                signature: {
                    signature_id: signature.signature_id,
                    ship_size: connection.ship_size,
                    mass_status: connection.mass_status,
                    lifetime: connection.lifetime_status,
                    wormhole: signature.wormhole,
                    signature_type: { target_class: signature.target_class ?? null },
                    is_static: flag,
                    is_wandering: false,
                },
                currentSystem: {
                    alias: system.alias,
                    class: system.solarsystem.class,
                    combatHome: Boolean(system.combat_home),
                    combatColor: system.combat_color ?? null,
                },
                connectionTarget: { ...target, alias },
                aliases: chainAliases([...store.systems.values()], system),
                formats: meta,
                detectReturn: true,
            });
        const back = (alias: string): string =>
            formatBookmarkName(
                { alias: system.alias, occupier_alias: system.occupier_alias, solarsystem: system.solarsystem, combat_home: system.combat_home, combat_color: system.combat_color },
                { signatureId: farSignature?.signature_id ?? null },
                meta,
                alias,
                alias,
                target.solarsystem.class,
                target.combat_color ?? null,
                Boolean(target.combat_home),
            );
        return [
            { label: 'In this system', from: forward(fromAlias, Boolean(signature.is_static)), to: forward(toAlias, isStatic) },
            { label: 'On the far side (way back)', from: back(fromAlias), to: back(toAlias) },
        ];
    }
    return [];
}

/** Systems already mapped further down a name in the chain ("A21" under "A2"): renaming it is blocked then. */
export function mappedBelow(store: MapStore, system: TMapSolarsystem, alias: string): string[] {
    return aliasesBelow(chainAliases([...store.systems.values()], system), alias, store.meta.value?.bookmark_ignored_alias);
}

/** Patch 14: the forward bookmark of a jumped hole from `system`, if the system it leads to were named `alias`. */
export function linkedForwardBookmark(store: MapStore, system: TMapSolarsystem, targetId: number, alias: string): string {
    const meta = store.meta.value;
    const target = store.systems.get(targetId);
    if (!meta || !target) return '';
    const connection = [...store.connections.values()].find(
        (candidate) =>
            (candidate.from_map_solarsystem_id === system.id && candidate.to_map_solarsystem_id === targetId) ||
            (candidate.to_map_solarsystem_id === system.id && candidate.from_map_solarsystem_id === targetId),
    );
    const signature = (connection?.signatures ?? []).find((candidate) => candidate.map_solarsystem_id === system.id) ?? null;
    return buildSignatureBookmark({
        signature: {
            signature_id: signature?.signature_id ?? null,
            ship_size: connection?.ship_size ?? null,
            mass_status: connection?.mass_status ?? null,
            lifetime: connection?.lifetime_status ?? 'healthy',
            wormhole: signature?.wormhole ?? null,
            signature_type: signature ? { target_class: signature.target_class ?? null } : null,
            is_static: Boolean(signature?.is_static),
            is_wandering: Boolean(signature?.is_wandering),
        },
        currentSystem: { alias: system.alias, class: system.solarsystem.class, combatHome: Boolean(system.combat_home), combatColor: system.combat_color ?? null },
        connectionTarget: { ...target, alias, combat_color: null, combat_home: false },
        aliases: chainAliases([...store.systems.values()], system),
        formats: meta,
        detectReturn: true,
    });
}

/** Patch 14: the way back ("*") from `system` to `parent`, with this side's return signature if pasted. */
export function wayBackBookmark(store: MapStore, system: TMapSolarsystem, parent: TMapSolarsystem): string {
    const meta = store.meta.value;
    if (!meta) return '';
    const connection = [...store.connections.values()].find(
        (candidate) =>
            (candidate.from_map_solarsystem_id === system.id && candidate.to_map_solarsystem_id === parent.id) ||
            (candidate.to_map_solarsystem_id === system.id && candidate.from_map_solarsystem_id === parent.id),
    );
    const signature = (connection?.signatures ?? []).find((candidate) => candidate.map_solarsystem_id === system.id) ?? null;
    return formatBookmarkName(
        { alias: parent.alias, occupier_alias: parent.occupier_alias, solarsystem: parent.solarsystem, combat_home: parent.combat_home, combat_color: parent.combat_color },
        {
            signatureId: signature?.signature_id ?? null,
            shipSize: connection?.ship_size ?? null,
            massStatus: connection?.mass_status ?? null,
            lifetime: connection?.lifetime_status ?? 'healthy',
        },
        meta,
        system.alias,
        system.alias,
        system.solarsystem.class,
        system.combat_color ?? null,
        Boolean(system.combat_home),
    );
}
