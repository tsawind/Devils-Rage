import { aliasForSlot, displayAlias } from '@/lib/alias';
import { isFrigateHole } from '@/lib/massEstimate';
import { wormholeMass } from '@/lib/wormholeMass';

/**
 * Arming (patch 13): a scanner arms the hole they are about to jump. It takes
 * its number (shared: nobody else gets it), the mapper copies its bookmark
 * name, and the scanner's next jump is linked to it with no prompt. The
 * server keeps who armed what; these are the pure decisions around it.
 */

/** "Sitting on it": a full paste arms the one wormhole closer than this. */
export const ARM_GRID_METERS = 10_000;

export type TArmable = {
    id: number;
    signature_id?: string | null;
    alias?: string | null;
    map_connection_id?: number | null;
    armed_by_user_id?: number | null;
    armed_by_name?: string | null;
};

export type TGridCandidate = { id: number; meters: number | null; isWormhole: boolean; linked: boolean };

/** Which hole a full paste arms: the one unlinked wormhole you sit on, if there is exactly one. */
export function pickGridHole(candidates: readonly TGridCandidate[]): { mode: 'one'; id: number } | { mode: 'many'; count: number } | { mode: 'none' } {
    const close = candidates.filter((candidate) => candidate.isWormhole && !candidate.linked && candidate.meters !== null && candidate.meters < ARM_GRID_METERS);
    if (close.length === 1) return { mode: 'one', id: close[0].id };
    if (close.length > 1) return { mode: 'many', count: close.length };
    return { mode: 'none' };
}

export type TArmAsOption = {
    alias: string;
    /** free: just taken; swap: someone else armed it (you swap numbers); mine: your hole has it; taken: can't be picked. */
    state: 'free' | 'swap' | 'mine' | 'taken';
    /** Who holds it ("Kyle", "A12 on the map", "QXP"). */
    holder: string | null;
};

/**
 * The numbers "Arm as…" offers in a system: slots 1-9. A number used by a
 * system on the map (jumped) or locked on an unarmed signature can't be
 * picked; one armed by someone else is swapped.
 */
export function armAsOptions(params: {
    parentAlias: string | null | undefined;
    ignoredAlias?: string | null;
    combatHome?: boolean;
    /** Numbers used by systems on the map in this chain. */
    usedBySystems: readonly string[];
    /** The system's signatures (with their numbers and arms). */
    signatures: readonly TArmable[];
    /** The hole being armed. */
    signatureId: number;
    userId: number | null;
}): TArmAsOption[] {
    const used = new Set(params.usedBySystems.map((alias) => alias.toUpperCase()));
    const options: TArmAsOption[] = [];
    for (const slot of '123456789') {
        const alias = aliasForSlot(params.parentAlias, slot, params.ignoredAlias, params.combatHome ?? false);
        if (!alias) continue;
        const key = alias.toUpperCase();
        if (used.has(key)) {
            options.push({ alias, state: 'taken', holder: `${displayAlias(alias)} on the map` });
            continue;
        }
        const holder = params.signatures.find((signature) => (signature.alias ?? '').toUpperCase() === key);
        if (!holder) options.push({ alias, state: 'free', holder: null });
        else if (holder.id === params.signatureId) options.push({ alias, state: 'mine', holder: null });
        else if (!holder.map_connection_id && holder.armed_by_user_id && holder.armed_by_user_id !== params.userId) {
            options.push({ alias, state: 'swap', holder: holder.armed_by_name ?? 'someone' });
        } else options.push({ alias, state: 'taken', holder: holder.signature_id?.slice(0, 3) ?? 'another signature' });
    }
    return options;
}

/** "1 LIH (you) · 2 QXP (Kyle)": the armed holes of a system, by number. */
export function armedSummary(signatures: readonly TArmable[], userId: number | null): string {
    return signatures
        .filter((signature) => signature.armed_by_user_id && !signature.map_connection_id)
        .toSorted((a, b) => (a.alias ?? '').localeCompare(b.alias ?? '', undefined, { numeric: true }))
        .map((signature) => {
            const who = signature.armed_by_user_id === userId ? 'you' : (signature.armed_by_name ?? 'someone');
            return `${displayAlias(signature.alias ?? '') || '·'} ${signature.signature_id?.slice(0, 3) ?? '???'} (${who})`;
        })
        .join(' · ');
}

/** Your armed hole among a system's signatures (unjumped). */
export function myArmedHole<T extends TArmable>(signatures: readonly T[] | null | undefined, userId: number | null): T | null {
    if (userId === null) return null;
    return signatures?.find((signature) => signature.armed_by_user_id === userId && !signature.map_connection_id) ?? null;
}

/**
 * Did you jump the hole you armed? No when the hole's known destination class
 * differs from where you landed, or you landed in a system already linked to
 * the origin by another hole.
 */
export function jumpMatchesArm(params: {
    armedTargetClass: string | null | undefined;
    destinationClass: string | null | undefined;
    connectedByOtherHole: boolean;
}): boolean {
    if (params.connectedByOtherHole) return false;
    const target = params.armedTargetClass;
    if (!target || target === 'unknown' || !params.destinationClass) return true;
    return target === params.destinationClass;
}

// ---- Quick keys in the Type lists -------------------------------------------

const QUICK_KEYS: Record<string, string> = { '1': 'C1', '2': 'C2', '3': 'C3', '4': 'C4', '5': 'C5', '6': 'C6', h: 'Highsec', l: 'Lowsec', n: 'Nullsec', f: 'Frigate holes' };

/** The filter a key stands for in the Type lists ("C5", "Highsec", "Frigate holes"), or null. */
export function quickKeyLabel(key: string): string | null {
    return QUICK_KEYS[key.toLowerCase()] ?? null;
}

/**
 * Does a wormhole type pass a quick key? 1-6 = leads to that class, h / l / n
 * = high, low, nullsec, f = only frigates fit through.
 */
export function matchesQuickKey(key: string | null, type: { signature?: string | null; target_class?: string | null }): boolean {
    if (!key) return true;
    const normalized = key.toLowerCase();
    if (normalized === 'f') {
        const mass = wormholeMass(type.signature ?? null);
        return Boolean(mass && isFrigateHole(mass.maxJump));
    }
    if (!(normalized in QUICK_KEYS)) return true;
    return (type.target_class ?? '').toLowerCase() === normalized;
}

/** A key press in a Type list: toggles the filter (same key again, or Backspace, clears it). */
export function nextQuickKey(current: string | null, pressed: string): string | null | undefined {
    if (pressed === 'Backspace') return current ? null : undefined;
    const key = pressed.toLowerCase();
    if (!(key in QUICK_KEYS)) return undefined;
    return current === key ? null : key;
}
