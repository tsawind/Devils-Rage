import { isWormholeClass } from '@/const/solarsystemClasses';
import { displayAlias, planSignatureAliases, type TAliasScheme } from '@/lib/alias';
import { chainAliases } from '@/lib/combat';

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
    target_class: string | null;
    wormhole: string | null;
};

type TPlaceholderSystem = {
    id: number;
    alias?: string | null;
    combat_color?: string | null;
    combat_home?: boolean | null;
    solarsystem?: { class?: string | null } | null;
    pending_holes?: TPlaceholderHole[] | null;
};

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
};

const KSPACE: Record<string, string> = { h: 'HS', l: 'LS', n: 'NS', p: 'Pochven' };

/** Where a hole leads: "C3", "HS", or "?" when the type isn't known. */
export function holeDestination(targetClass: string | null | undefined): string {
    if (!targetClass || targetClass === 'unknown') return '?';
    if (isWormholeClass(targetClass)) return `C${targetClass}`;
    return KSPACE[targetClass] ?? targetClass.toUpperCase();
}

export function buildPlaceholders(
    systems: readonly TPlaceholderSystem[],
    formats: { bookmark_alias_scheme?: TAliasScheme; bookmark_ignored_alias?: string },
): TPlaceholder[] {
    const result: TPlaceholder[] = [];
    for (const system of systems) {
        const holes = system.pending_holes ?? [];
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
            });
        }
    }
    return result;
}
