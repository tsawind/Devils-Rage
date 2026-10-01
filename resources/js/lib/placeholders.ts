import { isWormholeClass } from '@/const/solarsystemClasses';
import { displayAlias, planSignatureAliases, staticSlotAlias, suggestAlias, type TAliasScheme } from '@/lib/alias';
import { chainAliases } from '@/lib/combat';
import { classCode, wayBackCouldBe } from '@/lib/staticCertainty';
import type { TStringedSolarsystemClass } from '@/types/models';

/**
 * Placeholder systems (patch 12): every wormhole signature nobody has jumped
 * yet is drawn as a dashed system where the real one will appear.
 */

/** Placeholder node ids are negative (the signature id), so they never clash with map systems. */
export function placeholderNodeId(signatureId: number): number {
    return -signatureId;
}

type TPlaceholderHole = {
    id: number;
    signature_id: string | null;
    alias: string | null;
    is_static: boolean;
    target_class: TStringedSolarsystemClass | null;
    wormhole: string | null;
    mass_status?: string | null;
    lifetime?: string | null;
    armed_by_user_id?: number | null;
    armed_by_name?: string | null;
};

type TPlaceholderSystem = {
    id: number;
    alias?: string | null;
    combat_color?: string | null;
    combat_home?: boolean | null;
    solarsystem?: { class?: TStringedSolarsystemClass | null; statics?: { name: string; leads_to: string }[] | null } | null;
    pending_holes?: TPlaceholderHole[] | null;
};

/** A connection as the expected-statics check needs it (patch 13). */
type TPlaceholderConnection = {
    from_map_solarsystem_id: number;
    to_map_solarsystem_id: number;
    type?: string | null;
    signatures?:
        | { id: number; map_solarsystem_id: number; signature_id?: string | null; is_static?: boolean | null; wormhole?: { name: string } | null }[]
        | null;
};

/** Ids for expected statics (no signature yet): far below any signature's placeholder id. */
const EXPECTED_BASE = 2_000_000_000;

export type TPlaceholder = {
    /** Negative node id (see placeholderNodeId). */
    nodeId: number;
    signatureId: number;
    parentId: number;
    /** The chain it would join (its parent's), for the lane it sits in. */
    color: string | null;
    /** The number the hole has, or will get (main chain); null while in combat limbo. */
    alias: string | null;
    /** Shown on top: "A11", "Bravo", "—" (main chain, no number yet), "" (combat limbo). */
    label: string;
    /** Shown below: "JOW · C4", "MVD · HS", "QXP · ?". */
    detail: string;
    isStatic: boolean;
    /** The hole's type ("D845"), null while unknown or a K162. */
    wormhole: string | null;
    massStatus: string | null;
    lifetime: string | null;
    /** Patch 13: a static the system must have that nobody has scanned yet (no signature: signatureId 0). */
    expected?: boolean;
    /** Patch 13: "maybe *return?" when the way back could be this static. */
    note?: string | null;
    /** Patch 13: armed as someone's next jump (red dashed outline). */
    armedBy?: number | null;
    armedByName?: string | null;
};

const KSPACE: Record<string, string> = { h: 'HS', l: 'LS', n: 'NS', p: 'Pochven' };

/** Where a hole leads: "C3", "HS", or "?" when the type isn't known. */
export function holeDestination(targetClass: TStringedSolarsystemClass | null | undefined): string {
    if (!targetClass || targetClass === 'unknown') return '?';
    if (isWormholeClass(targetClass)) return `C${targetClass}`;
    return KSPACE[targetClass] ?? targetClass.toUpperCase();
}

/**
 * `linkedSignatureIds`: signatures already linked to a connection (as the
 * connections know them). A hole jumped a moment ago can still be in its
 * system's list until that system's signatures are re-sent; it is skipped.
 */
