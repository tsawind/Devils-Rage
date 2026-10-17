import { classMeta } from '@/const/solarsystemClasses';
import type { TStringedSolarsystemClass } from '@/types/models';

/**
 * Patch 39: special systems are named in words, not a raw class number, wherever a
 * class is shown (cards, bookmarks, the name column, the jump prompt):
 * Thera (C12), "C4shattered" (a shattered C1–C6), "C13 frig" (the frigate-only shattered
 * class), the drifter system's own name (C14–C18: Sentinel, Barbican, Vidette, Conflux,
 * Redoubt) and Pochven. Everything else keeps its normal label (null here).
 */
export function specialClassWord(solarsystemClass: string | null | undefined, options: { isShattered?: boolean | null } = {}): string | null {
    const value = String(solarsystemClass ?? '').toLowerCase();
    if (value === '12') return 'Thera';
    if (value === '13') return 'C13 frig';
    if (value === 'p') return 'Pochven';
    if (['14', '15', '16', '17', '18'].includes(value)) {
        return /\(([^)]+)\)/.exec(classMeta(value as TStringedSolarsystemClass).label)?.[1] ?? `C${value}`;
    }
    if (options.isShattered && /^[1-6]$/.test(value)) return `C${value}shattered`;
    return null;
}

/** Patch 39: Thera has a name of its own on the map (no callsign, no number): "Thera", its holes "Thera1", "Thera2"… */
export const THERA_NAME = 'Thera';

/** Whether a class is Thera's. */
export function isTheraClass(solarsystemClass: string | null | undefined): boolean {
    return String(solarsystemClass ?? '') === '12';
}

/** Whether a name is Thera's own, or one of its holes' ("Thera1", "Thera21", "Thera~1917"). */
export function isTheraName(alias: string | null | undefined): boolean {
    return /^[Tt]hera[0-9A-Z]*$/.test((alias ?? '').trim().split(/[~@]/)[0]);
}

/** Thera's names keep their spelling ("Thera12"), while chain numbers are worked out upper-cased. */
export function theraCase(alias: string): string {
    return alias.replace(/^THERA(?=[0-9A-Z]*(?:[~@]|$))/, THERA_NAME);
}