export function buildPlaceholders(
    systems: readonly TPlaceholderSystem[],
    formats: { bookmark_alias_scheme?: TAliasScheme; bookmark_ignored_alias?: string },
    linkedSignatureIds: ReadonlySet<number> = new Set(),
    /** Patch 13: connections and the way each system was found, for its unscanned statics. */
    context?: { connections: readonly TPlaceholderConnection[]; parentOf: ReadonlyMap<number, number>; homeId: number | null },
): TPlaceholder[] {
    const result: TPlaceholder[] = [];
    for (const system of systems) {
        const holes = (system.pending_holes ?? []).filter((hole) => !linkedSignatureIds.has(hole.id));
        if (holes.length === 0) continue;

        const limbo = Boolean(system.combat_color);
        const planned = planSignatureAliases({
            parentAlias: system.alias,
            originIsWormhole: isWormholeClass(system.solarsystem?.class ?? null),
            aliases: chainAliases(systems, system),
            scheme: formats.bookmark_alias_scheme,
            ignoredAlias: formats.bookmark_ignored_alias,
            combatHome: Boolean(system.combat_home),
            limbo,
            signatures: holes.map((hole) => ({
                id: hole.id,
                isWormhole: true,
                isConnected: false,
                lockedAlias: hole.alias,
                isStatic: hole.is_static,
                targetIsWormhole: !hole.target_class || hole.target_class === 'unknown' || isWormholeClass(hole.target_class),
                targetClass: hole.target_class,
            })),
        });

        for (const hole of holes.toSorted((a, b) => a.id - b.id)) {
            const alias = hole.alias ?? planned.get(hole.id) ?? null;
            const destination = holeDestination(hole.target_class);
            result.push({
                nodeId: placeholderNodeId(hole.id),
                signatureId: hole.id,
                parentId: system.id,
                color: system.combat_color ?? null,
                alias,
                label: alias ? displayAlias(alias, formats.bookmark_alias_scheme) : limbo ? '' : '—',
                detail: `${(hole.signature_id ?? '???').slice(0, 3)} · ${destination}${hole.is_static ? 's' : ''}`,
                isStatic: hole.is_static,
                wormhole: hole.wormhole,
                massStatus: hole.mass_status ?? null,
                lifetime: hole.lifetime ?? null,
                armedBy: hole.armed_by_user_id ?? null,
                armedByName: hole.armed_by_name ?? null,
            });
        }
    }
    if (context) result.push(...expectedStatics(systems, formats, context, result));
    return result;
}

/**
 * Patch 13: every wormhole system in a chain shows the statics it must have
 * that nobody has scanned yet, as dashed systems: the first takes the static's
 * number (A0, Alpha off Daisy), the others the next free numbers; combat
 * chains leave them unnumbered. When the way back could be one of them it
 * gets the note "maybe *return?".
 */
function expectedStatics(
    systems: readonly TPlaceholderSystem[],
    formats: { bookmark_alias_scheme?: TAliasScheme; bookmark_ignored_alias?: string },
    context: { connections: readonly TPlaceholderConnection[]; parentOf: ReadonlyMap<number, number>; homeId: number | null },
    holes: readonly TPlaceholder[],
): TPlaceholder[] {
    const byId = new Map(systems.map((system) => [system.id, system]));
    const result: TPlaceholder[] = [];

    for (const system of systems) {
        const statics = system.solarsystem?.statics ?? [];
        if (statics.length === 0 || !isWormholeClass(system.solarsystem?.class ?? null)) continue;

        const touching = context.connections.filter(
            (connection) => connection.from_map_solarsystem_id === system.id || connection.to_map_solarsystem_id === system.id,
        );
        // Only systems that are part of a chain (linked to something, or home).
        if (touching.length === 0 && system.id !== context.homeId) continue;

        // Patch 14: nothing is assumed. A static is only accounted for by a hole MARKED static
        // (by hand, or by the certain-static check); a hole of the static's type is just a candidate.
        // A hole already named for the static's slot (A0, Alpha off Daisy) is the static by our naming.
        const slot = staticSlotAlias(system.alias, formats.bookmark_ignored_alias, Boolean(system.combat_home)).toUpperCase();
        const marked: (string | null)[] = [];
        const candidates: { name: string; sig: string }[] = [];
        const consider = (hole: { is_static?: boolean | null; wormhole?: string | null; signature_id?: string | null; alias?: string | null }): void => {
            const name = (hole.wormhole ?? '').toUpperCase() || null;
            if (hole.is_static || (hole.alias ?? '').toUpperCase() === slot) marked.push(name);
            else if (name) candidates.push({ name, sig: (hole.signature_id ?? '???').slice(0, 3) });
        };
        for (const hole of system.pending_holes ?? []) consider(hole);
        let wayBack: { thisSideType: string | null; farSideType: string | null; leadsTo: string | null; signatureId: number | null } | null = null;
        const parentId = context.parentOf.get(system.id) ?? null;
        for (const connection of touching) {
            const thisSide = (connection.signatures ?? []).find((signature) => signature.map_solarsystem_id === system.id) ?? null;
            const farSide = (connection.signatures ?? []).find((signature) => signature.map_solarsystem_id !== system.id) ?? null;
            const otherId = connection.from_map_solarsystem_id === system.id ? connection.to_map_solarsystem_id : connection.from_map_solarsystem_id;
            // The hole leads on to a system named for the static's slot: that's the static.
            const leadsToSlot = otherId !== parentId && (byId.get(otherId)?.alias ?? '').toUpperCase() === slot;
            if (thisSide || leadsToSlot) {
                consider({
                    is_static: thisSide?.is_static || leadsToSlot,
                    wormhole: thisSide?.wormhole?.name ?? null,
                    signature_id: thisSide?.signature_id ?? null,
                });
            }
            if (otherId === parentId && connection.type !== 'stargate') {
                wayBack = {
                    signatureId: thisSide?.id ?? null,
                    thisSideType: thisSide?.wormhole?.name ?? null,
                    farSideType: farSide?.wormhole?.name ?? null,
                    leadsTo: classCode(byId.get(otherId)?.solarsystem?.class ?? null),
                };
            }
        }

        const staticList = statics.map((candidate) => ({ name: candidate.name, leadsTo: candidate.leads_to }));
        const maybeBack = wayBackCouldBe(staticList, wayBack);

        // Marked holes account for their own type first; a marked hole with no type accounts for any one static.
        const untypedMarked = marked.filter((name) => name === null).length;
        const typedMarked = marked.filter((name): name is string => name !== null);
        const missing = statics.filter((candidate) => {
            const index = typedMarked.indexOf(candidate.name.toUpperCase());
            if (index === -1) return true;
            typedMarked.splice(index, 1);
            return false;
        });
        const unscanned = missing.slice(Math.min(untypedMarked, missing.length));
        if (unscanned.length === 0) continue;

        /** "maybe SUE / *return?": the holes that could be this static (none is assumed). */
        const noteFor = (name: string): string | null => {
            const upper = name.toUpperCase();
            const parts = candidates.filter((candidate) => candidate.name === upper).map((candidate) => candidate.sig);
            if (maybeBack.some((back) => back.toUpperCase() === upper)) parts.push('*return');
            return parts.length ? `maybe ${parts.join(' / ')}?` : null;
        };

        const limbo = Boolean(system.combat_color);
        const taken = [
            ...chainAliases(systems, system),
            ...holes.filter((hole) => hole.parentId === system.id && hole.alias).map((hole) => hole.alias as string),
        ];
        const staticSlot = staticSlotAlias(system.alias, formats.bookmark_ignored_alias, Boolean(system.combat_home));
        unscanned.forEach((candidate, index) => {
            let alias: string | null = null;
            if (!limbo) {
                alias =
                    index === 0 && !taken.map((value) => value.toUpperCase()).includes(staticSlot.toUpperCase())
                        ? staticSlot
                        : suggestAlias({
                              parentAlias: system.alias,
                              targetIsWormhole: true,
                              originIsWormhole: true,
                              aliases: taken,
                              scheme: formats.bookmark_alias_scheme,
                              ignoredAlias: formats.bookmark_ignored_alias,
                              combatHome: Boolean(system.combat_home),
                          });
                if (alias) taken.push(alias);
            }
            const leadsTo = candidate.leads_to.toUpperCase();
            result.push({
                nodeId: -(EXPECTED_BASE + system.id * 4 + index),
                signatureId: 0,
                parentId: system.id,
                color: system.combat_color ?? null,
                alias,
                label: alias ? displayAlias(alias, formats.bookmark_alias_scheme) : limbo ? `static ${leadsTo}` : '—',
                detail: `${candidate.name} → ${leadsTo} static · ${noteFor(candidate.name) ? 'not identified' : 'not scanned'}`,
                isStatic: true,
                wormhole: candidate.name,
                massStatus: null,
                lifetime: null,
                expected: true,
                note: noteFor(candidate.name),
            });
        });
    }
    return result;
}
